<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\Exception\ServerError;
use Bynn\DecisionNode\Internal\Api;
use Bynn\DecisionNode\Internal\Held;
use Bynn\DecisionNode\Internal\Images;
use Bynn\DecisionNode\Internal\LocalChecks;
use Bynn\DecisionNode\Internal\Json;
use Bynn\DecisionNode\Internal\Prepare;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * The Batch API: many decisions at half the price, answered within 24 hours.
 * Reach it as `$dn->batches`; `$dn->runBatch()` runs the whole lifecycle.
 *
 *     $batch = $dn->batches->create([
 *         ['custom_id' => 'ticket-1', 'state' => '...', 'questions' => [...]],
 *     ]);
 *     $batch = $dn->batches->finalize($batch->id);
 *     // later
 *     foreach ($dn->batches->results($batch->id) as $line) { ... }
 *
 * Create, add and finalize are retried only on 429 and 529, never after a
 * timeout or a lost answer, since a repeat could create a second draft or
 * repeat custom ids. The other calls are retried like every API call.
 */
final class Batches
{
    /** The most bytes one add sends; a larger list is split into several adds, in order. */
    public const MAX_ADD_BYTES = 10_485_760;

    /**
     * @internal
     */
    public function __construct(
        private readonly Api $api,
        private readonly Images $images,
        private readonly string $model,
        private readonly Held $held,
    ) {
    }

    /**
     * `POST /v1/batches`: a new batch holding `$requests`, or an empty draft.
     *
     * @param iterable<BatchRequest|array<string, mixed>|object> $requests
     *
     * @throws \InvalidArgumentException|DecisionNodeError
     */
    public function create(iterable $requests = []): Batch
    {
        return $this->createAsync($requests)->wait();
    }

    /**
     * @param iterable<BatchRequest|array<string, mixed>|object> $requests
     *
     * @return PromiseInterface resolves with a Batch
     */
    public function createAsync(iterable $requests = []): PromiseInterface
    {
        return $this->lines($requests)->then(
            fn (array $lines): PromiseInterface => $this->send('POST', '/v1/batches', implode('', $lines), Api::RETRY_MUTATION, 'application/x-ndjson'),
        );
    }

    /**
     * `POST /v1/batches/{id}/requests`: adds requests to a draft, in calls of
     * at most 10 MiB each, in order. Returns the batch after the last call.
     *
     * @param iterable<BatchRequest|array<string, mixed>|object> $requests at least one
     *
     * @throws \InvalidArgumentException|DecisionNodeError
     */
    public function add(string $batchId, iterable $requests): Batch
    {
        return $this->addAsync($batchId, $requests)->wait();
    }

    /**
     * @param iterable<BatchRequest|array<string, mixed>|object> $requests
     *
     * @return PromiseInterface resolves with a Batch
     */
    public function addAsync(string $batchId, iterable $requests): PromiseInterface
    {
        return $this->lines($requests, requireOne: true)->then(
            fn (array $lines): PromiseInterface => $this->addLines($batchId, $lines),
        );
    }

    /**
     * Sends prepared lines in calls of at most 10 MiB each, in order.
     *
     * @param array<string> $lines
     *
     * @return PromiseInterface resolves with the Batch after the last call
     *
     * @internal
     */
    public function addLines(string $batchId, array $lines): PromiseInterface
    {
        $chunks = [];
        $chunk = '';
        foreach ($lines as $line) {
            if ($chunk !== '' && strlen($chunk) + strlen($line) > self::MAX_ADD_BYTES) {
                $chunks[] = $chunk;
                $chunk = '';
            }
            $chunk .= $line;
        }
        $chunks[] = $chunk;

        $path = '/v1/batches/' . rawurlencode($batchId) . '/requests';
        $promise = Create::promiseFor(null);
        foreach ($chunks as $body) {
            $promise = $promise->then(fn (): PromiseInterface => $this->send('POST', $path, $body, Api::RETRY_MUTATION, 'application/x-ndjson'));
        }

        return $promise;
    }

    /**
     * `POST /v1/batches/{id}/finalize`: queues the draft. When the API
     * answers that the batch is no longer a draft (an earlier finalize whose
     * answer was lost went through), the batch is read and returned.
     */
    public function finalize(string $batchId): Batch
    {
        return $this->finalizeAsync($batchId)->wait();
    }

    /**
     * @return PromiseInterface resolves with a Batch
     */
    public function finalizeAsync(string $batchId): PromiseInterface
    {
        return $this->send('POST', '/v1/batches/' . rawurlencode($batchId) . '/finalize', null, Api::RETRY_MUTATION)->otherwise(
            function (mixed $reason) use ($batchId): PromiseInterface {
                if (!$reason instanceof DecisionNodeError || $reason->status !== 409 || $reason->errorType !== 'invalid_batch_state') {
                    throw $reason instanceof \Throwable ? $reason : new DecisionNodeError('The request failed.');
                }

                return $this->getAsync($batchId)->then(static function (Batch $batch) use ($reason): Batch {
                    if ($batch->state === 'draft') {
                        throw $reason;
                    }

                    return $batch;
                });
            },
        );
    }

