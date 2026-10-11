<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Conformance;

use Bynn\DecisionNode\AgeReading;
use Bynn\DecisionNode\AiGeneratedReading;
use Bynn\DecisionNode\Answer;
use Bynn\DecisionNode\Batch;
use Bynn\DecisionNode\BatchPage;
use Bynn\DecisionNode\BatchResult;
use Bynn\DecisionNode\Booster;
use Bynn\DecisionNode\BoxAnswer;
use Bynn\DecisionNode\ChoiceAnswer;
use Bynn\DecisionNode\Decision;
use Bynn\DecisionNode\DecisionNode;
use Bynn\DecisionNode\Evidence;
use Bynn\DecisionNode\FaceDetectionReading;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\OverloadedError;
use Bynn\DecisionNode\Exception\RateLimitError;
use Bynn\DecisionNode\Exception\RefusalError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\FrameError;
use Bynn\DecisionNode\Image;
use Bynn\DecisionNode\ModelInfo;
use Bynn\DecisionNode\NumberAnswer;
use Bynn\DecisionNode\OcrReading;
use Bynn\DecisionNode\PointAnswer;
use Bynn\DecisionNode\Reading;
use Bynn\DecisionNode\Reply;
use Bynn\DecisionNode\Safety;
use Bynn\DecisionNode\ScoreAnswer;
use Bynn\DecisionNode\Session;
use Bynn\DecisionNode\SessionClosed;
use Bynn\DecisionNode\TruthAnswer;
use Bynn\DecisionNode\UnknownAnswer;
use Bynn\DecisionNode\Video;
use Bynn\DecisionNode\WantedPersonReading;
use Bynn\DecisionNode\VideoFrame;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

