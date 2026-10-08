<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\ServerError;
use Bynn\DecisionNode\Exception\SessionLimitError;
use Bynn\DecisionNode\Exception\TimeoutError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\Internal\Api;
use Bynn\DecisionNode\Internal\GuzzleTransport;
use Bynn\DecisionNode\Internal\Images;
use Bynn\DecisionNode\Internal\Json;
use Bynn\DecisionNode\Internal\Prepare;
use Bynn\DecisionNode\Internal\Retry;
use Bynn\DecisionNode\Internal\Supervisor;
use Bynn\DecisionNode\Internal\UploadCache;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Each;
use GuzzleHttp\Promise\PromiseInterface;
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
 * A client can be shared and reuses its connections. Every HTTP method has an
 * `...Async` form that returns a promise; `->wait()` on it gives the result.
 * Sessions are blocking.
 *
 * Requests are checked before anything is sent: an image or a question the
 * API would refuse raises the API's own error (BadRequestError, or
 * ValidationError for a number grid fault) without a round trip.
 */
final class DecisionNode
{
    public const VERSION = '0.1.0';

    /** The version of the DecisionNode SDK specification this client implements. */
    public const SPEC_VERSION = '1.1';

    public const DEFAULT_BASE_URL = 'https://api.decisionnode.com';

    public const DEFAULT_MODEL = 'decisionnode-latest';

    public const DEFAULT_TIMEOUT = 10.0;

    public const DEFAULT_MAX_RETRIES = 2;

    public const DEFAULT_CONCURRENCY = 8;

    /**
     * Local images of one request (or session frame, or batch line) under
     * this many bytes in all are sent inline as base64; at or above it they
     * are uploaded once and sent by upload id.
     */
    public const DEFAULT_INLINE_THRESHOLD = 524_288;

    /** The shortest poll interval `runBatch` takes, in seconds. */
    public const MIN_POLL_INTERVAL = 10.0;

    public const DEFAULT_POLL_INTERVAL = 60.0;

    /** How long `runBatch` waits for a batch to end by default: 26 hours. */
    public const DEFAULT_BATCH_TIMEOUT = 93_600.0;

    private const STALE_UPLOAD = ['upload_expired', 'upload_consumed', 'upload_not_found', 'upload_incomplete'];

    public readonly string $baseUrl;

    public readonly string $model;

    public readonly float $timeout;

    public readonly int $maxRetries;

    public readonly int $inlineThreshold;

    /** The Batch API. */
    public readonly Batches $batches;

    private readonly Api $api;

    private readonly Images $images;