    /**
     * `GET /v1/batches/{id}`.
     */
    public function get(string $batchId): Batch
    {
        return $this->getAsync($batchId)->wait();
    }

    /**
     * @return PromiseInterface resolves with a Batch
     */
    public function getAsync(string $batchId): PromiseInterface
    {
        return $this->send('GET', '/v1/batches/' . rawurlencode($batchId), null);
    }

    /**
     * `GET /v1/batches`: one page of the key's batches, newest first.
     */
    public function list(?int $offset = null, ?int $limit = null): BatchPage
    {
        return $this->listAsync($offset, $limit)->wait();
    }

    /**
     * @return PromiseInterface resolves with a BatchPage
     */
    public function listAsync(?int $offset = null, ?int $limit = null): PromiseInterface
    {
        LocalChecks::listQuery($offset, $limit);
        $query = http_build_query(array_filter(['offset' => $offset, 'limit' => $limit], static fn (?int $v): bool => $v !== null));

        return $this->api->request('GET', '/v1/batches' . ($query !== '' ? '?' . $query : ''))->then(
            static fn (array $result): BatchPage => BatchPage::fromArray(self::object($result)),
        );
    }

    /**
     * `GET /v1/batches/{id}/results`: one BatchResult per request, in the
     * order the requests were added. The whole file is read, line by line,
     * however many lines it holds.
     *
     * @return list<BatchResult>
     */
    public function results(string $batchId): array
    {
        return iterator_to_array($this->stream($batchId), false);
    }

    /**
     * The results one at a time, parsed as each line arrives: the response
     * body is a stream read as it is consumed, so a large results file is
     * never held in memory whole.
     *
     *     foreach ($dn->batches->stream($batch->id) as $line) { ... }
     *
     * @return \Generator<int, BatchResult>
     */
    public function stream(string $batchId): \Generator
    {
        $result = $this->api->request('GET', '/v1/batches/' . rawurlencode($batchId) . '/results', json: false, stream: true)->wait();
        $body = $result['stream'] ?? null;
        if (!$body instanceof StreamInterface) {
            yield from self::parse($result, (string) ($result['text'] ?? ''));

            return;
        }
        $buffer = '';
        $first = true;
        while (!$body->eof()) {
            $chunk = $body->read(65_536);
            if ($chunk === '') {
                continue;
            }
            $buffer .= $chunk;
            if ($first && ltrim($buffer) !== '') {
                $first = false;
                if (str_starts_with(ltrim($buffer), '[')) {
                    // One JSON array (format=json): read it whole.
                    yield from self::parse($result, $buffer . $body->getContents());

                    return;
                }
            }
            while (($end = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + 1);
                yield from self::parse($result, $line);
            }
        }
        yield from self::parse($result, $buffer);
    }

    /**
     * @return PromiseInterface resolves with list<BatchResult>, the whole file
     */
    public function resultsAsync(string $batchId): PromiseInterface
    {
        return $this->api->request('GET', '/v1/batches/' . rawurlencode($batchId) . '/results', json: false)->then(
            static function (array $result): array {
                $out = [];
                $text = (string) $result['text'];
                $lines = str_starts_with(ltrim($text), '[') ? [$text] : explode("\n", $text);
                foreach ($lines as $line) {
                    foreach (self::parse($result, $line) as $parsed) {
                        $out[] = $parsed;
                    }
                }

                return $out;
            },
        );
    }

    /**
     * The results of one line (or of one JSON array).
     *
     * @param array<string, mixed> $result
     *
     * @return list<BatchResult>
     */
    private static function parse(array $result, string $line): array
    {
        if (trim($line) === '') {
            return [];
        }
        try {
            $decoded = Json::decode($line);
        } catch (\JsonException $e) {
            /** @var array<string, string> $headers */
            $headers = $result['headers'];
            throw new ServerError('A results line is not JSON.', status: is_int($result['status']) ? $result['status'] : null, requestId: $headers['x-request-id'] ?? null, body: $line, headers: $headers, previous: $e);
        }
        $shape = Json::decodeObjects($line);
        $entries = is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];
        $shapes = is_array($shape) ? $shape : [$shape];
        $out = [];
        foreach ($entries as $i => $entry) {
            if (is_array($entry)) {
                $out[] = BatchResult::fromArray($entry, $shapes[$i] ?? null);
            }
        }

