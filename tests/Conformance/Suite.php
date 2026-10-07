<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Conformance;

use Bynn\DecisionNode\Answer;
use Bynn\DecisionNode\ChoiceAnswer;
use Bynn\DecisionNode\Decision;
use Bynn\DecisionNode\DecisionNode;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\RateLimitError;
use Bynn\DecisionNode\Exception\OverloadedError;
use Bynn\DecisionNode\Exception\RefusalError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\Image;
use Bynn\DecisionNode\ModelInfo;
use Bynn\DecisionNode\Safety;
use Bynn\DecisionNode\ScoreAnswer;
use Bynn\DecisionNode\TruthAnswer;
use Bynn\DecisionNode\UnknownAnswer;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

use function Bynn\DecisionNode\choice;
use function Bynn\DecisionNode\score;
use function Bynn\DecisionNode\truth;

/**
 * Runs the language-neutral conformance cases against this SDK.
 *
 * Case files are decoded with JSON objects kept as \stdClass, so an empty
 * object, a numeric-looking key and key order all survive the trip to the
 * client and back.
 */
final class Suite
{
    private const ENV_VARS = ['DECISIONNODE_API_KEY', 'DECISIONNODE_BASE_URL', 'DECISIONNODE_MODEL'];

    /** Keys whose values compare whole rather than by the keys the case lists. */
    private const EXACT = ['probabilities', 'legend', 'raw', 'entries', 'safety', 'body', 'limits'];

    private const ARGUMENT_NAMES = ['true' => 'ifTrue', 'false' => 'ifFalse'];

    private const METHOD_NAMES = ['decide_many' => 'decideMany', 'decide_raw' => 'decideRaw'];

    /** @var resource|null */
    private static $process = null;

    private static ?string $mockUrl = null;

    public static function configured(): bool
    {
        $dir = getenv('DECISIONNODE_CONFORMANCE_DIR');

        return is_string($dir) && $dir !== '' && is_dir($dir . '/cases');
    }

    public static function dir(): string
    {
        if (!self::configured()) {
            throw new \RuntimeException('Set DECISIONNODE_CONFORMANCE_DIR to a checkout of the conformance suite.');
        }

        return rtrim((string) getenv('DECISIONNODE_CONFORMANCE_DIR'), '/');
    }

    /**
     * One row per case file; with `$both`, one for the blocking call and one
     * for its Async form. Without the suite, a single row whose test fails.
     *
     * @return array<string, array{string, bool}>
     */
    public static function files(string $kind, bool $both): array
    {
        if (!self::configured()) {
            return [$kind . ' (DECISIONNODE_CONFORMANCE_DIR is not set)' => ['', false]];
        }
        $out = [];
        $files = glob(self::dir() . '/cases/' . $kind . '/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = $kind . '/' . basename($file, '.json');
            $out[$name] = [$file, false];
            if ($both) {
                $out[$name . ' (async)'] = [$file, true];
            }
        }

        return $out;
    }

    public static function load(string $file): \stdClass
    {
        $case = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
        if (!$case instanceof \stdClass) {
            throw new \RuntimeException($file . ' is not a JSON object');
        }
        if (($case->id ?? null) !== basename($file, '.json')) {
            throw new Fail('the case id does not match its file name');
        }

        return $case;
    }

    // ------------------------------------------------------------ the mock