use function Bynn\DecisionNode\box;
use function Bynn\DecisionNode\choice;
use function Bynn\DecisionNode\number;
use function Bynn\DecisionNode\point;
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

    private const ARGUMENT_NAMES = ['true' => 'ifTrue', 'false' => 'ifFalse'];

    private const METHOD_NAMES = [
        'decide_many' => 'decideMany',
        'decide_raw' => 'decideRaw',
        'open_session' => 'openSession',
        'run_batch' => 'runBatch',
    ];

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
     * The mock's record of a session: requests (pongs left out), the pong
     * count and the peak number of requests in flight.
     *
     * @return \stdClass {requests: list<\stdClass>, pongs: int, max_in_flight: int}
     */
    private static function mockLog(string $session, ?string $path = null): \stdClass
    {
        $query = 'session=' . rawurlencode($session) . ($path === null ? '' : '&path=' . rawurlencode($path));
        $log = json_decode((string) self::control('GET', '/_mock/requests?' . $query), false, 512, JSON_THROW_ON_ERROR);
        $log->pongs = count(array_filter($log->requests, static fn (\stdClass $r): bool => $r->method === 'PONG'));
        $log->requests = array_values(array_filter($log->requests, static fn (\stdClass $r): bool => $r->method !== 'PONG'));

        return $log;
    }

    private static function control(string $method, string $path, ?string $body = null): string
    {
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'timeout' => 30,
            'ignore_errors' => true,
            'header' => $body === null ? '' : "Content-Type: application/json\r\n",
            'content' => $body ?? '',
        ]]);

        return (string) file_get_contents(self::startMock() . $path, false, $ctx);
    }

    // ------------------------------------------------------------ the five kinds

    public static function runRequest(\stdClass $case, bool $async = false): void
    {
        $session = self::session();
        $extra = ['x-mock-session' => $session];
        if (isset($case->mock)) {
            $extra['x-mock-scenario'] = 'request/' . $case->id;
        }
        $client = self::client($case, $extra);
        self::invoke($client, $case->call, $async);
        usleep(20_000);
        $log = self::mockLog($session)->requests;
        $exp = $case->expect;
        if (isset($exp->request)) {
            if (count($log) !== 1) {
                throw new Fail(sprintf('expected 1 request, the mock received %d: %s', count($log), self::paths($log)));
            }
            self::checkRequest($exp->request, $log[0], 'request');

            return;
        }
        self::checkRequestList($exp->requests, $log);
    }

    /**
     * @param list<\stdClass> $expected
     * @param list<\stdClass> $log
     */
    private static function checkRequestList(array $expected, array $log): void
    {
        if (count($log) !== count($expected)) {
            throw new Fail(sprintf('expected %d requests, the mock received %d: %s', count($expected), count($log), self::paths($log)));
        }
        $i = 0;
        while ($i < count($expected)) {
            if (empty($expected[$i]->parallel)) {
                self::checkRequest($expected[$i], $log[$i], sprintf('requests[%d]', $i));
                ++$i;
                continue;
            }
            // Requests sent side by side may arrive in any order: one assignment must hold.
            $j = $i;
            while ($j < count($expected) && !empty($expected[$j]->parallel)) {
                ++$j;
            }
            // Which received request each expected one could be, then one
            // assignment that pairs them all (augmenting paths, so sixteen
            // parallel uploads cost 256 checks, not 16! orders).
            $fits = [];
            $reasons = [];
            foreach (range($i, $j - 1) as $k) {
                $fits[$k] = [];
                foreach (range($i, $j - 1) as $g) {
                    try {
                        self::checkRequest($expected[$k], $log[$g], sprintf('requests[%d]', $k));
                        $fits[$k][] = $g;
                    } catch (Fail $fail) {
                        $reasons[] = $fail->getMessage();
                    }
                }
            }
            $owner = [];
            foreach (array_keys($fits) as $k) {
                $seen = [];
                if (!self::assign($k, $fits, $owner, $seen)) {
                    throw new Fail('no order of the parallel requests matches: ' . ($reasons[0] ?? sprintf('requests[%d]', $k)));
                }
            }
            $i = $j;
        }
    }

    /**
     * Gives expected request `$k` a received one, moving earlier pairings
     * along when that frees one for it.
     *
     * @param array<int, list<int>> $fits  the received requests each expected one matches
     * @param array<int, int>       $owner received index to the expected index it is paired with
     * @param array<int, true>      $seen  received requests already tried on this path
     */
    private static function assign(int $k, array $fits, array &$owner, array &$seen): bool
    {
        foreach ($fits[$k] as $g) {
            if (isset($seen[$g])) {
                continue;
            }
            $seen[$g] = true;
            if (!isset($owner[$g]) || self::assign($owner[$g], $fits, $owner, $seen)) {
                $owner[$g] = $k;

                return true;
            }
        }

        return false;
    }

    private static function checkRequest(\stdClass $exp, \stdClass $got, string $where, bool $anyBody = false): void
    {
        if ($got->method !== $exp->method) {
            throw new Fail(sprintf('%s method: expected %s, got %s %s', $where, $exp->method, $got->method, $got->path));
        }
        if (isset($exp->path) && $got->path !== $exp->path) {
            throw new Fail(sprintf('%s path: expected %s, got %s', $where, $exp->path, $got->path));
        }
        if (isset($exp->path_pattern) && !self::matches($exp->path_pattern, $got->path)) {
            throw new Fail(sprintf('%s path: %s does not match %s', $where, $got->path, $exp->path_pattern));
        }
        if (isset($exp->query)) {
            parse_str((string) ($got->query ?? ''), $query);
            if (!Compare::same($exp->query, $query)) {
                throw new Fail(sprintf('%s query: expected %s, got %s', $where, Compare::show($exp->query), Compare::show($query)));
            }
        }
        if (isset($exp->status) && ($got->status ?? null) !== $exp->status) {
            throw new Fail(sprintf('%s status: the mock answered %s, expected %s', $where, json_encode($got->status ?? null), $exp->status));
        }
        $headers = array_change_key_case(get_object_vars($got->headers), CASE_LOWER);
        foreach (get_object_vars($exp->headers ?? new \stdClass()) as $name => $value) {
            if (($headers[strtolower((string) $name)] ?? null) !== $value) {
                throw new Fail(sprintf('%s header %s: expected %s, got %s', $where, $name, json_encode($value), json_encode($headers[strtolower((string) $name)] ?? null)));
            }
        }
        foreach (get_object_vars($exp->header_patterns ?? new \stdClass()) as $name => $pattern) {
            $value = $headers[strtolower((string) $name)] ?? null;
            if ($value === null || !self::matches($pattern, $value)) {
                throw new Fail(sprintf('%s header %s: %s does not match %s', $where, $name, json_encode($value), $pattern));
            }
        }
        foreach ($exp->absent_headers ?? [] as $name) {
            if (array_key_exists(strtolower($name), $headers)) {
                throw new Fail(sprintf('%s header %s must not be sent', $where, $name));
            }
        }
        if (isset($exp->body_base64)) {
            if (($got->body_base64 ?? null) !== $exp->body_base64) {
                throw new Fail(sprintf('%s body: the bytes differ from the expected ones', $where));
            }

            return;
        }
        if (isset($exp->body_sha256)) {
            $digest = hash('sha256', (string) base64_decode((string) ($got->body_base64 ?? ''), true));
            if ($digest !== $exp->body_sha256) {
                throw new Fail(sprintf('%s body: sha256 %s, expected %s', $where, $digest, $exp->body_sha256));
            }

            return;
        }
        $text = (string) ($got->body_text ?? '');
        if (property_exists($exp, 'jsonl')) {
            $lines = self::jsonLines($text);
            if (!Compare::same($exp->jsonl, $lines)) {
                throw new Fail(sprintf('%s body lines: expected %s, got %s', $where, Compare::show($exp->jsonl), Compare::show($lines)));
            }

            return;
        }
        if (isset($exp->jsonl_count) || isset($exp->jsonl_checks)) {
            $lines = self::jsonLines($text);
            if (isset($exp->jsonl_count) && count($lines) !== $exp->jsonl_count) {
                throw new Fail(sprintf('%s body: expected %d lines, got %d', $where, $exp->jsonl_count, count($lines)));
            }
            foreach ($exp->jsonl_checks ?? [] as $check) {
                if (!array_key_exists($check->line, $lines)) {
                    throw new Fail(sprintf('%s body: no line %d', $where, $check->line));
                }
                self::checkValue($check, $lines[$check->line], sprintf('%s line %d', $where, $check->line));
            }

            return;
        }
        $body = $text === '' ? null : json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        if (property_exists($exp, 'body')) {
            if (!Compare::same($exp->body, $body)) {
                throw new Fail(sprintf('%s body: expected %s, got %s', $where, Compare::show($exp->body), Compare::show($body)));
            }
        } elseif (!property_exists($exp, 'body_checks') && $text !== '' && !$anyBody) {
            throw new Fail(sprintf('%s: expected no body, got %s', $where, Compare::show($text)));
        }
        foreach ($exp->body_checks ?? [] as $check) {
            self::checkValue($check, $body, $where);
        }
        foreach ($exp->ordered_keys ?? [] as $pointer) {
            [, $want] = Compare::pointer($exp->body, $pointer);
            [, $have] = Compare::pointer($body, $pointer);
            $wantKeys = array_map('strval', array_keys(get_object_vars($want)));
            $haveKeys = $have instanceof \stdClass ? array_map('strval', array_keys(get_object_vars($have))) : [];
            if ($wantKeys !== $haveKeys) {
                throw new Fail(sprintf('%s body%s: key order expected %s, got %s', $where, $pointer, Compare::show($wantKeys), Compare::show($haveKeys)));
            }
        }
    }

    private static function checkValue(\stdClass $check, mixed $doc, string $where): void
    {
        [$found, $value] = Compare::pointer($doc, $check->pointer);
        if (!empty($check->absent)) {
            if ($found) {
                throw new Fail(sprintf('%s body%s: must be absent, got %s', $where, $check->pointer, Compare::show($value)));
            }

            return;
        }
        if (!$found) {
            throw new Fail(sprintf('%s body%s: missing', $where, $check->pointer));
        }
        if (property_exists($check, 'equals') && !Compare::same($check->equals, $value)) {
            throw new Fail(sprintf('%s body%s: expected %s, got %s', $where, $check->pointer, Compare::show($check->equals), Compare::show($value)));
        }
        if (property_exists($check, 'type') && !Compare::isType($value, $check->type)) {
            throw new Fail(sprintf('%s body%s: expected a %s', $where, $check->pointer, $check->type));
        }
        if (property_exists($check, 'pattern') && (!is_string($value) || !self::matches($check->pattern, $value))) {
            throw new Fail(sprintf('%s body%s: %s does not match %s', $where, $check->pointer, Compare::show($value), $check->pattern));
        }
        if (property_exists($check, 'keys')) {
            $keys = $value instanceof \stdClass ? array_map('strval', array_keys(get_object_vars($value))) : null;
            if ($keys !== $check->keys) {
                throw new Fail(sprintf('%s body%s: keys expected %s, got %s', $where, $check->pointer, Compare::show($check->keys), Compare::show($keys)));
            }
        }
    }

    /**
     * @return list<mixed>
     */
    private static function jsonLines(string $text): array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return [];
        }
        if (str_starts_with($trimmed, '[')) {
            return json_decode($trimmed, false, 512, JSON_THROW_ON_ERROR);
        }
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if (trim($line) !== '') {
                $out[] = json_decode($line, false, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $out;
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
            $got = [];
            foreach ($result as $i => $m) {
                $entry = [
                    'name' => $m->name,
                    'description' => $m->description,
                    'release_date' => $m->releaseDate,
                    'limits' => $m->limits,
                ];
                // The fine-tuned fields are compared when the case lists them.
                $listed = $exp->models[$i] ?? new \stdClass();
                foreach (['fine_tuned' => $m->fineTuned, 'base' => $m->base, 'adapter_version' => $m->adapterVersion, 'status' => $m->status] as $field => $value) {
                    if (property_exists($listed, $field)) {
                        $entry[$field] = $value;
                    }
                }
                $got[] = $entry;
            }
            if (!Compare::same($exp->models, $got)) {
                throw new Fail('models: expected ' . Compare::show($exp->models) . ', got ' . Compare::show($got));
            }
        } elseif (property_exists($exp, 'raw')) {
            if (!Compare::same($exp->raw, $result)) {
                throw new Fail('raw: expected ' . Compare::show($exp->raw) . ', got ' . Compare::show($result));
            }
        } elseif (property_exists($exp, 'batch')) {
            Compare::match($exp->batch, self::batch($result), 'batch');
        } elseif (property_exists($exp, 'batch_page')) {
            Compare::match($exp->batch_page, self::batchPage($result), 'batch_page');
        } elseif (property_exists($exp, 'batch_results')) {
            self::checkBatchResults($exp->batch_results, $result, 'batch_results');
        } elseif (property_exists($exp, 'session')) {
            Compare::match($exp->session, self::sessionValue($result), 'session');
        } else {
            throw new Fail('the case expects nothing this harness knows');
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
        // recorder: the delay is noted and the request is sent at once.
        $waits = isset($exp->scheduled_waits_ms) ? [] : null;
        $events = [];
        $started = hrtime(true);
        try {
            // An argument error may come from the constructor (a bad base URL,
            // timeout or maxRetries) or from the call; both count.
            $client = self::client($case, $extra, $waits);
            if (isset($case->steps)) {
                self::runSteps($case, $client, $session, $events, $async);
                $result = null;
            } else {
                $result = self::invoke($client, $case->call, $async);
            }
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
        } elseif (isset($exp->attempts) && count($log) !== $exp->attempts) {
            throw new Fail(sprintf('attempts: expected %d, the mock received %d: %s', $exp->attempts, count($log), self::paths($log)));
        }
        if (isset($exp->request_sequence)) {
            self::checkSequence($exp->request_sequence, $log);
        }
        if (isset($exp->request_counts)) {
            self::checkCounts($exp->request_counts, $log);
        }
        if (isset($exp->session_events)) {
            self::checkEvents($exp->session_events, $events);
        }
        if (isset($exp->pongs)) {
            self::inRange($exp->pongs, (float) $info->pongs, 'pongs');
        }
        if (isset($exp->outcome)) {
            self::checkOutcome($exp->outcome, $result, 'outcome');
        }
        if (isset($exp->results)) {
            if (!is_array($result) || count($result) !== count($exp->results)) {
                throw new Fail(sprintf('results: expected %d results, got %s', count($exp->results), self::describe($result)));
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
                throw new Fail(sprintf('scheduled waits: expected %d, the client scheduled %d (%s)', count($exp->scheduled_waits_ms), count($waits), json_encode($waits)));
            }
            foreach ($exp->scheduled_waits_ms as $i => $range) {
                self::inRange($range, $waits[$i], sprintf('scheduled wait %d (ms)', $i + 1));
            }
        }
        if (isset($case->steps) && $result instanceof \Throwable) {
            throw new Fail(sprintf('the steps raised %s: %s', get_class($result), $result->getMessage()));
        }
        foreach ($exp->unrequested_paths ?? [] as $path) {
            $reached = count(self::mockLog('*', $path)->requests);
            if ($reached !== 0) {
                throw new Fail(sprintf('%s was requested %d times', $path, $reached));
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private static function runSteps(\stdClass $case, DecisionNode $client, string $session, array &$events, bool $async): void
    {
        $ctx = [];
        foreach ($case->steps as $i => $step) {
            $where = sprintf('steps[%d]', $i);
            if (isset($step->call)) {
                try {
                    $result = self::invoke($client, $step->call, $async, $ctx);
                } catch (DecisionNodeError|\InvalidArgumentException|SessionClosed $error) {
                    $result = $error;
                }
                if (isset($step->outcome)) {
                    self::checkOutcome($step->outcome, $result, $where . '.outcome');
                } elseif ($result instanceof \Throwable) {
                    throw new Fail(sprintf('%s: the call raised %s: %s', $where, get_class($result), $result->getMessage()));
                }
                if (isset($step->save_as)) {
                    $ctx[$step->save_as] = $result;
                }
            } elseif (isset($step->connect)) {
                try {
                    self::sessionOf($ctx, $step->connect)->connect();
                } catch (SessionClosed $closed) {
                    // A refused handshake may surface here or as the first event: both count.
                    $events[] = self::event($closed);
                }
            } elseif (isset($step->send)) {
                $args = [];
                foreach (get_object_vars($step->send) as $name => $value) {
                    if ($name !== 'session') {
                        $args[(string) $name] = self::decode($value, $ctx);
                    }
                }
                if (!empty($step->check)) {
                    try {
                        self::sessionOf($ctx, $step->send->session)->checkFrame(...$args);
                        $checked = new Checked();
                    } catch (DecisionNodeError|\InvalidArgumentException $error) {
                        $checked = $error;
                    }
                    self::checkOutcome($step->outcome ?? (object) ['result' => 'checked'], $checked, $where . '.outcome');
                    continue;
                }
                try {
                    self::sessionOf($ctx, $step->send->session)->send(...$args);
                } catch (DecisionNodeError|\InvalidArgumentException $error) {
                    // A frame that breaks a fixed rule is refused locally and nothing is sent.
                    if (!isset($step->outcome)) {
                        throw $error;
                    }
                    self::checkOutcome($step->outcome, $error, $where . '.outcome');
                    continue;
                }
                if (isset($step->outcome)) {
                    throw new Fail(sprintf('%s: expected %s, the frame was sent', $where, $step->outcome->error ?? 'an error'));
                }
            } elseif (isset($step->read)) {
                $s = self::sessionOf($ctx, $step->read->session);
                $count = $step->read->count ?? null;
                $timeout = ($step->read->timeout_ms ?? 10000) / 1000;
                while (true) {
                    $event = $s->next($timeout);
                    if ($event === null) {
                        throw new Fail(sprintf('%s: no session event arrived in time (%d read so far)', $where, count($events)));
                    }
                    $events[] = self::event($event);
                    if ($event instanceof SessionClosed) {
                        break;
                    }
                    if ($count !== null && --$count === 0) {
                        break;
                    }
                }
            } elseif (isset($step->end)) {
                try {
                    $s = self::sessionOf($ctx, $step->end);
                    $async ? $s->endAsync()->wait() : $s->end();
                    $events[] = ['ended' => true];
                } catch (DecisionNodeError $error) {
                    $events[] = ['end_error' => ['class' => self::shortName($error), 'status' => $error->status]];
                }
            } elseif (isset($step->close)) {
                self::sessionOf($ctx, $step->close)->close();
            } elseif (isset($step->mock_clock)) {
                self::control('POST', '/_mock/clock?session=' . rawurlencode($session), (string) json_encode($step->mock_clock));
            } elseif (isset($step->sleep_ms)) {
                usleep((int) $step->sleep_ms * 1000);
            } else {
                throw new Fail(sprintf('%s: unknown step %s', $where, json_encode($step)));
            }
        }
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private static function sessionOf(array $ctx, string $name): Session
    {
        $session = $ctx[$name] ?? null;
        if (!$session instanceof Session) {
            throw new Fail(sprintf('%s is not a session: %s', $name, self::describe($session)));
        }

        return $session;
    }

    /**
     * @param list<\stdClass> $expected
     * @param list<\stdClass> $log
     */
    private static function checkSequence(array $expected, array $log): void
    {
        if (count($expected) !== count($log)) {
            throw new Fail(sprintf('request sequence: expected %d requests, the mock received %d: %s', count($expected), count($log), self::paths($log)));
        }
        foreach ($expected as $i => $spec) {
            $rec = $log[$i];
            $got = $rec->method . ' ' . $rec->path;
            if ($rec->method !== $spec->method) {
                throw new Fail(sprintf('request sequence[%d]: expected %s, got %s', $i, $spec->method, $got));
            }
            if (isset($spec->path) && $rec->path !== $spec->path) {
                throw new Fail(sprintf('request sequence[%d]: expected %s %s, got %s', $i, $spec->method, $spec->path, $got));
            }
            if (isset($spec->path_pattern) && !self::matches($spec->path_pattern, $rec->path)) {
                throw new Fail(sprintf('request sequence[%d]: %s does not match %s', $i, $got, $spec->path_pattern));
            }
            if (isset($spec->status) && ($rec->status ?? null) !== $spec->status) {
                throw new Fail(sprintf('request sequence[%d]: %s answered %s, expected %s', $i, $got, json_encode($rec->status ?? null), $spec->status));
            }
            $checks = new \stdClass();
            foreach (['headers', 'header_patterns', 'absent_headers', 'body_checks', 'jsonl_count', 'jsonl_checks', 'body_base64', 'body_sha256', 'query'] as $key) {
                if (property_exists($spec, $key)) {
                    $checks->{$key} = $spec->{$key};
                }
            }
            if (get_object_vars($checks) !== []) {
                $checks->method = $spec->method;
                self::checkRequest($checks, $rec, sprintf('request sequence[%d]', $i), anyBody: true);
            }
        }
    }

    /**
     * @param list<\stdClass> $expected
     * @param list<\stdClass> $log
     */
    private static function checkCounts(array $expected, array $log): void
    {
        foreach ($expected as $spec) {
            $n = count(array_filter($log, static fn (\stdClass $r): bool => $r->method === $spec->method
                && (!isset($spec->path) || $r->path === $spec->path)
                && (!isset($spec->path_pattern) || self::matches($spec->path_pattern, $r->path))));
            $what = $spec->method . ' ' . ($spec->path ?? $spec->path_pattern ?? '');
            if (isset($spec->count) && $n !== $spec->count) {
                throw new Fail(sprintf('%s: expected %d requests, got %d', $what, $spec->count, $n));
            }
            $range = new \stdClass();
            foreach (['min', 'max'] as $key) {
                if (isset($spec->{$key})) {
                    $range->{$key} = $spec->{$key};
                }
            }
            self::inRange($range, (float) $n, $what);
        }
    }

    /**
     * @param list<\stdClass>            $expected
     * @param list<array<string, mixed>> $events
     */
    private static function checkEvents(array $expected, array $events): void
    {
        if (count($expected) !== count($events)) {
            throw new Fail(sprintf('session events: expected %d, got %d: %s', count($expected), count($events), Compare::show($events)));
        }
        foreach ($expected as $i => $spec) {
            $key = (string) array_key_first(get_object_vars($spec));
            $event = $events[$i];
            if (array_keys($event) !== [$key]) {
                throw new Fail(sprintf('session events[%d]: expected %s, got %s', $i, $key, Compare::show($event)));
            }
            Compare::match($spec->{$key}, $event[$key], sprintf('session events[%d].%s', $i, $key));
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
        $session = $extraHeaders['x-mock-session'] ?? '';
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
        if (isset($spec->upload_timeout)) {
            $args['uploadTimeout'] = (float) $spec->upload_timeout;
        }
        if (isset($spec->inline_threshold)) {
            $args['inlineThreshold'] = (int) $spec->inline_threshold;
        }
        if (($spec->clock ?? null) === 'mock') {
            // The SDK's injectable clock reads the mock's clock for this
            // session, so a case that moves the mock's time moves the SDK's.
            $args['clock'] = static function () use ($session): float {
                $clock = json_decode(self::control('GET', '/_mock/clock?session=' . rawurlencode($session)), false, 512, JSON_THROW_ON_ERROR);

                return (float) $clock->epoch_seconds;
            };
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
     * Calls the method, or with `$async` its Async form when it has one and
     * waits on the promise.
     *
     * @param array<string, mixed> $ctx values saved by earlier steps
     */
    private static function invoke(DecisionNode $client, \stdClass $call, bool $async = false, array $ctx = []): mixed
    {
        $name = (string) $call->method;
        $target = $client;
        if (str_starts_with($name, 'batches.')) {
            $target = $client->batches;
            $name = substr($name, strlen('batches.'));
        }
        $method = self::METHOD_NAMES[$name] ?? lcfirst(str_replace('_', '', ucwords($name, '_')));

        $args = [];
        foreach (get_object_vars($call->args) as $arg => $value) {
            $arg = (string) $arg;
            $phpName = self::ARGUMENT_NAMES[$arg] ?? lcfirst(str_replace('_', '', ucwords($arg, '_')));
            $args[$phpName] = $name === 'decide_raw' ? $value : self::decode($value, $ctx);
        }

        if ($name === 'supervise') {
            return self::supervise($client, $args);
        }
        if (!empty($call->check)) {
            // Not made: the same arguments go to the public check, which
            // sends and uploads nothing and returns nothing when clean.
            $client->checkRequest((string) $call->method, $args);

            return new Checked();
        }

        if ($async && method_exists($target, $method . 'Async')) {
            $result = $target->{$method . 'Async'}(...$args);
            if (!$result instanceof PromiseInterface) {
                throw new Fail($method . 'Async did not return a promise');
            }

            return $result->wait();
        }

        return $target->{$method}(...$args);
    }

    /**
     * `supervise` with a feed that yields the readings `feed_interval_ms`
     * apart and an onReply that collects.
     *
     * @param array<string, mixed> $args
     *
     * @return array{supervised: true, replies: list<Reply|FrameError>, closed: SessionClosed|null}
     */
    private static function supervise(DecisionNode $client, array $args): array
    {
        $interval = (int) ($args['feedIntervalMs'] ?? 0);
        $readings = $args['feed'];
        $feed = (static function () use ($readings, $interval): \Generator {
            foreach ($readings as $i => $reading) {
                if ($i > 0) {
                    usleep($interval * 1000);
                }
                yield get_object_vars($reading);
            }
        })();
        $replies = [];
        $closed = $client->supervise($args['body'], $feed, static function (Reply|FrameError $event) use (&$replies): void {
            $replies[] = $event;
        });

        return ['supervised' => true, 'replies' => $replies, 'closed' => $closed];
    }

    /**
     * Turns `{"$truth": {...}}` and the other builder markers into builder
     * calls, `{"$ref": "b.id"}` into a saved value and `{"$text": ...}` into
     * a long string.
     *
     * @param array<string, mixed> $ctx
     */
    private static function decode(mixed $value, array $ctx = []): mixed
    {
        if ($value instanceof \stdClass) {
            $vars = get_object_vars($value);
            if (count($vars) === 1) {
                $key = (string) array_key_first($vars);
                $a = $vars[$key];
                switch ($key) {
                    case '$ref':
                        return self::ref((string) $a, $ctx);
                    case '$text':
                        return str_repeat((string) $a->repeat, (int) $a->times);
                    case '$list':
                        // count copies of item, {i} in every string replaced by the index
                        $out = [];
                        for ($i = 0; $i < (int) $a->count; ++$i) {
                            $out[] = self::decode(self::index($a->item, $i), $ctx);
                        }

                        return $out;
                    case '$nest':
                        // the value inside depth arrays: [[[...value...]]]
                        $out = self::decode($a->value, $ctx);
                        for ($i = 0; $i < (int) $a->depth; ++$i) {
                            $out = [$out];
                        }

                        return $out;
                    case '$truth':
                        return truth($a->instructions ?? null, $a->true ?? null, $a->false ?? null);
                    case '$choice':
                        return choice($a->instructions ?? null, $a->options);
                    case '$score':
                        return score($a->instructions ?? null, $a->levels);
                    case '$number':
                        return number($a->instructions ?? null, $a->min ?? null, $a->max ?? null, $a->step ?? null);
                    case '$point':
                        return point($a->instructions ?? null, $a->image ?? null, $a->units ?? null);
                    case '$box':
                        return box($a->instructions ?? null, $a->image ?? null, $a->units ?? null);
                    case '$image':
                        return self::image($a, $ctx);
                    case '$video':
                        return self::video($a, $ctx);
                    case '$frame':
                        return self::frame($a);
                    case '$booster':
                        // Booster(name, images, require, options), each argument passed only when present
                        $named = ['name' => (string) $a->name];
                        foreach (['images', 'require', 'options'] as $field) {
                            if (property_exists($a, $field)) {
                                $named[$field] = self::decode($a->{$field}, $ctx);
                            }
                        }

                        return new Booster(...$named);
                }
            }
            $out = [];
            foreach ($vars as $k => $v) {
                $out[$k] = self::decode($v, $ctx);
            }

            return (object) $out;
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $v): mixed => self::decode($v, $ctx), $value);
        }

        return $value;
    }

    private static function index(mixed $value, int $i): mixed
    {
        if (is_string($value)) {
            return str_replace('{i}', (string) $i, $value);
        }
        if ($value instanceof \stdClass) {
            $out = new \stdClass();
            foreach (get_object_vars($value) as $k => $v) {
                $out->{$k} = self::index($v, $i);
            }

            return $out;
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $v): mixed => self::index($v, $i), $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private static function image(\stdClass $a, array $ctx): Image
    {
        $id = (string) $a->id;
        $mediaType = isset($a->media_type) ? (string) $a->media_type : null;
        if (isset($a->url)) {
            return Image::fromUrl($id, (string) $a->url);
        }
        if (isset($a->upload_id)) {
            return Image::fromUploadId($id, (string) self::decode($a->upload_id, $ctx), $mediaType);
        }
        if (isset($a->path) && !isset($a->pad_to) && $mediaType === null) {
            return Image::fromPath($id, self::dir() . '/' . $a->path);
        }
        $data = isset($a->path)
            ? (string) file_get_contents(self::dir() . '/' . $a->path)
            : (string) base64_decode((string) $a->data_base64, true);
        if (isset($a->pad_to)) {
            // The image's own bytes followed by zero bytes: the header (type,
            // pixel size) is unchanged and the size is what the case needs.
            $data .= str_repeat("\0", max(0, (int) $a->pad_to - strlen($data)));
        }

        return Image::fromData($id, $data, $mediaType);
    }

    /**
     * `Video(id, frames, fps, start, end)`, `fps`, `start` and `end` passed only when present.
     *
     * @param array<string, mixed> $ctx
     */
    private static function video(\stdClass $a, array $ctx): Video
    {
        $frames = array_map(static fn (mixed $f): mixed => self::decode($f, $ctx), (array) $a->frames);
        $named = [];
        foreach (['fps', 'start', 'end'] as $name) {
            if (property_exists($a, $name)) {
                $named[$name] = $a->{$name};
            }
        }

        return Video::of((string) $a->id, $frames, ...$named);
    }

    /**
     * `VideoFrame(t, data, media_type)`: `path` and `data_base64` as raw bytes,
     * `data_text` as base64 text unchanged, the media type only when present.
     */
    private static function frame(\stdClass $a): VideoFrame
    {
        $mediaType = isset($a->media_type) ? (string) $a->media_type : null;
        if (isset($a->data_text)) {
            return VideoFrame::fromBase64($a->t, (string) $a->data_text, $mediaType);
        }
        $data = isset($a->path)
            ? (string) file_get_contents(self::dir() . '/' . $a->path)
            : (string) base64_decode((string) $a->data_base64, true);

        return VideoFrame::fromData($a->t, $data, $mediaType);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private static function ref(string $path, array $ctx): mixed
    {
        $parts = explode('.', $path);
        $current = $ctx[array_shift($parts)] ?? null;
        foreach ($parts as $part) {
            if (is_array($current)) {
                $current = $current[$part] ?? $current[(int) $part] ?? null;
            } elseif (is_object($current)) {
                $property = lcfirst(str_replace('_', '', ucwords($part, '_')));
                $current = $current->{$property} ?? $current->{$part} ?? null;
            } else {
                throw new Fail('cannot resolve $ref ' . $path);
            }
        }

        return $current;
    }

    // ------------------------------------------------------------ normalising

    /**
     * @return array<string, mixed>
     */
    public static function answer(mixed $a): array
    {
        return match (true) {
            $a instanceof NumberAnswer => ['kind' => 'number', 'number' => $a->number, 'expected' => $a->expected, 'confidence' => $a->confidence, 'probabilities' => $a->probabilities],
            $a instanceof TruthAnswer => ['kind' => 'truth', 'truth' => $a->truth],
            $a instanceof ChoiceAnswer => ['kind' => 'choice', 'choice' => $a->choice, 'confidence' => $a->confidence, 'probabilities' => $a->probabilities],
            $a instanceof ScoreAnswer => ['kind' => 'score', 'score' => $a->score, 'confidence' => $a->confidence, 'probabilities' => $a->probabilities, 'legend' => $a->legend],
            $a instanceof PointAnswer => ['kind' => 'point', 'x' => $a->x, 'y' => $a->y, 'present' => $a->present, 'confidence' => $a->confidence, 'image' => $a->image, 'width' => $a->width, 'height' => $a->height],
            $a instanceof BoxAnswer => ['kind' => 'box', 'x1' => $a->x1, 'y1' => $a->y1, 'x2' => $a->x2, 'y2' => $a->y2, 'present' => $a->present, 'confidence' => $a->confidence, 'image' => $a->image, 'width' => $a->width, 'height' => $a->height],
            $a instanceof UnknownAnswer => ['kind' => 'unknown', 'type' => $a->type, 'raw' => $a->raw],
            default => throw new Fail('expected an answer, got ' . self::describe($a)),
        };
    }

    /**
     * @param array<array-key, Answer> $answers
     *
     * @return array<array-key, array<string, mixed>>
     */
    private static function answers(array $answers): array
    {
        $out = [];
        foreach ($answers as $id => $answer) {
            $out[$id] = self::answer($answer);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decision(mixed $d): array
    {
        if (!$d instanceof Decision) {
            throw new Fail('expected a Decision, got ' . self::describe($d));
        }

        return [
            'model' => $d->model,
            'request_id' => $d->requestId,
            'latency_ms' => $d->latencyMs,
            'usage' => [
                'input_tokens' => $d->usage->inputTokens,
                'output_tokens' => $d->usage->outputTokens,
                'video_frames' => $d->usage->videoFrames,
                'video_seconds' => $d->usage->videoSeconds,
                'booster_tokens' => $d->usage->boosterTokens,
                'boosters' => $d->usage->boosters,
                'cost' => $d->usage->cost === null ? null : ['usd' => $d->usage->cost->usd, 'model_usd' => $d->usage->cost->modelUsd, 'boosters_usd' => $d->usage->cost->boostersUsd, 'free_tier' => $d->usage->cost->freeTier],
            ],
            'warnings' => $d->warnings,
            'fallback' => $d->fallback === null ? null : ['served_model' => $d->fallback->servedModel, 'requested_model' => $d->fallback->requestedModel],
            'evidence' => $d->evidence === null ? null : array_map(static fn (Evidence $e): array => [
                'version' => $e->version,
                'status' => $e->status,
                'latency_ms' => $e->latencyMs,
                'billed_calls' => $e->billedCalls,
                'results' => array_map(self::reading(...), $e->results),
            ], $d->evidence),
            'safety' => self::safety($d->safety),
            'answers' => self::answers($d->answers),
            'raw' => $d->raw,
        ];
    }

    /**
     * A reading in the suite's form: its kind and fields, or the raw fields of
     * a generic reading.
     *
     * @return array<string, mixed>
     */
    private static function reading(Reading $r): array
    {
        return match (true) {
            $r instanceof AgeReading => ['kind' => 'age', 'image' => $r->image, 'faces' => $r->faces, 'age_years' => $r->ageYears, 'low' => $r->low, 'high' => $r->high, 'confidence' => $r->confidence],
            $r instanceof AiGeneratedReading => ['kind' => 'ai_generated', 'image' => $r->image, 'generated' => $r->generated, 'confidence' => $r->confidence, 'method' => $r->method, 'top_generator' => $r->topGenerator, 'c2pa_signer' => $r->c2paSigner, 'c2pa' => $r->c2pa],
            $r instanceof OcrReading => ['kind' => 'ocr', 'image' => $r->image, 'text' => $r->text, 'truncated' => $r->truncated],
            $r instanceof FaceDetectionReading => ['kind' => 'face_detection', 'image' => $r->image, 'faces' => $r->faces, 'detections' => $r->detections],
            $r instanceof WantedPersonReading => ['kind' => 'wanted_person', 'image' => $r->image, 'faces' => $r->faces, 'matches' => $r->matches],
            default => ['kind' => 'unknown', 'raw' => $r->raw],
        };
    }

    /**
     * Every field of the batch object, read from the Batch's typed fields.
     *
     * @return array<string, mixed>
     */
    private static function batch(mixed $b): array
    {
        if (!$b instanceof Batch) {
            throw new Fail('expected a Batch, got ' . self::describe($b));
        }

        return [
            'id' => $b->id,
            'state' => $b->state,
            'counters' => $b->counters,
            'created_at' => $b->createdAt,
            'finalized_at' => $b->finalizedAt,
            'expires_at' => $b->expiresAt,
            'ended_at' => $b->endedAt,
            'results_url' => $b->resultsUrl,
            'results_expire_at' => $b->resultsExpireAt,
            'price_factor' => $b->priceFactor,
            'usage' => $b->usage,
            'error' => $b->error,
        ] + $b->raw;
    }

    /**
     * @return array<string, mixed>
     */
    private static function batchPage(mixed $p): array
    {
        if (!$p instanceof BatchPage) {
            throw new Fail('expected a BatchPage, got ' . self::describe($p));
        }

        return [
            'batches' => array_map([self::class, 'batch'], $p->batches),
            'offset' => $p->offset,
            'limit' => $p->limit,
            'total' => $p->total,
            'next_offset' => $p->nextOffset,
        ];
    }

    /**
     * @param list<\stdClass> $expected
     */
    private static function checkBatchResults(array $expected, mixed $results, string $path): void
    {
        if (!is_array($results) || count($results) !== count($expected)) {
            throw new Fail(sprintf('%s: expected %d results, got %s', $path, count($expected), self::describe($results)));
        }
        foreach ($expected as $i => $spec) {
            $r = $results[$i];
            $where = sprintf('%s[%d]', $path, $i);
            if (!$r instanceof BatchResult) {
                throw new Fail($where . ': not a BatchResult');
            }
            if (property_exists($spec, 'custom_id') && !Compare::same($spec->custom_id, $r->customId)) {
                throw new Fail(sprintf('%s.custom_id: expected %s, got %s', $where, Compare::show($spec->custom_id), Compare::show($r->customId)));
            }
            if (property_exists($spec, 'status') && !Compare::same($spec->status, $r->status)) {
                throw new Fail(sprintf('%s.status: expected %s, got %s', $where, Compare::show($spec->status), Compare::show($r->status)));
            }
            if (property_exists($spec, 'headers')) {
                Compare::match($spec->headers, $r->headers, $where . '.headers');
            }
            if (property_exists($spec, 'decision') && $spec->decision !== null) {
                if ($r->decision === null) {
                    throw new Fail($where . ': expected a decision, got none');
                }
                Compare::match($spec->decision, self::decision($r->decision), $where . '.decision');
            }
            if (property_exists($spec, 'error')) {
                if ($r->error === null) {
                    throw new Fail($where . ': expected an error value, got none');
                }
                self::checkError($spec->error, $r->error, $where . '.error');
            }
            if (!empty($spec->no_decision) && $r->decision !== null) {
                throw new Fail($where . ': expected no decision');
            }
        }
    }

    /**
     * `batch_results` whole, or `count` and `batch_results_sample` by index.
     */
    private static function checkResultsOutcome(\stdClass $spec, mixed $results, string $path): void
    {
        if (isset($spec->batch_results)) {
            self::checkBatchResults($spec->batch_results, $results, $path . '.batch_results');
        }
        if (isset($spec->count) && (!is_array($results) || count($results) !== $spec->count)) {
            throw new Fail(sprintf('%s: expected %d results, got %s', $path, $spec->count, is_array($results) ? (string) count($results) : self::describe($results)));
        }
        foreach ($spec->batch_results_sample ?? [] as $sample) {
            $index = (int) $sample->index;
            if (!is_array($results) || !array_key_exists($index, $results)) {
                throw new Fail(sprintf('%s: no result %d', $path, $index));
            }
            $one = clone $sample;
            unset($one->index);
            self::checkBatchResults([$one], [$results[$index]], sprintf('%s.batch_results[%d]', $path, $index));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function sessionValue(mixed $s): array
    {
        if (!$s instanceof Session) {
            throw new Fail('expected a Session, got ' . self::describe($s));
        }

        return ['answers' => self::answers($s->answers)] + $s->raw;
    }

    /**
     * @return array<string, mixed>
     */
    private static function event(mixed $event): array
    {
        if ($event instanceof Reply) {
            $out = ['seq' => $event->seq, 'answers' => self::answers($event->answers), 'usage' => $event->usage, 'latency_ms' => $event->latencyMs, 'safety' => $event->safety];
            if ($event->dropped !== null) {
                $out['dropped'] = $event->dropped;
            }

            return ['reply' => $out];
        }
        if ($event instanceof FrameError) {
            $out = ['seq' => $event->seq, 'error_type' => $event->errorType, 'message' => $event->message];
            foreach (['category' => $event->category, 'retry_after_ms' => $event->retryAfterMs, 'usage' => $event->usage, 'safety' => $event->safety] as $key => $value) {
                if ($value !== null) {
                    $out[$key] = $value;
                }
            }

            return ['frame_error' => $out];
        }
        if ($event instanceof SessionClosed) {
            return ['closed' => ['code' => $event->closeCode, 'reopen' => $event->reopen]];
        }

        throw new Fail('unknown session event ' . self::describe($event));
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
            'boosters' => $e->boosters,
        ];
    }

    private static function shortName(object $o): string
    {
        return substr((string) strrchr('\\' . get_class($o), '\\'), 1);
    }

    private static function checkError(\stdClass $spec, \Throwable $err, string $path): void
    {
        $class = $spec->error ?? $spec->class;
        $names = array_map(static fn (string $fqcn): string => substr((string) strrchr('\\' . $fqcn, '\\'), 1), array_merge([get_class($err)], array_values(class_parents($err) ?: [])));
        if (!empty($spec->subclass_ok)) {
            if (!in_array($class, $names, true)) {
                throw new Fail(sprintf('%s: expected %s or a subclass, got %s (%s)', $path, $class, $names[0], $err->getMessage()));
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
        if ($result instanceof SessionClosed && !isset($spec->closed)) {
            throw new Fail(sprintf('%s: the call raised SessionClosed(%d)', $path, $result->closeCode));
        }
        if (!empty($spec->argument_error)) {
            if (!$result instanceof \InvalidArgumentException) {
                throw new Fail(sprintf('%s: expected an InvalidArgumentException, got %s', $path, self::describe($result)));
            }

            return;
        }
        if ($result instanceof \InvalidArgumentException) {
            throw new Fail(sprintf('%s: unexpected argument error: %s', $path, $result->getMessage()));
        }
        if (isset($spec->result)) {
            if ($result instanceof \Throwable) {
                throw new Fail(sprintf('%s: expected %s, got %s: %s', $path, $spec->result, get_class($result), $result->getMessage()));
            }
            switch ($spec->result) {
                case 'decision':
                    $normal = self::decision($result);
                    if (isset($spec->decision)) {
                        Compare::match($spec->decision, $normal, $path . '.decision');
                    }
                    break;
                case 'answer':
                    Compare::match($spec->answer ?? new \stdClass(), self::answer($result), $path . '.answer');
                    break;
                case 'batch':
                    Compare::match($spec->batch ?? new \stdClass(), self::batch($result), $path . '.batch');
                    break;
                case 'batch_page':
                    Compare::match($spec->batch_page ?? new \stdClass(), self::batchPage($result), $path . '.batch_page');
                    break;
                case 'batch_results':
                    self::checkResultsOutcome($spec, $result, $path);
                    break;
                case 'checked':
                    if (!$result instanceof Checked) {
                        throw new Fail(sprintf('%s: expected a clean check, got %s', $path, self::describe($result)));
                    }
                    break;
                case 'batch_run':
                    if (!is_array($result) || count($result) !== 2) {
                        throw new Fail($path . ': expected [Batch, results], got ' . self::describe($result));
                    }
                    Compare::match($spec->batch ?? new \stdClass(), self::batch($result[0]), $path . '.batch');
                    self::checkResultsOutcome($spec, $result[1], $path);
                    break;
                case 'session':
                    Compare::match($spec->session ?? new \stdClass(), self::sessionValue($result), $path . '.session');
                    break;
                case 'upload_ids':
                    if (!is_array($result) || (isset($spec->count) && count($result) !== $spec->count)) {
                        throw new Fail(sprintf('%s: expected %s upload ids, got %s', $path, json_encode($spec->count ?? null), Compare::show($result)));
                    }
                    foreach ($result as $id) {
                        if (!is_string($id) || preg_match('/^upl_[0-9a-f]{32}$/', $id) !== 1) {
                            throw new Fail(sprintf('%s: %s is not an upload id', $path, Compare::show($id)));
                        }
                    }
                    if (isset($spec->distinct) && (count(array_unique($result)) === count($result)) !== $spec->distinct) {
                        throw new Fail(sprintf('%s: upload ids %s', $path, Compare::show($result)));
                    }
                    break;
                case 'supervised':
                    if (!is_array($result) || empty($result['supervised'])) {
                        throw new Fail($path . ': expected supervise to return');
                    }
                    $replies = count(array_filter($result['replies'], static fn (mixed $e): bool => $e instanceof Reply));
                    self::inRange($spec->replies ?? new \stdClass(), (float) $replies, 'replies');
                    if (property_exists($spec, 'closed')) {
                        $closed = $result['closed'];
                        $got = $closed === null ? null : ['code' => $closed->closeCode, 'reopen' => $closed->reopen];
                        if (!Compare::same($spec->closed, $got)) {
                            throw new Fail(sprintf('%s.closed: expected %s, got %s', $path, Compare::show($spec->closed), Compare::show($got)));
                        }
                    }
                    break;
                default:
                    throw new Fail($path . ': unknown result kind ' . $spec->result);
            }

            return;
        }
        if (!$result instanceof DecisionNodeError) {
            throw new Fail(sprintf('%s: expected %s, got %s', $path, $spec->error, self::describe($result)));
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

    private static function matches(string $pattern, string $value): bool
    {
        return preg_match('~' . str_replace('~', '\~', $pattern) . '~', $value) === 1;
    }

    /**
     * @param list<\stdClass> $log
     */
    private static function paths(array $log): string
    {
        return implode(', ', array_map(static fn (\stdClass $r): string => $r->method . ' ' . $r->path . ' ' . json_encode($r->status ?? null), $log));
    }

    private static function describe(mixed $value): string
    {
        if ($value instanceof \Throwable) {
            return get_class($value) . ': ' . $value->getMessage();
        }

        return is_object($value) ? get_class($value) : gettype($value);
    }

    private static function session(): string
    {
        return bin2hex(random_bytes(16));
    }
}