        return $out;
    }

    /**
     * `POST /v1/batches/{id}/cancel`: a draft is cancelled at once; a running
     * batch winds down and ends `cancelled`.
     */
    public function cancel(string $batchId): Batch
    {
        return $this->cancelAsync($batchId)->wait();
    }

    /**
     * @return PromiseInterface resolves with a Batch
     */
    public function cancelAsync(string $batchId): PromiseInterface
    {
        return $this->send('POST', '/v1/batches/' . rawurlencode($batchId) . '/cancel', null);
    }

    /**
     * Checks every request before anything is sent; the returned promise
     * resolves with the JSON lines, after uploading the lines' large images.
     *
     * @param iterable<BatchRequest|array<string, mixed>|object> $requests
     *
     * @return PromiseInterface resolves with list<string>, each line ending in "\n"
     *
     * @internal
     */
    public function lines(iterable $requests, bool $requireOne = false): PromiseInterface
    {
        $prepared = $this->check($requests, $requireOne);
        $promises = [];
        foreach ($prepared as [$head, $model, $state, $questions, $images, $videos, $tail]) {
            $promises[] = $this->images->wires($images)->then(
                static fn (array $wires): string => Prepare::body($model, $state, $questions, $wires[0], $videos, $head, $tail) . "\n",
            );
        }

        return Utils::all($promises);
    }

    /**
     * Every local check of a create (or, with `$requireOne`, an add): the
     * custom_id rules, then each request as a decision request. Nothing is
     * sent or uploaded.
     *
     * @param iterable<BatchRequest|array<string, mixed>|object> $requests
     *
     * @return list<array{array<string, mixed>, string, mixed, array<string, mixed>, list<mixed>, list<mixed>, array<array-key, mixed>}>
     *
     * @throws \InvalidArgumentException|BadRequestError|ValidationError
     *
     * @internal
     */
    public function check(iterable $requests, bool $requireOne = false): array
    {
        $args = [];
        foreach ($requests as $i => $request) {
            $args[] = self::arguments($request, $i);
        }
        LocalChecks::batchRequests($args, !$requireOne, $this->held->limits($this->model));
        $prepared = [];
        foreach ($args as $request) {
            $prepared[] = $this->prepare($request);
        }

        return $prepared;
    }

    /**
     * A request's arguments as an array, `custom_id` under its wire name.
     *
     * @return array<array-key, mixed>
     */
    private static function arguments(mixed $request, int|string $index): array
    {
        if ($request instanceof BatchRequest) {
            return ['custom_id' => $request->customId, 'state' => $request->state, 'questions' => $request->questions, 'images' => $request->images, 'videos' => $request->videos, 'model' => $request->model];
        }
        if (is_array($request)) {
            $args = $request;
        } elseif (is_object($request)) {
            $args = get_object_vars($request);
        } else {
            throw new \InvalidArgumentException(sprintf('Batch request %s must be a BatchRequest or an array.', (string) $index));
        }
        if (!array_key_exists('custom_id', $args) && array_key_exists('customId', $args)) {
            $args['custom_id'] = $args['customId'];
            unset($args['customId']);
        }

        return $args;
    }

    /**
     * One line's parts, checked as a decision request.
     *
     * @param array<array-key, mixed> $args
     *
     * @return array{array<string, mixed>, string, mixed, array<string, mixed>, list<mixed>, list<mixed>, array<array-key, mixed>}
     */
    private function prepare(array $args): array
    {
        $questions = $args['questions'] ?? [];
        if (!is_iterable($questions) && !is_object($questions)) {
            throw new \InvalidArgumentException(sprintf('Batch request %s: questions must be a map of questions.', Json::encode($args['custom_id'] ?? null)));
        }
        $images = $args['images'] ?? null;
        $videos = $args['videos'] ?? null;
        $model = $args['model'] ?? null;
        $model = is_string($model) && $model !== '' ? $model : $this->model;
        $state = $args['state'] ?? '';
        $questions = Prepare::questions($questions);
        $images = Prepare::images(is_iterable($images) ? $images : null);
        $videos = Prepare::videos(is_iterable($videos) ? $videos : null);
        LocalChecks::decision($model, $state, $questions, $images, $this->held->limits($model), $videos);

        $tail = $args;
        unset($tail['custom_id'], $tail['state'], $tail['questions'], $tail['images'], $tail['videos'], $tail['model']);

        return [['custom_id' => $args['custom_id']], $model, $state, $questions, $images, $videos, $tail];
    }

    /**
     * @return PromiseInterface resolves with a Batch
     */
    private function send(string $method, string $path, ?string $body, string $policy = Api::RETRY_DEFAULT, string $contentType = 'application/json'): PromiseInterface
    {
        return $this->api->request($method, $path, $body, $policy, $contentType)->then(
            static fn (array $result): Batch => Batch::fromArray(self::object($result)),
        );
    }

    /**
     * Reads a batch with its response headers, for `runBatch`'s polling.
     *
     * @return PromiseInterface resolves with [Batch, array<string, string> headers]
     *
     * @internal
     */
    public function poll(string $batchId, ?float $delay): PromiseInterface
    {
        return $this->api->request('GET', '/v1/batches/' . rawurlencode($batchId), delay: $delay)->then(
            static fn (array $result): array => [Batch::fromArray(self::object($result)), $result['headers']],
        );
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<array-key, mixed>
     */
    private static function object(array $result): array
    {
        if (!is_array($result['body'])) {
            /** @var array<string, string> $headers */
            $headers = $result['headers'];
            throw new ServerError(
                sprintf('HTTP %d: the response body is not a JSON object.', (int) $result['status']),
                status: (int) $result['status'],
                requestId: $headers['x-request-id'] ?? null,
                body: $result['body'],
                headers: $headers,
            );
        }

        return $result['body'];
    }
}
