<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

use Bynn\DecisionNode\Exception\AuthenticationError;
use Bynn\DecisionNode\Exception\ConnectionError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\ServerError;
use Bynn\DecisionNode\Exception\TimeoutError;
use Bynn\DecisionNode\Internal\Errors;
use Bynn\DecisionNode\Internal\GuzzleTransport;
use Bynn\DecisionNode\Internal\Json;
use Bynn\DecisionNode\Internal\Retry;
use Bynn\DecisionNode\Internal\Transport;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Each;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * The DecisionNode client.
 *
 *     $dn = new DecisionNode();               // reads DECISIONNODE_API_KEY
 *     $d = $dn->decide(
 *         state: ['ticket' => 'I was charged twice.'],
 *         questions: ['escalate' => truth('Escalate to a human now?')],
 *     );
 *     $d['escalate']->truth;                  // 0.94
 *
 * A client can be shared and reuses its connections. Every method has an
 * `...Async` form that returns a promise; `->wait()` on it gives the result.
 */
final class DecisionNode
{
    public const VERSION = '0.1.0';

    /** The version of the DecisionNode SDK specification this client implements. */
    public const SPEC_VERSION = '1.0';

    public const DEFAULT_BASE_URL = 'https://api.decisionnode.com';

    public const DEFAULT_MODEL = 'decisionnode-latest';

    public const DEFAULT_TIMEOUT = 10.0;

    public const DEFAULT_MAX_RETRIES = 2;

    public const DEFAULT_CONCURRENCY = 8;

    public readonly string $baseUrl;

    public readonly string $model;

    public readonly float $timeout;

    public readonly int $maxRetries;

    private readonly ?string $apiKey;

    /** @var array<string, string> */
    private readonly array $headers;

    private readonly Transport $transport;