    public static function startMock(): string
    {
        if (self::$mockUrl !== null) {
            return self::$mockUrl;
        }
        $external = getenv('DECISIONNODE_MOCK_URL');
        if (is_string($external) && $external !== '') {
            return self::$mockUrl = rtrim($external, '/');
        }
        $python = getenv('PYTHON') ?: 'python3';
        $pipes = [];
        $process = proc_open(
            [$python, self::dir() . '/mock/server.py', '--port', '0'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('the mock server did not start');
        }
        $line = trim((string) fgets($pipes[1]));
        if (!str_starts_with($line, 'listening on ')) {
            proc_terminate($process);
            throw new \RuntimeException('the mock server did not start: ' . $line);
        }
        stream_set_blocking($pipes[1], false);
        self::$process = $process;
        register_shutdown_function([self::class, 'stopMock']);

        return self::$mockUrl = substr($line, strlen('listening on '));
    }

    public static function stopMock(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
    }

    /**
     * @return \stdClass {requests: list<\stdClass>, max_in_flight: int}
     */
    private static function mockLog(string $session, ?string $path = null): \stdClass
    {
        $ctx = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true]]);
        $query = 'session=' . rawurlencode($session) . ($path === null ? '' : '&path=' . rawurlencode($path));
        $text = file_get_contents(self::startMock() . '/_mock/requests?' . $query, false, $ctx);

        return json_decode((string) $text, false, 512, JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------ the five kinds

    public static function runRequest(\stdClass $case, bool $async = false): void
    {
        $session = self::session();
        $client = self::client($case, ['x-mock-session' => $session]);
        self::invoke($client, $case->call, $async);
        $log = self::mockLog($session)->requests;
        if (count($log) !== 1) {
            throw new Fail(sprintf('expected 1 request, the mock received %d', count($log)));
        }
        $got = $log[0];
        $exp = $case->expect->request;
        if ($got->method !== $exp->method) {
            throw new Fail(sprintf('method: expected %s, got %s', $exp->method, $got->method));
        }
        if ($got->path !== $exp->path) {
            throw new Fail(sprintf('path: expected %s, got %s', $exp->path, $got->path));
        }
        $headers = array_change_key_case(get_object_vars($got->headers), CASE_LOWER);
        foreach (get_object_vars($exp->headers ?? new \stdClass()) as $name => $value) {
            if (($headers[strtolower((string) $name)] ?? null) !== $value) {
                throw new Fail(sprintf('header %s: expected %s, got %s', $name, json_encode($value), json_encode($headers[strtolower((string) $name)] ?? null)));
            }
        }
        foreach (get_object_vars($exp->header_patterns ?? new \stdClass()) as $name => $pattern) {
            $value = $headers[strtolower((string) $name)] ?? null;
            if ($value === null || preg_match('~' . str_replace('~', '\~', $pattern) . '~', $value) !== 1) {
                throw new Fail(sprintf('header %s: %s does not match %s', $name, json_encode($value), $pattern));
            }
        }
        foreach ($exp->absent_headers ?? [] as $name) {
            if (array_key_exists(strtolower($name), $headers)) {
                throw new Fail(sprintf('header %s must not be sent', $name));
            }
        }

        $text = (string) ($got->body_text ?? '');
        $body = $text === '' ? null : json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        if (property_exists($exp, 'body')) {
            if (!Compare::same($exp->body, $body)) {
                throw new Fail('body: expected ' . Compare::show($exp->body) . ', got ' . $text);
            }
        } elseif (!property_exists($exp, 'body_checks') && $text !== '') {
            throw new Fail('expected no body, got ' . $text);
        }
        foreach ($exp->body_checks ?? [] as $check) {
            [$found, $value] = Compare::pointer($body, $check->pointer);
            if (!$found) {
                throw new Fail('body' . $check->pointer . ': missing');
            }
            if (property_exists($check, 'equals') && !Compare::same($check->equals, $value)) {
                throw new Fail('body' . $check->pointer . ': expected ' . Compare::show($check->equals) . ', got ' . Compare::show($value));
            }
            if (property_exists($check, 'type') && !Compare::isType($value, $check->type)) {
                throw new Fail('body' . $check->pointer . ': expected a ' . $check->type);
            }
            if (property_exists($check, 'keys')) {
                $keys = $value instanceof \stdClass ? array_map('strval', array_keys(get_object_vars($value))) : null;
                if ($keys !== $check->keys) {
                    throw new Fail('body' . $check->pointer . ': keys expected ' . Compare::show($check->keys) . ', got ' . Compare::show($keys));
                }
            }
        }
        foreach ($exp->ordered_keys ?? [] as $pointer) {
            [, $want] = Compare::pointer($exp->body, $pointer);
            [, $have] = Compare::pointer($body, $pointer);
            $wantKeys = array_map('strval', array_keys(get_object_vars($want)));
            $haveKeys = $have instanceof \stdClass ? array_map('strval', array_keys(get_object_vars($have))) : [];
            if ($wantKeys !== $haveKeys) {
                throw new Fail('body' . $pointer . ': key order expected ' . Compare::show($wantKeys) . ', got ' . Compare::show($haveKeys));
            }
        }
    }

    public static function runResponse(\stdClass $case, bool $async = false): void
    {
        $client = self::client(
            (object) ['client' => (object) ['api_key' => 'dn_test_conformance']],
            ['x-mock-session' => self::session(), 'x-mock-scenario' => 'response/' . $case->id],
        );
        $result = self::invoke($client, $case->call, $async);
        $exp = $case->expect;
        if (property_exists($exp, 'decision')) {
            Compare::match($exp->decision, self::decision($result), 'decision');
        } elseif (property_exists($exp, 'answer')) {
            Compare::match($exp->answer, self::answer($result), 'answer');
        } elseif (property_exists($exp, 'models')) {
            $got = array_map(static fn (ModelInfo $m): array => [
                'name' => $m->name,
                'description' => $m->description,
                'release_date' => $m->releaseDate,
                'limits' => $m->limits,
            ], $result);
            if (!Compare::same($exp->models, $got)) {
                throw new Fail('models: expected ' . Compare::show($exp->models) . ', got ' . Compare::show($got));
            }
        } elseif (property_exists($exp, 'raw')) {
            if (!Compare::same($exp->raw, $result)) {
                throw new Fail('raw: expected ' . Compare::show($exp->raw) . ', got ' . Compare::show($result));
            }
        }
    }

    public static function runError(\stdClass $case, bool $async = false): void
    {
        $session = self::session();
        $client = self::client($case, ['x-mock-session' => $session, 'x-mock-scenario' => 'error/' . $case->id]);
        $catchAs = $case->expect->error->catch_as ?? null;
        if ($catchAs !== null && $catchAs !== 'DecisionNodeError') {
            throw new Fail('catch_as names ' . $catchAs . ', which this harness does not know');
        }
        // Every error is caught by a clause written against the exported base
        // name DecisionNodeError, which is what catch_as asks for.
        try {
            self::invoke($client, $case->call, $async);
        } catch (DecisionNodeError $error) {
            self::checkError($case->expect->error, $error, 'error');
            $attempts = count(self::mockLog($session)->requests);
            if ($attempts !== 1) {
                throw new Fail(sprintf('expected 1 attempt with max_retries 0, got %d', $attempts));
            }

            return;
        }
        throw new Fail('no error was raised');
    }

    public static function runBehaviour(\stdClass $case, bool $async = false): void
    {
        $session = self::session();
        $extra = ['x-mock-session' => $session];
        if (isset($case->mock)) {
            $extra['x-mock-scenario'] = 'behaviour/' . $case->id;
        }
        $exp = $case->expect;
        // For scheduled_waits_ms the wait between attempts is replaced by a
        // recorder: the delay is noted and the retry is sent at once.
        $waits = isset($exp->scheduled_waits_ms) ? [] : null;
        $started = hrtime(true);
        try {
            // An argument error may come from the constructor (a bad base URL,
            // timeout or maxRetries) or from the call; both count.
            $client = self::client($case, $extra, $waits);
            $result = self::invoke($client, $case->call, $async);
        } catch (DecisionNodeError|\InvalidArgumentException $error) {
            $result = $error;
        }
        $elapsed = (hrtime(true) - $started) / 1e6;
        usleep(50_000); // let the mock finish recording dropped connections
        $info = self::mockLog($session);
        $log = $info->requests;
        if (isset($exp->one_of)) {
            // The rule allows more than one way to comply; one must hold whole.
            $reasons = [];
            $held = false;
            foreach ($exp->one_of as $i => $alt) {
                try {
                    if (count($log) !== $alt->attempts) {
                        throw new Fail(sprintf('attempts: expected %d, the mock received %d', $alt->attempts, count($log)));
                    }
                    self::checkOutcome($alt->outcome, $result, 'outcome');
                    $held = true;
                    break;
                } catch (Fail $fail) {
                    $reasons[] = sprintf('one_of[%d]: %s', $i, $fail->getMessage());
                }
            }
            if (!$held) {
                throw new Fail(implode('; ', $reasons));
            }
        } elseif (count($log) !== $exp->attempts) {
            throw new Fail(sprintf('attempts: expected %d, the mock received %d', $exp->attempts, count($log)));
        }
        if (isset($exp->outcome)) {
            self::checkOutcome($exp->outcome, $result, 'outcome');
        }
        if (isset($exp->results)) {
            if (!is_array($result) || count($result) !== count($exp->results)) {
                throw new Fail(sprintf('results: expected %d results', count($exp->results)));
            }
            foreach ($exp->results as $i => $spec) {
                self::checkOutcome($spec, $result[$i], sprintf('results[%d]', $i));
            }
        }
        if (isset($exp->gaps_ms)) {
            $times = array_map(static fn (\stdClass $r): float => (float) $r->time, $log);
            foreach ($exp->gaps_ms as $i => $range) {
                $gap = ($times[$i + 1] - $times[$i]) * 1000;
                self::inRange($range, $gap, sprintf('gap %d (ms)', $i + 1));
            }
        }
        if (isset($exp->elapsed_ms)) {
            self::inRange($exp->elapsed_ms, $elapsed, 'elapsed (ms)');
        }
        if (isset($exp->max_in_flight)) {
            self::inRange($exp->max_in_flight, (float) $info->max_in_flight, 'max in flight');
        }
        if ($waits !== null) {
            if (count($waits) !== count($exp->scheduled_waits_ms)) {
                throw new Fail(sprintf('scheduled waits: expected %d, the client scheduled %d', count($exp->scheduled_waits_ms), count($waits)));
            }
            foreach ($exp->scheduled_waits_ms as $i => $range) {
                self::inRange($range, $waits[$i], sprintf('scheduled wait %d (ms)', $i + 1));
            }
        }
        foreach ($exp->unrequested_paths ?? [] as $path) {
            $reached = count(self::mockLog('*', $path)->requests);
            if ($reached !== 0) {
                throw new Fail(sprintf('%s was requested %d times', $path, $reached));
            }
        }
    }

    public static function runConstant(\stdClass $case): void
    {
        $name = (string) $case->constant;
        $classConstant = DecisionNode::class . '::' . $name;
        $namespaceConstant = 'Bynn\\DecisionNode\\' . $name;
        foreach ([$classConstant, $namespaceConstant] as $constant) {
            if (!defined($constant)) {
                throw new Fail($constant . ' is not defined');
            }
            if (!Compare::same($case->equals, constant($constant))) {
                throw new Fail(sprintf('%s: expected %s, got %s', $constant, Compare::show($case->equals), Compare::show(constant($constant))));
            }
        }
    }

    // ------------------------------------------------------------ building and calling

    /**
     * @param array<string, string> $extraHeaders
     * @param list<float>|null       $waits        when given, receives each wait (ms) instead of waiting
     */
    private static function client(\stdClass $case, array $extraHeaders, ?array &$waits = null): DecisionNode
    {
        $mock = self::startMock();
        foreach (self::ENV_VARS as $var) {
            putenv($var);
        }
        foreach (get_object_vars($case->env ?? new \stdClass()) as $var => $value) {
            putenv($var . '=' . str_replace('{mock_url}', $mock, (string) $value));
        }

        $spec = $case->client ?? new \stdClass();
        $args = [];
        if (isset($spec->api_key)) {
            $args['apiKey'] = $spec->api_key;
        }
        if (isset($spec->model)) {
            $args['model'] = $spec->model;
        }
        if (isset($spec->timeout)) {
            $args['timeout'] = (float) $spec->timeout;
        }
        if (isset($spec->max_retries)) {
            $args['maxRetries'] = (int) $spec->max_retries;
        }
        if (property_exists($spec, 'base_url')) {
            if ($spec->base_url !== null) {
                $port = (string) parse_url($mock, PHP_URL_PORT);
                $args['baseUrl'] = str_replace(['{mock_url}', '{mock_port}'], [$mock, $port], $spec->base_url);
            }
        } else {
            $args['baseUrl'] = $mock;
        }
        $headers = [];
        foreach (get_object_vars($spec->headers ?? new \stdClass()) as $name => $value) {
            $headers[(string) $name] = (string) $value;
        }
        $args['headers'] = array_merge($headers, $extraHeaders);
        if (($spec->http_client ?? null) === 'caller_following_redirects') {
            // A Guzzle client the caller made, set to follow redirects.
            $args['httpClient'] = new GuzzleClient(['allow_redirects' => ['max' => 5, 'strict' => true, 'protocols' => ['http', 'https']]]);
        }
        if ($waits !== null) {
            $stack = HandlerStack::create();
            $stack->unshift(static function (callable $next) use (&$waits): \Closure {
                return static function (RequestInterface $request, array $options) use ($next, &$waits) {
                    if (array_key_exists('delay', $options)) {
                        $waits[] = (float) $options['delay'];
                        unset($options['delay']);
                    }

                    return $next($request, $options);
                };
            }, 'record_waits');
            $args['httpClient'] = new GuzzleClient(['handler' => $stack]);
        }

        return new DecisionNode(...$args);
    }

    /**
     * Calls the method, or with `$async` its Async form and waits on the promise.
     */
    private static function invoke(DecisionNode $client, \stdClass $call, bool $async = false): mixed
    {
        $method = (self::METHOD_NAMES[$call->method] ?? $call->method) . ($async ? 'Async' : '');
        $args = [];
        foreach (get_object_vars($call->args) as $name => $value) {
            $name = (string) $name;
            $phpName = self::ARGUMENT_NAMES[$name] ?? lcfirst(str_replace('_', '', ucwords($name, '_')));
            $args[$phpName] = $call->method === 'decide_raw' ? $value : self::decode($value);
        }

        $result = $client->{$method}(...$args);
        if ($async) {
            if (!$result instanceof PromiseInterface) {
                throw new Fail($method . ' did not return a promise');
            }

            return $result->wait();
        }

        return $result;
    }

    /**
     * Turns `{"$truth": {...}}` and the other builder markers into builder calls.
     */
    private static function decode(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $vars = get_object_vars($value);
            if (count($vars) === 1) {
                $key = (string) array_key_first($vars);
                $a = $vars[$key];
                switch ($key) {
                    case '$truth':
                        return truth($a->instructions ?? null, $a->true ?? null, $a->false ?? null);
                    case '$choice':
                        return choice($a->instructions ?? null, $a->options);
                    case '$score':
                        return score($a->instructions ?? null, $a->levels);
                    case '$image':
                        if (isset($a->path)) {
                            return Image::fromPath($a->id, self::dir() . '/' . $a->path);
                        }
                        if (isset($a->url)) {
                            return Image::fromUrl($a->id, $a->url);
                        }

                        return Image::fromData($a->id, (string) base64_decode($a->data_base64, true), $a->media_type ?? null);
                }
            }
            $out = [];
            foreach ($vars as $k => $v) {
                $out[$k] = self::decode($v);
            }

            return (object) $out;
        }
        if (is_array($value)) {
            return array_map([self::class, 'decode'], $value);
        }

        return $value;
    }

    // ------------------------------------------------------------ normalising

    /**
     * @return array<string, mixed>
     */
    public static function answer(Answer $a): array
    {
        return match (true) {
            $a instanceof TruthAnswer => ['kind' => 'truth', 'truth' => $a->truth],
            $a instanceof ChoiceAnswer => ['kind' => 'choice', 'choice' => $a->choice, 'confidence' => $a->confidence, 'probabilities' => $a->probabilities],
            $a instanceof ScoreAnswer => ['kind' => 'score', 'score' => $a->score, 'confidence' => $a->confidence, 'probabilities' => $a->probabilities, 'legend' => $a->legend],
            $a instanceof UnknownAnswer => ['kind' => 'unknown', 'type' => $a->type, 'raw' => $a->raw],
            default => throw new Fail('an answer of class ' . get_class($a)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function decision(Decision $d): array
    {
        $answers = [];
        foreach ($d->answers as $id => $answer) {
            $answers[$id] = self::answer($answer);
        }

        return [
            'model' => $d->model,
            'request_id' => $d->requestId,
            'latency_ms' => $d->latencyMs,
            'usage' => ['input_tokens' => $d->usage->inputTokens, 'output_tokens' => $d->usage->outputTokens],
            'safety' => self::safety($d->safety),
            'answers' => $answers,
            'raw' => $d->raw,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function safety(?Safety $s): ?array
    {
        if ($s === null) {
            return null;
        }
        if (!$s->checked) {
            return ['checked' => false, 'reason' => $s->reason];
        }

        return ['checked' => true, 'flagged' => $s->flagged] + $s->scores + ['harm' => $s->harm, 'self_harm' => $s->selfHarm];
    }

    /**
     * @return array<string, mixed>
     */
    private static function error(DecisionNodeError $e): array
    {
        return [
            'status' => $e->status,
            'error_type' => $e->errorType,
            // The error's own message: an empty server message is "", never null.
            'message' => $e->getMessage(),
            'request_id' => $e->requestId,
            'reason' => $e->reason,
            'category' => $e instanceof RefusalError ? $e->category : null,
            'safety' => $e instanceof RefusalError ? self::safety($e->safety) : null,
            'entries' => $e instanceof ValidationError ? $e->entries : null,
            'retry_after' => $e instanceof RateLimitError || $e instanceof OverloadedError ? $e->retryAfter : null,
        ];
    }

    private static function checkError(\stdClass $spec, \Throwable $err, string $path): void
    {
        $class = $spec->error ?? $spec->class;
        $short = static fn (string $fqcn): string => substr((string) strrchr('\\' . $fqcn, '\\'), 1);
        $names = array_map($short, array_merge([get_class($err)], array_values(class_parents($err) ?: [])));
        if (!empty($spec->subclass_ok)) {
            if (!in_array($class, $names, true)) {
                throw new Fail(sprintf('%s: expected %s or a subclass, got %s', $path, $class, $names[0]));
            }
        } elseif ($names[0] !== $class) {
            throw new Fail(sprintf('%s: expected %s, got %s (%s)', $path, $class, $names[0], $err->getMessage()));
        }
        if (!$err instanceof DecisionNodeError) {
            throw new Fail($path . ': not a DecisionNodeError');
        }
        Compare::match($spec->fields ?? new \stdClass(), self::error($err), $path . '.fields');
    }

    private static function checkOutcome(\stdClass $spec, mixed $result, string $path): void
    {
        if (!empty($spec->argument_error)) {
            if (!$result instanceof \InvalidArgumentException) {
                throw new Fail(sprintf('%s: expected an InvalidArgumentException, got %s', $path, is_object($result) ? get_class($result) : gettype($result)));
            }

            return;
        }
        if (isset($spec->result)) {
            if ($result instanceof \Throwable) {
                throw new Fail(sprintf('%s: expected a decision, got %s: %s', $path, get_class($result), $result->getMessage()));
            }
            if (isset($spec->decision)) {
                Compare::match($spec->decision, self::decision($result), $path . '.decision');
            }

            return;
        }
        if (!$result instanceof DecisionNodeError) {
            throw new Fail(sprintf('%s: expected %s, got %s', $path, $spec->error, is_object($result) ? get_class($result) : gettype($result)));
        }
        self::checkError($spec, $result, $path);
    }

    private static function inRange(\stdClass $range, float $value, string $what): void
    {
        if (isset($range->min) && $value < $range->min) {
            throw new Fail(sprintf('%s %.0f is below %s', $what, $value, $range->min));
        }
        if (isset($range->max) && $value > $range->max) {
            throw new Fail(sprintf('%s %.0f is above %s', $what, $value, $range->max));
        }
    }

    private static function session(): string
    {
        return bin2hex(random_bytes(16));
    }
}