    /**
     * Every argument is optional.
     *
     * @param string|null                              $apiKey          default: DECISIONNODE_API_KEY
     * @param string|null                              $baseUrl         default: DECISIONNODE_BASE_URL, else https://api.decisionnode.com
     * @param string|null                              $model           default: DECISIONNODE_MODEL, else decisionnode-latest
     * @param float                                    $timeout         seconds for a whole attempt, above 0 (default 10)
     * @param int                                      $maxRetries      retries after the first attempt, 0 or more (default 2)
     * @param array<string, string>                    $headers         extra headers sent with every API request and session handshake
     *                                                                  (never with an upload's storage PUT)
     * @param GuzzleClientInterface|null               $httpClient      your own GuzzleHttp\Client, for a proxy or TLS options; redirects are never followed with it either.
     *                                                                  It must use one of Guzzle's own handlers and keep TLS checks on for an https base URL
     * @param LoggerInterface|null                     $logger          receives one debug line per attempt: method, path, status, request id and timing
     * @param int|null                                 $inlineThreshold bytes; default DEFAULT_INLINE_THRESHOLD (524,288)
     *
     * @throws \InvalidArgumentException for a timeout of 0 or less, a negative maxRetries or inlineThreshold, a base URL
     *                                   that is not https (http is accepted for localhost only), an httpClient that is not a
     *                                   GuzzleHttp\Client with one of Guzzle's own handlers, or one that turns off TLS checks
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $model = null,
        float $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        array $headers = [],
        ?GuzzleClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
        ?int $inlineThreshold = null,
    ) {
        if (!is_finite($timeout) || $timeout <= 0.0) {
            throw new \InvalidArgumentException(sprintf('timeout must be a number of seconds above 0, got %s.', var_export($timeout, true)));
        }
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException(sprintf('maxRetries must be 0 or more, got %d.', $maxRetries));
        }
        if ($inlineThreshold !== null && $inlineThreshold < 0) {
            throw new \InvalidArgumentException(sprintf('inlineThreshold must be 0 or more, got %d.', $inlineThreshold));
        }

        $apiKey = self::nonEmpty($apiKey) ?? self::env('DECISIONNODE_API_KEY');
        $this->baseUrl = self::checkBaseUrl(rtrim(self::nonEmpty($baseUrl) ?? self::env('DECISIONNODE_BASE_URL') ?? self::DEFAULT_BASE_URL, '/'));
        $this->model = self::nonEmpty($model) ?? self::env('DECISIONNODE_MODEL') ?? self::DEFAULT_MODEL;
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->inlineThreshold = $inlineThreshold ?? self::DEFAULT_INLINE_THRESHOLD;

        $transport = new GuzzleTransport(
            $httpClient ?? new GuzzleClient(),
            strtolower((string) parse_url($this->baseUrl, PHP_URL_SCHEME)) === 'https',
        );
        $this->api = new Api($this->baseUrl, $apiKey, $headers, $timeout, $maxRetries, $transport, 'decisionnode-php/' . self::VERSION, $logger);
        $this->images = new Images($this->api, new UploadCache(), $this->inlineThreshold);
        $this->batches = new Batches($this->api, $this->images, $this->model);
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
     *
     * @throws \InvalidArgumentException for an empty question map or a value that cannot be encoded as JSON
     * @throws BadRequestError|ValidationError for a question or image the API would refuse, before anything is sent
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
     * @throws BadRequestError|ValidationError for a question or image the API would refuse, before anything is sent
     */
    public function decideAsync(mixed $state = '', iterable|object $questions = [], ?iterable $images = null, ?string $model = null): PromiseInterface
    {
        return $this->call('/v1/decide', $state, Prepare::questions($questions), Prepare::images($images), $model)
            ->then(static fn (array $result): Decision => self::decision($result));
    }

    /**
     * Asks the questions on `POST /v1/systemone`, the path that takes only the
     * truth, choice and score types and always names a model.
     *
     * @param iterable<array-key, Question|array<string, mixed>|object>|object $questions by question id
     * @param iterable<Image|array<string, mixed>>|null $images up to 4
     */
    public function systemone(mixed $state = '', iterable|object $questions = [], ?iterable $images = null, ?string $model = null): Decision
    {
        return $this->systemoneAsync($state, $questions, $images, $model)->wait();
    }

    /**
     * @param iterable<array-key, Question|array<string, mixed>|object>|object $questions
     * @param iterable<Image|array<string, mixed>>|null $images
     *
     * @return PromiseInterface resolves with a Decision
     */
    public function systemoneAsync(mixed $state = '', iterable|object $questions = [], ?iterable $images = null, ?string $model = null): PromiseInterface
    {
        return $this->call('/v1/systemone', $state, Prepare::questions($questions, strict: true), Prepare::images($images), $model)
            ->then(static fn (array $result): Decision => self::decision($result));
    }