    /**
     * Every argument is optional.
     *
     * @param string|null                              $apiKey     default: DECISIONNODE_API_KEY
     * @param string|null                              $baseUrl    default: DECISIONNODE_BASE_URL, else https://api.decisionnode.com
     * @param string|null                              $model      default: DECISIONNODE_MODEL, else decisionnode-latest
     * @param float                                    $timeout    seconds for a whole attempt, above 0 (default 10)
     * @param int                                      $maxRetries retries after the first attempt, 0 or more (default 2)
     * @param array<string, string>                    $headers    extra headers sent with every request
     * @param GuzzleClientInterface|null               $httpClient your own GuzzleHttp\Client, for a proxy or TLS options; redirects are never followed with it either.
     *                                                             It must use one of Guzzle's own handlers and keep TLS checks on for an https base URL
     * @param LoggerInterface|null                     $logger     receives one debug line per attempt: method, path, status, request id and timing
     *
     * @throws \InvalidArgumentException for a timeout of 0 or less, a negative maxRetries, a base URL that is not https
     *                                   (http is accepted for localhost only), an httpClient that is not a GuzzleHttp\Client
     *                                   with one of Guzzle's own handlers, or one that turns off TLS checks
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $model = null,
        float $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        array $headers = [],
        ?GuzzleClientInterface $httpClient = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        if (!is_finite($timeout) || $timeout <= 0.0) {
            throw new \InvalidArgumentException(sprintf('timeout must be a number of seconds above 0, got %s.', var_export($timeout, true)));
        }
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException(sprintf('maxRetries must be 0 or more, got %d.', $maxRetries));
        }

        $this->apiKey = self::nonEmpty($apiKey) ?? self::env('DECISIONNODE_API_KEY');
        $this->baseUrl = self::checkBaseUrl(rtrim(self::nonEmpty($baseUrl) ?? self::env('DECISIONNODE_BASE_URL') ?? self::DEFAULT_BASE_URL, '/'));
        $this->model = self::nonEmpty($model) ?? self::env('DECISIONNODE_MODEL') ?? self::DEFAULT_MODEL;
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->headers = $headers;

        $this->transport = new GuzzleTransport(
            $httpClient ?? new GuzzleClient(),
            strtolower((string) parse_url($this->baseUrl, PHP_URL_SCHEME)) === 'https',
        );
    }

    /**
     * The base URL must use https, so the API key never travels unencrypted.
     * Plain http is accepted only for this machine (localhost, 127.0.0.1, ::1).
     */
    private static function checkBaseUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        if ($host === '' || ($scheme !== 'https' && $scheme !== 'http')) {
            throw new \InvalidArgumentException(sprintf('baseUrl must be an https URL, got "%s".', $url));
        }
        if ($scheme === 'http' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'baseUrl must use https; plain http is accepted only for localhost, 127.0.0.1 and ::1, got "%s".',
                $url,
            ));
        }

        return $url;
    }

    // ---------------------------------------------------------------- decide

    /**
     * Asks every question about `state` in one `POST /v1/decide`.
     *
     * @param mixed                                 $state     a string or any JSON value; omitted means ""
     * @param iterable<array-key, Question|array<string, mixed>|object>|object $questions by question id
     * @param iterable<Image|array<string, mixed>>|null $images up to 4
     * @param string|null                           $model     overrides the client's model for this call
     */
    public function decide(mixed $state = '', iterable|object $questions = [], ?iterable $images = null, ?string $model = null): Decision
    {
        return $this->decideAsync($state, $questions, $images, $model)->wait();
    }

    /**
     * @param iterable<array-key, Question|array<string, mixed>|object>|object $questions
     * @param iterable<Image|array<string, mixed>>|null $images
     *
     * @return PromiseInterface resolves with a Decision
     *
     * @throws \InvalidArgumentException for an empty question map or a value that cannot be encoded as JSON, before anything is sent
     */
    public function decideAsync(mixed $state = '', iterable|object $questions = [], ?iterable $images = null, ?string $model = null): PromiseInterface
    {
        return $this->send($this->body($state, $questions, $images, $model));
    }

    private function send(string $body): PromiseInterface
    {
        return $this->request('POST', '/v1/decide', $body)->then(
            static fn (array $result): Decision => self::decision($result),
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function decision(array $result): Decision
    {
        /** @var array<string, string> $headers */
        $headers = $result['headers'];

        return Decision::fromResponse(
            self::object($result['body'], $result),
            $headers,
            Json::decodeObjects((string) $result['text']),
        );
    }

    /**
     * Runs many decisions, at most `$concurrency` at a time (default 8). Returns
     * one result per request, in input order: a Decision, or the
     * DecisionNodeError that request ended with. One failure does not stop the
     * others.
     *
     * Each request is an array with the keys of {@see decide()}: `state`,
     * `questions`, `images` and `model`.
     *
     * @param iterable<array<string, mixed>|object> $requests
     *
     * @return list<DecisionResult>
     *
     * @throws \InvalidArgumentException when `$concurrency` is below 1 or a request has an argument
     *                                   problem (see {@see decide()}), before anything is sent
     */
    public function decideMany(iterable $requests, int $concurrency = self::DEFAULT_CONCURRENCY): array
    {
        return $this->decideManyAsync($requests, $concurrency)->wait();
    }

    /**
     * @param iterable<array<string, mixed>|object> $requests
     *
     * @return PromiseInterface resolves with list<DecisionResult>, in input order
     *
     * @throws \InvalidArgumentException when `$concurrency` is below 1 or a request has an argument
     *                                   problem, before anything is sent
     */
    public function decideManyAsync(iterable $requests, int $concurrency = self::DEFAULT_CONCURRENCY): PromiseInterface
    {
        if ($concurrency < 1) {
            throw new \InvalidArgumentException(sprintf('concurrency must be 1 or more, got %d.', $concurrency));
        }
        // Every body is built first, so an argument problem in any request
        // is raised before anything is sent.
        $bodies = [];
        foreach ($requests as $request) {
            $args = is_array($request) ? $request : get_object_vars($request);
            $bodies[] = $this->body(
                $args['state'] ?? '',
                $args['questions'] ?? [],
                $args['images'] ?? null,
                $args['model'] ?? null,
            );
        }
        if ($bodies === []) {
            return Create::promiseFor([]);
        }

        $results = array_fill(0, count($bodies), null);
        $promises = (function () use ($bodies): \Generator {
            foreach ($bodies as $index => $body) {
                yield $index => $this->send($body);
            }
        })();

        return Each::ofLimit(
            $promises,
            $concurrency,
            static function (Decision $decision, int $index) use (&$results): void {
                $results[$index] = $decision;
            },
            static function (mixed $reason, int $index) use (&$results): void {
                if (!$reason instanceof DecisionNodeError) {
                    throw $reason instanceof \Throwable ? $reason : new DecisionNodeError('The request failed.');
                }
                $results[$index] = $reason;
            },
        )->then(static function () use (&$results): array {
            return $results;
        });
    }

    // ---------------------------------------------------------------- one-question helpers

    /**
     * One `truth` question, sent under the id "check". An answer that is missing
     * or has missing or wrongly typed fields is a ServerError.
     *
     * @param iterable<Image|array<string, mixed>>|null $images
     */
    public function check(mixed $state = '', mixed $instructions = null, mixed $ifTrue = null, mixed $ifFalse = null, ?iterable $images = null, ?string $model = null): TruthAnswer
    {
        return $this->checkAsync($state, $instructions, $ifTrue, $ifFalse, $images, $model)->wait();
    }

    /**
     * @param iterable<Image|array<string, mixed>>|null $images
     *
     * @return PromiseInterface resolves with a TruthAnswer; rejects with a ServerError when the answer is missing or malformed
     */
    public function checkAsync(mixed $state = '', mixed $instructions = null, mixed $ifTrue = null, mixed $ifFalse = null, ?iterable $images = null, ?string $model = null): PromiseInterface
    {
        return $this->single('check', Question::truth($instructions, $ifTrue, $ifFalse), TruthAnswer::class, $state, $images, $model);
    }

    /**
     * One `choice` question, sent under the id "classify". An answer that is
     * missing or has missing or wrongly typed fields is a ServerError.
     *
     * @param iterable<array-key, mixed>|object $options names to descriptions, or a list of names
     * @param iterable<Image|array<string, mixed>>|null $images
     */
    public function classify(mixed $state = '', mixed $instructions = null, iterable|object $options = [], ?iterable $images = null, ?string $model = null): ChoiceAnswer
    {
        return $this->classifyAsync($state, $instructions, $options, $images, $model)->wait();
    }

    /**
     * @param iterable<array-key, mixed>|object $options
     * @param iterable<Image|array<string, mixed>>|null $images
     *
     * @return PromiseInterface resolves with a ChoiceAnswer; rejects with a ServerError when the answer is missing or malformed
     */
    public function classifyAsync(mixed $state = '', mixed $instructions = null, iterable|object $options = [], ?iterable $images = null, ?string $model = null): PromiseInterface
    {
        return $this->single('classify', Question::choice($instructions, $options), ChoiceAnswer::class, $state, $images, $model);
    }

    /**
     * One `score` question, sent under the id "score". An answer that is missing
     * or has missing or wrongly typed fields is a ServerError.
     *
     * @param iterable<mixed> $levels low to high
     * @param iterable<Image|array<string, mixed>>|null $images
     */
    public function score(mixed $state = '', mixed $instructions = null, iterable $levels = [], ?iterable $images = null, ?string $model = null): ScoreAnswer
    {
        return $this->scoreAsync($state, $instructions, $levels, $images, $model)->wait();
    }

    /**
     * @param iterable<mixed> $levels
     * @param iterable<Image|array<string, mixed>>|null $images
     *
     * @return PromiseInterface resolves with a ScoreAnswer; rejects with a ServerError when the answer is missing or malformed
     */
    public function scoreAsync(mixed $state = '', mixed $instructions = null, iterable $levels = [], ?iterable $images = null, ?string $model = null): PromiseInterface
    {
        return $this->single('score', Question::score($instructions, $levels), ScoreAnswer::class, $state, $images, $model);
    }

    /**
     * Asks whether an action should run: a `risk` score (none to severe), a
     * `touches_production` truth, and a `verdict` choice of allow, confirm or
     * block. The state sent is `{"action": ..., "context": ...}`.
     *
     * @param iterable<Image|array<string, mixed>>|null $images
     */
    public function gate(mixed $action, mixed $context = null, ?iterable $images = null, ?string $model = null): Decision
    {
        return $this->gateAsync($action, $context, $images, $model)->wait();
    }

    /**
     * @param iterable<Image|array<string, mixed>>|null $images
     *
     * @return PromiseInterface resolves with a Decision
     */
    public function gateAsync(mixed $action, mixed $context = null, ?iterable $images = null, ?string $model = null): PromiseInterface
    {
        $state = ['action' => $action];
        if ($context !== null) {
            $state['context'] = $context;
        }

        return $this->decideAsync($state, self::gateQuestions(), $images, $model);
    }

    /**
     * The three questions `gate` asks.
     *
     * @return array<string, Question>
     */
    public static function gateQuestions(): array
    {
        return [
            'risk' => Question::score(
                'How much harm could this action cause if it is wrong?',
                ['none', 'low', 'moderate', 'high', 'severe'],
            ),
            'touches_production' => Question::truth(
                'Does this action change a production system, production data or anything customers use?',
            ),
            'verdict' => Question::choice(
                'Should this action run as it is, wait for a person to confirm it, or be blocked?',
                [
                    'allow' => 'safe to run without asking',
                    'confirm' => 'a person should confirm it first',
                    'block' => 'should not run',
                ],
            ),
        ];
    }

    // ---------------------------------------------------------------- models and raw

    /**
     * The models the API serves, with their published limits (`GET /v1/models`).
     *
     * @return list<ModelInfo>
     */
    public function models(): array
    {
        return $this->modelsAsync()->wait();
    }

    /**
     * @return PromiseInterface resolves with list<ModelInfo>
     */
    public function modelsAsync(): PromiseInterface
    {
        return $this->request('GET', '/v1/models', null)->then(static function (array $result): array {
            $body = self::object($result['body'], $result);
            $models = [];
            foreach (is_array($body['models'] ?? null) ? $body['models'] : [] as $entry) {
                if (is_array($entry)) {
                    $models[] = ModelInfo::fromArray($entry);
                }
            }

            return $models;
        });
    }

    /**
     * Sends `$body` to `POST /v1/decide` exactly as given and returns the
     * parsed response body. The way to use an API field this SDK version does
     * not know yet.
     *
     * `$body` is an array, an object, or a JSON string sent byte for byte.
     * Use an object (`new \stdClass()`, `(object) []`) where the body needs an
     * empty JSON object, since PHP encodes an empty array as `[]`.
     *
     * @param array<array-key, mixed>|object|string $body
     */
    public function decideRaw(array|object|string $body): mixed
    {
        return $this->decideRawAsync($body)->wait();
    }

    /**
     * @param array<array-key, mixed>|object|string $body
     *
     * @return PromiseInterface resolves with the parsed response body
     */
    public function decideRawAsync(array|object|string $body): PromiseInterface
    {
        $json = is_string($body) ? $body : Json::encode($body);

        return $this->request('POST', '/v1/decide', $json)->then(static fn (array $result): mixed => $result['body']);
    }

    // ---------------------------------------------------------------- internals

    /**
     * @param iterable<array-key, mixed>|object $questions
     * @param iterable<mixed>|null              $images
     */
    private function body(mixed $state, iterable|object $questions, ?iterable $images, ?string $model): string
    {
        $wire = [];
        $iterable = is_iterable($questions) ? $questions : get_object_vars($questions);
        foreach ($iterable as $id => $question) {
            $wire[(string) $id] = $question instanceof Question ? $question->toArray() : $question;
        }
        if ($wire === []) {
            throw new \InvalidArgumentException('questions must hold at least one question.');
        }

        $body = [
            'model' => self::nonEmpty($model) ?? $this->model,
            'state' => $state ?? '',
            'questions' => (object) $wire,
        ];

        if ($images !== null) {
            $list = [];
            foreach ($images as $image) {
                $list[] = $image instanceof Image ? $image->toArray() : $image;
            }
            if ($list !== []) {
                $body['images'] = $list;
            }
        }

        return Json::encode($body);
    }

    /**
     * Sends one question and returns its answer as `$class`. The helper
     * promises a typed answer, so an answer that is missing, of another type
     * or with missing or wrongly typed fields is a ServerError carrying the
     * response's status and `x-decisionnode-error` reason. decide() returns
     * such an answer as an UnknownAnswer instead.
     *
     * @param class-string<Answer> $class
     * @param iterable<mixed>|null $images
     */
    private function single(string $id, Question $question, string $class, mixed $state, ?iterable $images, ?string $model): PromiseInterface
    {
        $body = $this->body($state, [$id => $question], $images, $model);

        return $this->request('POST', '/v1/decide', $body)->then(
            static function (array $result) use ($id, $class): Answer {
                $decision = self::decision($result);
                $answer = $decision->answers[$id] ?? null;
                if (!$answer instanceof $class) {
                    /** @var array<string, string> $headers */
                    $headers = $result['headers'];
                    throw new ServerError(
                        $answer === null
                            ? sprintf('The response has no answer for "%s".', $id)
                            : sprintf('The answer for "%s" is not a well-formed %s.', $id, substr((string) strrchr('\\' . $class, '\\'), 1)),
                        status: is_int($result['status']) ? $result['status'] : null,
                        requestId: $headers['x-request-id'] ?? null,
                        reason: $headers['x-decisionnode-error'] ?? null,
                        body: $decision->raw,
                        headers: $headers,
                    );
                }

                return $answer;
            },
        );
    }

    /**
     * Sends with retries.
     *
     * @return PromiseInterface resolves with ["status" => int, "headers" => array<string, string>, "body" => mixed, "text" => string]
     */
    private function request(string $method, string $path, ?string $json): PromiseInterface
    {
        if ($this->apiKey === null) {
            return Create::rejectionFor(new AuthenticationError(
                'No API key: pass apiKey to the client or set DECISIONNODE_API_KEY.',
            ));
        }

        $attempt = function (int $n, ?float $wait) use (&$attempt, $method, $path, $json): PromiseInterface {
            $request = $this->buildRequest($method, $path, $json);
            $sending = $this->transport->send($request, $wait, $this->timeout);
            $started = microtime(true) + ($wait ?? 0.0);

            return $sending->then(
                function (ResponseInterface $response) use (&$attempt, $n, $method, $path, $started): mixed {
                    $status = $response->getStatusCode();
                    $headers = self::headers($response);
                    $this->log($method, $path, $n, $status, $headers['x-request-id'] ?? null, $started);

                    if ($status >= 200 && $status < 300) {
                        $text = (string) $response->getBody();
                        try {
                            $body = Json::decode($text);
                        } catch (\JsonException $e) {
                            throw new ServerError(
                                sprintf('HTTP %d: the response body is not JSON.', $status),
                                status: $status,
                                requestId: $headers['x-request-id'] ?? null,
                                reason: $headers['x-decisionnode-error'] ?? null,
                                body: $text,
                                headers: $headers,
                                previous: $e,
                            );
                        }

                        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'text' => $text];
                    }

                    if (Retry::retryable($status) && $n < $this->maxRetries) {
                        return $attempt($n + 1, Retry::after($headers) ?? Retry::backoff($n));
                    }

                    throw Errors::fromResponse($status, $headers, (string) $response->getBody());
                },
                function (mixed $reason) use (&$attempt, $n, $method, $path, $started): mixed {
                    if ($reason instanceof ConnectionError || $reason instanceof TimeoutError) {
                        $this->log($method, $path, $n, null, null, $started, $reason->getMessage());
                        if ($n < $this->maxRetries) {
                            return $attempt($n + 1, Retry::backoff($n));
                        }
                    }

                    throw $reason instanceof \Throwable ? $reason : new ConnectionError('The request failed.');
                },
            );
        };

        return $attempt(0, null);
    }

    private function buildRequest(string $method, string $path, ?string $json): Request
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'User-Agent' => 'decisionnode-php/' . self::VERSION,
            'Accept' => 'application/json',
        ];
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $request = new Request($method, $this->baseUrl . $path, $headers, $json);
        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader((string) $name, $value);
        }

        return $request;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<array-key, mixed>
     */
    private static function object(mixed $body, array $result): array
    {
        if (!is_array($body)) {
            $status = is_int($result['status'] ?? null) ? $result['status'] : 0;
            /** @var array<string, string> $headers */
            $headers = is_array($result['headers'] ?? null) ? $result['headers'] : [];
            throw new ServerError(
                sprintf('HTTP %d: the response body is not a JSON object.', $status),
                status: $status,
                requestId: $headers['x-request-id'] ?? null,
                body: $body,
                headers: $headers,
            );
        }

        return $body;
    }

    private function log(string $method, string $path, int $attempt, ?int $status, ?string $requestId, float $started, ?string $error = null): void
    {
        if ($this->logger === null) {
            return;
        }
        $ms = (int) round(max(0.0, microtime(true) - $started) * 1000);
        $this->logger->debug(
            sprintf(
                'decisionnode: %s %s %s request_id=%s %d ms attempt=%d',
                $method,
                $path,
                $status === null ? 'no response (' . ($error ?? 'error') . ')' : (string) $status,
                $requestId ?? '-',
                $ms,
                $attempt + 1,
            ),
            ['method' => $method, 'path' => $path, 'status' => $status, 'request_id' => $requestId, 'duration_ms' => $ms, 'attempt' => $attempt + 1],
        );
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function nonEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