    /**
     * Sends one decision body: the images inline or uploaded first, then the
     * request. When the API reports that an upload the client placed is gone
     * (expired, consumed, not found, incomplete), the client uploads again and
     * sends once more; a second such answer is raised.
     *
     * @param array<string, mixed> $questions wire forms, checked
     * @param list<mixed>          $images    checked
     */
    private function call(string $path, mixed $state, array $questions, array $images, ?string $model, bool $again = false): PromiseInterface
    {
        $model = self::nonEmpty($model) ?? $this->model;

        return $this->images->wires($images)->then(
            function (array $prepared) use ($path, $state, $questions, $images, $model, $again): PromiseInterface {
                [$wires, $placed] = $prepared;
                $body = Prepare::body($model, $state, $questions, $wires);

                return $this->api->request('POST', $path, $body)->otherwise(
                    function (mixed $reason) use ($path, $state, $questions, $images, $model, $again, $placed): PromiseInterface {
                        if (!$again && $placed !== [] && $reason instanceof BadRequestError && in_array($reason->errorType, self::STALE_UPLOAD, true)) {
                            $this->images->forget($placed);

                            return $this->call($path, $state, $questions, $images, $model, true);
                        }
                        throw $reason instanceof \Throwable ? $reason : new DecisionNodeError('The request failed.');
                    },
                );
            },
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
     * others. Uploaded images are shared through the client's upload cache.
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
     * @throws BadRequestError|ValidationError when any request holds a question or image the API would
     *                                         refuse, before anything is sent
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
     * @throws \InvalidArgumentException|BadRequestError|ValidationError before anything is sent
     */
    public function decideManyAsync(iterable $requests, int $concurrency = self::DEFAULT_CONCURRENCY): PromiseInterface
    {
        if ($concurrency < 1) {
            throw new \InvalidArgumentException(sprintf('concurrency must be 1 or more, got %d.', $concurrency));
        }
        // Every request is checked first, so a problem in any request is
        // raised before anything is sent.
        $prepared = [];
        foreach ($requests as $request) {
            $args = is_array($request) ? $request : get_object_vars($request);
            $model = $args['model'] ?? null;
            $prepared[] = [
                $args['state'] ?? '',
                Prepare::questions($args['questions'] ?? []),
                Prepare::images($args['images'] ?? null),
                is_string($model) ? $model : null,
            ];
        }
        if ($prepared === []) {
            return Create::promiseFor([]);
        }

        $results = array_fill(0, count($prepared), null);
        // Images are prepared as each request starts, so a later request
        // reuses an upload an earlier one made.
        $promises = (function () use ($prepared): \Generator {
            foreach ($prepared as $index => [$state, $questions, $images, $model]) {
                yield $index => $this->call('/v1/decide', $state, $questions, $images, $model)
                    ->then(static fn (array $result): Decision => self::decision($result));
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
     * One `number` question, sent under the id "number". `$min`, `$max` and
     * `$step` left out are left out of the request (the server's defaults are
     * 0, 255 and 1). An answer that is missing or malformed is a ServerError.
     *
     * @param iterable<Image|array<string, mixed>>|null $images
     */
    public function number(mixed $state = '', mixed $instructions = null, int|float|null $min = null, int|float|null $max = null, int|float|null $step = null, ?iterable $images = null, ?string $model = null): NumberAnswer
    {
        return $this->numberAsync($state, $instructions, $min, $max, $step, $images, $model)->wait();
    }

    /**
     * @param iterable<Image|array<string, mixed>>|null $images
     *
     * @return PromiseInterface resolves with a NumberAnswer; rejects with a ServerError when the answer is missing or malformed
     */
    public function numberAsync(mixed $state = '', mixed $instructions = null, int|float|null $min = null, int|float|null $max = null, int|float|null $step = null, ?iterable $images = null, ?string $model = null): PromiseInterface
    {
        return $this->single('number', Question::number($instructions, $min, $max, $step), NumberAnswer::class, $state, $images, $model);
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

    // ---------------------------------------------------------------- models, uploads and raw

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
        return $this->api->request('GET', '/v1/models')->then(static function (array $result): array {
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
     * Uploads local images whatever their size (`POST /v1/uploads`, then one
     * PUT each) and returns the upload id of each, in order. An image this
     * client uploaded before is not uploaded again. Name an upload with
     * `Image::fromUploadId()` within its hour, by any key of the workspace.
     *
     * @param iterable<Image> $images up to 4 local images
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException for an image that is not local bytes
     * @throws BadRequestError for an image the API would refuse, before anything is sent
     */
    public function upload(iterable $images): array
    {
        return $this->uploadAsync($images)->wait();
    }

    /**
     * @param iterable<Image> $images
     *
     * @return PromiseInterface resolves with list<string>
     */
    public function uploadAsync(iterable $images): PromiseInterface
    {
        $list = Prepare::images($images);
        foreach ($list as $image) {
            if (!$image instanceof Image || !$image->isLocal()) {
                throw new \InvalidArgumentException('upload takes images made from a path, bytes, base64 or a stream.');
            }
        }
        /** @var list<Image> $list */
        return $this->images->upload($list);
    }

    /**
     * Sends `$body` to `POST /v1/decide` exactly as given and returns the
     * parsed response body. The way to use an API field this SDK version does
     * not know yet. Nothing is checked or uploaded.
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

        return $this->api->request('POST', '/v1/decide', $json)->then(static fn (array $result): mixed => $result['body']);
    }

    // ---------------------------------------------------------------- sessions

    /**
     * Opens a session (`POST /v1/sessions`): the state and questions are read
     * once, and every frame sent on the session's socket is answered against
     * them. A 429 `session_limit_exceeded` is never retried and raises
     * SessionLimitError.
     *
     * @param iterable<array-key, Question|array<string, mixed>|object>|object $questions by question id
     *
     * @throws \InvalidArgumentException|BadRequestError|ValidationError before anything is sent
     * @throws SessionLimitError when the key holds as many open sessions as it may
     */
    public function openSession(
        iterable|object $questions = [],
        mixed $state = null,
        mixed $instructions = null,
        ?string $model = null,
        ?int $window = null,
        ?int $ttlSeconds = null,
    ): Session {
        return $this->openSessionAsync($questions, $state, $instructions, $model, $window, $ttlSeconds)->wait();
    }

    /**
     * @param iterable<array-key, Question|array<string, mixed>|object>|object $questions
     * @param float|null $delay seconds to wait before sending
     *
     * @return PromiseInterface resolves with a Session (its socket is opened with Session::connect())
     */
    public function openSessionAsync(
        iterable|object $questions = [],
        mixed $state = null,
        mixed $instructions = null,
        ?string $model = null,
        ?int $window = null,
        ?int $ttlSeconds = null,
        ?float $delay = null,
    ): PromiseInterface {
        $body = ['model' => self::nonEmpty($model) ?? $this->model];
        if ($instructions !== null) {
            $body['instructions'] = $instructions;
        }
        if ($state !== null) {
            $body['state'] = $state;
        }
        $body['questions'] = (object) Prepare::questions($questions);
        if ($window !== null) {
            $body['window'] = $window;
        }
        if ($ttlSeconds !== null) {
            $body['ttl_seconds'] = $ttlSeconds;
        }

        return $this->api->request('POST', '/v1/sessions', Json::encode($body), Api::RETRY_SESSION_OPEN, delay: $delay)->then(
            fn (array $result): Session => new Session(self::object($result['body'], $result), $this->api, $this->images, Json::decodeObjects((string) $result['text'])),
        );
    }

    /**
     * Keeps a session running over a long feed of readings: each reading is
     * sent as a frame as soon as it arrives (the latest wins), every Reply and
     * FrameError goes to `$onReply`, and the session is opened again when the
     * server closes it with a code that asks for that (at once when its time
     * is up or it went idle, after a capped backoff of 1 to 30 s with jitter
     * otherwise). On 1013 it connects to the same session again.
     *
     * When the feed ends it waits for the outstanding replies, ends the
     * session and returns the last SessionClosed seen (or null). On a code
     * that is final it stops and returns that SessionClosed.
     *
     * @param array<string, mixed>|object                      $body        the arguments of openSession(): questions, state,
     *                                                                     instructions, model, window, ttl_seconds
     * @param iterable<array<string, mixed>|object>            $feed        readings, each the arguments of Session::send()
     * @param callable(Reply|FrameError): void                 $onReply
     * @param int                                              $maxSessions the most sessions opened in all
     */
    public function supervise(array|object $body, iterable $feed, callable $onReply, int $maxSessions = 10): ?SessionClosed
    {
        $args = is_array($body) ? $body : get_object_vars($body);
        $open = function (?float $delay) use ($args): Session {
            $session = $this->openSessionAsync(
                questions: $args['questions'] ?? [],
                state: $args['state'] ?? null,
                instructions: $args['instructions'] ?? null,
                model: isset($args['model']) && is_string($args['model']) ? $args['model'] : null,
                window: isset($args['window']) && is_int($args['window']) ? $args['window'] : null,
                ttlSeconds: isset($args['ttl_seconds']) && is_int($args['ttl_seconds']) ? $args['ttl_seconds'] : null,
                delay: $delay,
            )->wait();

            return $session->connect();
        };

        $supervisor = new Supervisor(
            $open,
            \Closure::fromCallable($onReply),
            static function (float $seconds): void {
                usleep((int) ($seconds * 1_000_000));
            },
            $maxSessions,
            $this->timeout,
        );

        return $supervisor->run($feed);
    }

    // ---------------------------------------------------------------- batches

    /**
     * Runs a whole batch: an empty draft, the requests added in calls of at
     * most 10 MiB, finalize, then a poll every `$pollInterval` seconds (or the
     * server's Retry-After when longer) until the batch ends, then its
     * results. If adding fails, the draft is cancelled and the error raised.
     *
     * @param iterable<BatchRequest|array<string, mixed>|object> $requests
     * @param float $pollInterval seconds between polls, at least 10 (default 60)
     * @param float $timeout      seconds to wait for the batch to end (default 26 hours)
     *
     * @return array{0: Batch, 1: list<BatchResult>} the ended batch and its results
     *
     * @throws \InvalidArgumentException for a poll interval under 10 s, before anything is sent
     * @throws TimeoutError when the batch has not ended within `$timeout`
     */
    public function runBatch(iterable $requests, float $pollInterval = self::DEFAULT_POLL_INTERVAL, float $timeout = self::DEFAULT_BATCH_TIMEOUT): array
    {
        return $this->runBatchAsync($requests, $pollInterval, $timeout)->wait();
    }

    /**
     * @param iterable<BatchRequest|array<string, mixed>|object> $requests
     *
     * @return PromiseInterface resolves with [Batch, list<BatchResult>]
     */
    public function runBatchAsync(iterable $requests, float $pollInterval = self::DEFAULT_POLL_INTERVAL, float $timeout = self::DEFAULT_BATCH_TIMEOUT): PromiseInterface
    {
        if (!is_finite($pollInterval) || $pollInterval < self::MIN_POLL_INTERVAL) {
            throw new \InvalidArgumentException(sprintf('pollInterval must be at least %d seconds, got %s.', (int) self::MIN_POLL_INTERVAL, var_export($pollInterval, true)));
        }
        if (!is_finite($timeout) || $timeout <= 0.0) {
            throw new \InvalidArgumentException(sprintf('timeout must be a number of seconds above 0, got %s.', var_export($timeout, true)));
        }
        $list = [];
        foreach ($requests as $request) {
            $list[] = $request;
        }
        // Every request is checked (and its large images uploaded) before the draft is made.
        $lines = $list === [] ? Create::promiseFor([]) : $this->batches->lines($list);
        $started = microtime(true);

        return $lines->then(
            fn (array $prepared): PromiseInterface => $this->batches->createAsync([])->then(
                fn (Batch $draft): PromiseInterface => $this->addAndFinalize($draft, $prepared),
            ),
        )->then(fn (Batch $batch): PromiseInterface => $this->pollBatch($batch, [], $pollInterval, $timeout, $started, 0.0));
    }

    /**
     * Adds the prepared lines to the draft and finalizes it; when adding
     * fails, the draft is cancelled and the add's error raised.
     *
     * @param array<string> $lines
     *
     * @return PromiseInterface resolves with the finalized Batch
     */
    private function addAndFinalize(Batch $draft, array $lines): PromiseInterface
    {
        $id = (string) $draft->id;
        if ($lines === []) {
            return $this->batches->finalizeAsync($id);
        }

        return $this->batches->addLines($id, $lines)->then(
            fn (): PromiseInterface => $this->batches->finalizeAsync($id),
            function (mixed $reason) use ($id): PromiseInterface {
                $raise = static function () use ($reason): never {
                    throw $reason instanceof \Throwable ? $reason : new DecisionNodeError('The request failed.');
                };

                return $this->batches->cancelAsync($id)->then($raise, $raise);
            },
        );
    }

    /**
     * Reads the batch every poll interval (or the server's Retry-After when
     * longer) until it ends, then its results.
     *
     * @param array<string, string> $headers the last answer's headers
     *
     * @return PromiseInterface resolves with [Batch, list<BatchResult>]
     */
    private function pollBatch(Batch $batch, array $headers, float $pollInterval, float $timeout, float $started, float $waited): PromiseInterface
    {
        $id = (string) $batch->id;
        if ($batch->ended()) {
            return $this->batches->resultsAsync($id)->then(static fn (array $results): array => [$batch, $results]);
        }
        if (max($waited, microtime(true) - $started) >= $timeout) {
            throw new TimeoutError(sprintf('The batch %s did not end within %s s.', $id, rtrim(rtrim(sprintf('%.3f', $timeout), '0'), '.')));
        }
        $wait = max($pollInterval, Retry::after($headers, null) ?? 0.0);

        return $this->batches->poll($id, $wait)->then(
            fn (array $next): PromiseInterface => $this->pollBatch($next[0], $next[1], $pollInterval, $timeout, $started, $waited + $wait),
        );
    }

    // ---------------------------------------------------------------- internals

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
        return $this->call('/v1/decide', $state, Prepare::questions([$id => $question]), Prepare::images($images), $model)->then(
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
