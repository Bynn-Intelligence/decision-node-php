<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\ServerError;
use Bynn\DecisionNode\Exception\TimeoutError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\Internal\Api;
use Bynn\DecisionNode\Internal\Documents;
use Bynn\DecisionNode\Internal\Images;
use Bynn\DecisionNode\Internal\Json;
use Bynn\DecisionNode\Internal\LocalChecks;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * The Collections API: the workspace's collections of faces, which the
 * `face_recognition` booster searches, and the public ones it may search;
 * and its knowledge bases, collections of documents (type `text`), which
 * the `knowledge` booster searches. Reach it as `$dn->collections`.
 *
 *     $staff = $dn->collections->create('Staff, Stockholm office');
 *     $dn->collections->enroll($staff->id, Image::fromPath('badge', 'emp-1042.jpg'), reference: 'emp-1042', label: 'Reception');
 *
 *     $kb = $dn->collections->create('Claims policy', type: 'text');
 *     $doc = $dn->collections->addDocument($kb->id, Document::fromPath('claims-2026.pdf'));
 *     $dn->collections->waitUntilReady($kb->id, $doc->id);
 *
 * Every result holds the object's fields as the API sent them and `raw`.
 * Create and enroll send an Idempotency-Key, a new UUID for each call and
 * the same on every attempt, so the server creates the collection or
 * enrolls and bills the face once however often the call is tried: they are
 * retried like every API call, and on a 409 idempotency_in_progress after
 * its wait. A 409 idempotency_key_reused is raised. Adding, replacing and
 * changing a document carry one too. The other calls carry no key and are
 * retried like every API call.
 */
final class Collections
{
    /** An argument left out, told apart from null. */
    private const UNSET = "\0unset";

    private const STALE_UPLOAD = ['upload_expired', 'upload_consumed', 'upload_not_found', 'upload_incomplete'];

    /**
     * @internal
     */
    /** Seconds per attempt of a document call with `wait`, when the client was given no timeout. */
    public const WAIT_TIMEOUT = 20.0;

    /** How long `waitUntilReady` waits by default, in seconds. */
    public const DEFAULT_READY_TIMEOUT = 300.0;

    /** `waitUntilReady`'s first interval between reads, in seconds. */
    public const DEFAULT_READY_POLL_INTERVAL = 1.0;

    /** The shortest poll interval `waitUntilReady` takes, in seconds. */
    public const MIN_READY_POLL_INTERVAL = 0.1;

    /** The longest interval `waitUntilReady` grows to, in seconds. */
    private const MAX_READY_POLL_INTERVAL = 10.0;

    /**
     * @internal
     *
     * @param bool $timeoutSet whether the client was given a timeout, which then holds for a call with `wait` too
     */
    public function __construct(
        private readonly Api $api,
        private readonly Images $images,
        private readonly ?Documents $documents = null,
        private readonly bool $timeoutSet = false,
    ) {
    }

    /**
     * `POST /v1/collections`: a new collection. The type is `face` (the
     * default) or `text`, a knowledge base; the name 1 to 80 characters.
     * `$scopeKey`, sent only when given and taken only with type `text`, is
     * the metadata key (1 to 40 of `[a-z0-9_]`) whose value scopes a
     * document to, say, one mission; it is fixed for the collection's life.
     * A type, a name or a scope key the API would refuse is refused before
     * anything is sent, with its entries.
     *
     * @throws ValidationError|DecisionNodeError
     */
    public function create(string $name, string $type = 'face', ?string $scopeKey = null): Collection
    {
        return $this->createAsync($name, $type, $scopeKey)->wait();
    }

    /**
     * @return PromiseInterface resolves with a Collection
     */
    public function createAsync(string $name, string $type = 'face', ?string $scopeKey = null): PromiseInterface
    {
        LocalChecks::collection($type, $name, $scopeKey);
        $body = ['type' => $type, 'name' => $name];
        if ($scopeKey !== null) {
            $body['scope_key'] = $scopeKey;
        }

        return $this->request('POST', '/v1/collections', Json::encode($body), Api::RETRY_IDEMPOTENT, Api::idempotencyKey())->then(
            static fn (array $body): Collection => Collection::fromArray($body),
        );
    }

    /**
     * `GET /v1/collections`: the workspace's own collections, newest first,
     * then the public ones it may search.
     *
     * @return list<Collection>
     */
    public function list(): array
    {
        return $this->listAsync()->wait();
    }

    /**
     * @return PromiseInterface resolves with list<Collection>
     */
    public function listAsync(): PromiseInterface
    {
        return $this->api->request('GET', '/v1/collections')->then(
            static function (array $result): array {
                $body = self::object($result);
                if (!is_array($body['collections'] ?? null) || !array_is_list($body['collections'])) {
                    throw self::invalid($result, 'the response holds no list of collections');
                }
                $list = [];
                foreach ($body['collections'] as $collection) {
                    $list[] = Collection::fromArray(is_array($collection) ? $collection : []);
                }

                return $list;
            },
        );
    }

    /**
     * `GET /v1/collections/{id}`: one collection. An id that is neither the
     * workspace's own nor a public one it may search is a NotFoundError
     * (`collection_not_found`).
     */
    public function get(string $collectionId): Collection
    {
        return $this->getAsync($collectionId)->wait();
    }

    /**
     * @return PromiseInterface resolves with a Collection
     */
    public function getAsync(string $collectionId): PromiseInterface
    {
        return $this->request('GET', self::path($collectionId))->then(
            static fn (array $body): Collection => Collection::fromArray($body),
        );
    }

    /**
     * `DELETE /v1/collections/{id}`: deletes the collection and every item
     * in it at once. A knowledge base purged within 10 s answers `deleted`
     * true, else `status` `purging`, the purge finishing in the background;
     * both are success, and the client does not wait for a purge. A public
     * collection cannot be deleted: a PermissionDeniedError
     * (`collection_read_only`).
     */
    public function delete(string $collectionId): Deleted
    {
        return $this->deleteAsync($collectionId)->wait();
    }

    /**
     * @return PromiseInterface resolves with a Deleted
     */
    public function deleteAsync(string $collectionId): PromiseInterface
    {
        return $this->request('DELETE', self::path($collectionId))->then(
            static fn (array $body): Deleted => Deleted::fromArray($body),
        );
    }

    /**
     * `POST /v1/collections/{id}/items`: enrolls the largest face in the
     * image. The image is checked and sent as a decision's image is (a URL as
     * given, local bytes inline or uploaded first); the reference is 1 to 64
     * of `[A-Za-z0-9_.:-]`, the label, sent only when given, 1 to 128
     * characters, and the metadata, sent only when given, at most 20 keys of
     * 1 to 40 of `[a-z0-9_]`, each value a text of at most 500 characters, a
     * number, or true or false; each is refused before anything is sent. An
     * image with no face is the API's ValidationError with the entry
     * `no_face`.
     *
     * @param Image|array<string, mixed>|null $image
     * @param array<string, mixed>|null       $metadata
     *
     * @throws \InvalidArgumentException|BadRequestError|ValidationError|DecisionNodeError
     */
    public function enroll(string $collectionId, Image|array|null $image, string $reference, ?string $label = null, ?array $metadata = null): CollectionItem
    {
        return $this->enrollAsync($collectionId, $image, $reference, $label, $metadata)->wait();
    }

    /**
     * @param Image|array<string, mixed>|null $image
     * @param array<string, mixed>|null       $metadata
     *
     * @return PromiseInterface resolves with a CollectionItem
     */
    public function enrollAsync(string $collectionId, Image|array|null $image, string $reference, ?string $label = null, ?array $metadata = null): PromiseInterface
    {
        LocalChecks::enrollment($image, $reference, $label, $metadata);

        return $this->enrollPlaced(self::path($collectionId) . '/items', $image ?? [], $reference, $label, $metadata, false);
    }

    /**
     * `PATCH /v1/collections/{id}/items/{item_id}`: changes an item, sending
     * only the fields given. A label given as null removes it; metadata
     * replaces the item's whole metadata ([] removes every key). They are
     * checked as an enrollment's, and at least one must be given. The call
     * carries an Idempotency-Key, as create and enroll do, and is retried as
     * they are.
     *
     * A document also takes `$pinned`, `$pinRank` (0 to 1,000) and `$title`
     * (1 to 200 characters): every change but the title applies at once,
     * never re-embedded or billed; a new title makes a new version (`202`).
     *
     * @param array<string, mixed>|null $metadata
     *
     * @throws ValidationError|DecisionNodeError
     */
    public function updateItem(string $collectionId, string $itemId, ?string $reference = null, mixed $label = self::UNSET, ?array $metadata = null, ?bool $pinned = null, ?int $pinRank = null, ?string $title = null): CollectionItem
    {
        return $this->updateItemAsync($collectionId, $itemId, $reference, $label, $metadata, $pinned, $pinRank, $title)->wait();
    }

    /**
     * @param array<string, mixed>|null $metadata
     *
     * @return PromiseInterface resolves with a CollectionItem
     */
    public function updateItemAsync(string $collectionId, string $itemId, ?string $reference = null, mixed $label = self::UNSET, ?array $metadata = null, ?bool $pinned = null, ?int $pinRank = null, ?string $title = null): PromiseInterface
    {
        $hasLabel = $label !== self::UNSET;
        if ($hasLabel && $label !== null && !is_string($label)) {
            throw new \InvalidArgumentException('label is a string, or null to remove it');
        }
        LocalChecks::itemUpdate($reference !== null, $reference, $hasLabel, $label, $metadata !== null, $metadata, $pinned !== null, $pinRank, $title);
        $body = [];
        if ($reference !== null) {
            $body['reference'] = $reference;
        }
        if ($hasLabel) {
            $body['label'] = $label;
        }
        if ($metadata !== null) {
            $body['metadata'] = self::metadataObject($metadata);
        }
        if ($pinned !== null) {
            $body['pinned'] = $pinned;
        }
        if ($pinRank !== null) {
            $body['pin_rank'] = $pinRank;
        }
        if ($title !== null) {
            $body['title'] = $title;
        }
        $path = self::path($collectionId) . '/items/' . rawurlencode($itemId);

        return $this->request('PATCH', $path, Json::encode($body), Api::RETRY_IDEMPOTENT, Api::idempotencyKey())->then(
            static fn (array $item): CollectionItem => CollectionItem::fromArray($item),
        );
    }

    /**
     * Places the image and posts the enrollment. When the API reports that
     * an upload the client placed is gone, it uploads again and sends once
     * more, as a decision does; a second such answer is raised.
     *
     * @param Image|array<string, mixed> $image checked
     * @param array<string, mixed>|null $metadata checked
     */
    private function enrollPlaced(string $path, Image|array $image, string $reference, ?string $label, ?array $metadata, bool $again): PromiseInterface
    {
        return $this->images->wires([$image])->then(
            function (array $prepared) use ($path, $image, $reference, $label, $metadata, $again): PromiseInterface {
                [$wires, $placed] = $prepared;
                $body = ['image' => $wires[0], 'reference' => $reference];
                if ($label !== null) {
                    $body['label'] = $label;
                }
                if ($metadata !== null) {
                    $body['metadata'] = self::metadataObject($metadata);
                }
                $encoded = Json::encode($body);
                LocalChecks::bodySize(strlen($encoded));

                // A resend after a stale upload is another body, so it has its own key.
                return $this->api->request('POST', $path, $encoded, Api::RETRY_IDEMPOTENT, idempotencyKey: Api::idempotencyKey())->then(
                    static fn (array $result): CollectionItem => CollectionItem::fromArray(self::object($result)),
                    function (mixed $reason) use ($path, $image, $reference, $label, $metadata, $again, $placed): PromiseInterface {
                        if (!$again && $placed !== [] && $reason instanceof BadRequestError && in_array($reason->errorType, self::STALE_UPLOAD, true)) {
                            $this->images->forget($placed);

                            return $this->enrollPlaced($path, $image, $reference, $label, $metadata, true);
                        }
                        throw $reason instanceof \Throwable ? $reason : new DecisionNodeError('The request failed.');
                    },
                );
            },
        );
    }

    /**
     * `GET /v1/collections/{id}/items`: one page of the collection's items,
     * newest first. `offset` is 0 or more and `limit` 1 to 100, each sent only
     * when given. `reference` lists the items with exactly that reference,
     * and `referencePrefix` those whose reference starts with it; one or the
     * other, each 1 to 64 of `[A-Za-z0-9_.:-]`. A public collection's items
     * are not listed: a PermissionDeniedError (`collection_read_only`).
     *
     * A knowledge base's documents also take `q`, 1 to 200 characters that
     * a document's title or reference contains, and `sort`, `newest` (the
     * default), `oldest` or `title`; each sent only when given.
     *
     * @throws BadRequestError for a query out of bounds, before anything is sent
     */
    public function listItems(string $collectionId, ?int $offset = null, ?int $limit = null, ?string $reference = null, ?string $referencePrefix = null, ?string $q = null, ?string $sort = null): CollectionItemPage
    {
        return $this->listItemsAsync($collectionId, $offset, $limit, $reference, $referencePrefix, $q, $sort)->wait();
    }

    /**
     * @return PromiseInterface resolves with a CollectionItemPage
     */
    public function listItemsAsync(string $collectionId, ?int $offset = null, ?int $limit = null, ?string $reference = null, ?string $referencePrefix = null, ?string $q = null, ?string $sort = null): PromiseInterface
    {
        LocalChecks::listQuery($offset, $limit);
        LocalChecks::referenceQuery($reference, $referencePrefix);
        LocalChecks::itemListQuery($q, $sort);
        $params = array_filter(['offset' => $offset, 'limit' => $limit, 'reference' => $reference, 'reference_prefix' => $referencePrefix, 'q' => $q, 'sort' => $sort], static fn (int|string|null $v): bool => $v !== null);
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return $this->request('GET', self::path($collectionId) . '/items' . ($query !== '' ? '?' . $query : ''))->then(
            static fn (array $body): CollectionItemPage => CollectionItemPage::fromArray($body),
        );
    }

    /**
     * `DELETE /v1/collections/{id}/items/{item_id}`: deletes one item at
     * once. An id not in the collection is a NotFoundError (`item_not_found`).
     */
    public function deleteItem(string $collectionId, string $itemId): Deleted
    {
        return $this->deleteItemAsync($collectionId, $itemId)->wait();
    }

    /**
     * @return PromiseInterface resolves with a Deleted
     */
    public function deleteItemAsync(string $collectionId, string $itemId): PromiseInterface
    {
        return $this->request('DELETE', self::path($collectionId) . '/items/' . rawurlencode($itemId))->then(
            static fn (array $body): Deleted => Deleted::fromArray($body),
        );
    }

    /**
     * `POST /v1/collections/{id}/items`: adds a document to a knowledge base.
     * Text and Markdown in memory, and a small UTF-8 text or Markdown file,
     * are sent inline; every other file is uploaded first (one presign with
     * the knowledge purpose, one PUT) and named by its upload id. The title
     * is the one given, else a file's name without its extension; text in
     * memory needs one. `$reference`, `$pinned`, `$pinRank`, `$label` and
     * `$metadata` are sent only when given; on a scoped knowledge base the
     * scope key's value in `$metadata` scopes the document, absent it is
     * global.
     *
     * Ingestion is asynchronous: the document comes back `queued`. With
     * `$wait` the server holds the call up to 10 s and answers once it is
     * ready, else as it stands (20 s per attempt unless the client was given
     * a timeout); `waitUntilReady()` waits longer. The call carries an
     * Idempotency-Key, the same on every attempt. When an upload the call
     * made is reported gone, the file is uploaded and the call sent once
     * more, with a new key.
     *
     * @param array<string, mixed>|null $metadata
     *
     * @throws ValidationError|BadRequestError|DecisionNodeError
     */
    public function addDocument(string $collectionId, Document $document, ?string $title = null, ?string $reference = null, ?bool $pinned = null, ?int $pinRank = null, ?string $label = null, ?array $metadata = null, bool $wait = false): CollectionItem
    {
        return $this->addDocumentAsync($collectionId, $document, $title, $reference, $pinned, $pinRank, $label, $metadata, $wait)->wait();
    }

    /**
     * @param array<string, mixed>|null $metadata
     *
     * @return PromiseInterface resolves with a CollectionItem
     */
    public function addDocumentAsync(string $collectionId, Document $document, ?string $title = null, ?string $reference = null, ?bool $pinned = null, ?int $pinRank = null, ?string $label = null, ?array $metadata = null, bool $wait = false): PromiseInterface
    {
        return $this->document('POST', self::path($collectionId) . '/items', $document, $title, $reference, $pinned, $pinRank, $label, $metadata, $wait);
    }

    /**
     * `PUT /v1/collections/{id}/items/{item_id}`: replaces a document whole,
     * sent as `addDocument()` sends it. A new text, format or title is a new
     * version, the old one serving until the new one is ready; the same text
     * again changes nothing and is free. A document made from a file is
     * replaced by a file, one written as text by text.
     *
     * @param array<string, mixed>|null $metadata
     *
     * @throws ValidationError|BadRequestError|DecisionNodeError
     */
    public function replaceDocument(string $collectionId, string $itemId, Document $document, ?string $title = null, ?string $reference = null, ?bool $pinned = null, ?int $pinRank = null, ?string $label = null, ?array $metadata = null, bool $wait = false): CollectionItem
    {
        return $this->replaceDocumentAsync($collectionId, $itemId, $document, $title, $reference, $pinned, $pinRank, $label, $metadata, $wait)->wait();
    }

    /**
     * @param array<string, mixed>|null $metadata
     *
     * @return PromiseInterface resolves with a CollectionItem
     */
    public function replaceDocumentAsync(string $collectionId, string $itemId, Document $document, ?string $title = null, ?string $reference = null, ?bool $pinned = null, ?int $pinRank = null, ?string $label = null, ?array $metadata = null, bool $wait = false): PromiseInterface
    {
        return $this->document('PUT', self::itemPath($collectionId, $itemId), $document, $title, $reference, $pinned, $pinRank, $label, $metadata, $wait);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function document(string $method, string $path, Document $document, ?string $title, ?string $reference, ?bool $pinned, ?int $pinRank, ?string $label, ?array $metadata, bool $wait): PromiseInterface
    {
        $plan = $this->documents()->plan($document, $title, $reference, $pinRank, $label, $metadata);
        $fields = [];
        foreach (['reference' => $reference, 'pinned' => $pinned, 'pin_rank' => $pinRank, 'label' => $label] as $key => $value) {
            if ($value !== null) {
                $fields[$key] = $value;
            }
        }
        if ($metadata !== null) {
            $fields['metadata'] = self::metadataObject($metadata);
        }

        return $this->sendDocument($method, $path . ($wait ? '?wait=true' : ''), $plan, $fields, $wait, false);
    }

    /**
     * Uploads the document's file when it has one, then sends the item call.
     * A stale upload the call placed is uploaded again once.
     *
     * @param array{body: array<string, mixed>, file: array{bytes: string, content_type: string, filename: string|null}|null} $plan
     * @param array<string, mixed> $fields
     */
    private function sendDocument(string $method, string $path, array $plan, array $fields, bool $wait, bool $again): PromiseInterface
    {
        $file = $plan['file'];
        $upload = $file !== null
            ? $this->documents()->upload([$file])->then(static fn (array $ids): string => $ids[0])
            : Create::promiseFor(null);

        return $upload->then(function (?string $uploadId) use ($method, $path, $plan, $fields, $wait, $again): PromiseInterface {
            $body = $plan['body'];
            if ($uploadId !== null) {
                $body['upload_id'] = $uploadId;
            }

            // A resend after a stale upload is another body, so it has its own key.
            return $this->api->request(
                $method,
                $path,
                Json::encode($body + $fields),
                Api::RETRY_IDEMPOTENT,
                timeout: $wait && !$this->timeoutSet ? self::WAIT_TIMEOUT : null,
                idempotencyKey: Api::idempotencyKey(),
            )->then(
                static fn (array $result): CollectionItem => CollectionItem::fromArray(self::object($result)),
                function (mixed $reason) use ($method, $path, $plan, $fields, $wait, $again, $uploadId): PromiseInterface {
                    if (!$again && $uploadId !== null && $reason instanceof BadRequestError && in_array($reason->errorType, self::STALE_UPLOAD, true)) {
                        return $this->sendDocument($method, $path, $plan, $fields, $wait, true);
                    }
                    throw $reason instanceof \Throwable ? $reason : new DecisionNodeError('The request failed.');
                },
            );
        });
    }

    /**
     * `DELETE /v1/collections/{id}/items?status=failed`: deletes every
     * failed document of a knowledge base at once and answers how many.
     * A failed document stays listed with its reason until it is deleted,
     * counting toward nothing.
     */
    public function deleteFailed(string $collectionId): ItemsDeleted
    {
        return $this->deleteFailedAsync($collectionId)->wait();
    }

    /**
     * @return PromiseInterface resolves with an ItemsDeleted
     */
    public function deleteFailedAsync(string $collectionId): PromiseInterface
    {
        return $this->request('DELETE', self::path($collectionId) . '/items?status=failed')->then(
            static fn (array $body): ItemsDeleted => ItemsDeleted::fromArray($body),
        );
    }

    /**
     * `GET /v1/collections/{id}/items/{item_id}`: one item. With
     * `$includeText` a document carries `text`, its stored text (null while
     * no version is ready), which the byte offsets of its chunks and
     * passages index as UTF-8.
     */
    public function getItem(string $collectionId, string $itemId, bool $includeText = false): CollectionItem
    {
        return $this->getItemAsync($collectionId, $itemId, $includeText)->wait();
    }

    /**
     * @return PromiseInterface resolves with a CollectionItem
     */
    public function getItemAsync(string $collectionId, string $itemId, bool $includeText = false): PromiseInterface
    {
        return $this->request('GET', self::itemPath($collectionId, $itemId) . ($includeText ? '?include=text' : ''))->then(
            static fn (array $body): CollectionItem => CollectionItem::fromArray($body),
        );
    }

    /**
     * `GET /v1/collections/{id}/items/{item_id}/chunks`: one page of a
     * document's live chunks, in its order. `offset` is 0 or more and
     * `limit` 1 to 100, each sent only when given.
     *
     * @throws BadRequestError for a query out of bounds, before anything is sent
     */
    public function listChunks(string $collectionId, string $itemId, ?int $offset = null, ?int $limit = null): ChunkPage
    {
        return $this->listChunksAsync($collectionId, $itemId, $offset, $limit)->wait();
    }

    /**
     * @return PromiseInterface resolves with a ChunkPage
     */
    public function listChunksAsync(string $collectionId, string $itemId, ?int $offset = null, ?int $limit = null): PromiseInterface
    {
        LocalChecks::listQuery($offset, $limit);
        $query = http_build_query(array_filter(['offset' => $offset, 'limit' => $limit], static fn (?int $v): bool => $v !== null), '', '&', PHP_QUERY_RFC3986);

        return $this->request('GET', self::itemPath($collectionId, $itemId) . '/chunks' . ($query !== '' ? '?' . $query : ''))->then(
            static fn (array $body): ChunkPage => ChunkPage::fromArray($body),
        );
    }

    /**
     * Waits until a document's ingestion has ended, reading it until its
     * `status` is `ready` or `failed`, and returns it either way: a failed
     * document is a value, its `error` saying why. Without `$itemId` it
     * waits for the whole knowledge base, until none of its documents is
     * queued or processing, and returns the collection.
     *
     * The first read is made at once; then it waits `$pollInterval` seconds
     * (at least 0.1), half as long again after each read up to 10 s; each
     * read is retried as any call, after the server's Retry-After. The
     * timeout is a deadline on the clock, counted from the call.
     *
     * @throws \InvalidArgumentException for a poll interval under 0.1 s or a timeout not above 0, before anything is sent
     * @throws TimeoutError when it has not ended within `$timeout` seconds
     */
    public function waitUntilReady(string $collectionId, ?string $itemId = null, float $timeout = self::DEFAULT_READY_TIMEOUT, float $pollInterval = self::DEFAULT_READY_POLL_INTERVAL): CollectionItem|Collection
    {
        return $this->waitUntilReadyAsync($collectionId, $itemId, $timeout, $pollInterval)->wait();
    }

    /**
     * @return PromiseInterface resolves with a CollectionItem, or a Collection without `$itemId`
     */
    public function waitUntilReadyAsync(string $collectionId, ?string $itemId = null, float $timeout = self::DEFAULT_READY_TIMEOUT, float $pollInterval = self::DEFAULT_READY_POLL_INTERVAL): PromiseInterface
    {
        if (!is_finite($pollInterval) || $pollInterval < self::MIN_READY_POLL_INTERVAL) {
            throw new \InvalidArgumentException(sprintf('pollInterval must be at least %s seconds, got %s.', self::MIN_READY_POLL_INTERVAL, var_export($pollInterval, true)));
        }
        if (!is_finite($timeout) || $timeout <= 0.0) {
            throw new \InvalidArgumentException(sprintf('timeout must be a number of seconds above 0, got %s.', var_export($timeout, true)));
        }

        return $this->pollReady($collectionId, $itemId, microtime(true) + $timeout, $timeout, $pollInterval, null);
    }

    /**
     * One read, then another after the interval until the document or the
     * knowledge base has settled.
     */
    private function pollReady(string $collectionId, ?string $itemId, float $deadline, float $timeout, float $interval, ?float $delay): PromiseInterface
    {
        $path = $itemId === null ? self::path($collectionId) : self::itemPath($collectionId, $itemId);

        return $this->api->request('GET', $path, delay: $delay)->then(
            function (array $result) use ($collectionId, $itemId, $deadline, $timeout, $interval): mixed {
                $body = self::object($result);
                if ($itemId !== null) {
                    $item = CollectionItem::fromArray($body);
                    if ($item->settled()) {
                        return $item;
                    }
                } else {
                    $collection = Collection::fromArray($body);
                    $counts = $collection->statusCounts ?? [];
                    if (($counts['queued'] ?? 0) === 0 && ($counts['processing'] ?? 0) === 0) {
                        return $collection;
                    }
                }
                $left = $deadline - microtime(true);
                if ($left <= 0.0) {
                    throw new TimeoutError(sprintf(
                        '%s was not ready within %s s.',
                        $itemId === null ? 'The knowledge base ' . $collectionId : 'The document ' . $itemId,
                        rtrim(rtrim(sprintf('%.3f', $timeout), '0'), '.'),
                    ));
                }

                return $this->pollReady($collectionId, $itemId, $deadline, $timeout, min($interval * 1.5, self::MAX_READY_POLL_INTERVAL), min($interval, $left));
            },
        );
    }

    /**
     * `POST /v1/collections/{id}/search`: the passages of a knowledge base
     * that a query finds, without a decision, as a {@see KnowledgeReading}
     * with every passage's text. Each argument but the query is sent only
     * when given, as given (`$match` and `$filter` are JSON values passed
     * through; `$maxTokens` an integer or `"auto"`); the API checks them.
     * Billed as one knowledge call; it needs a full-scope key.
     *
     * @param array<array-key, mixed>|object|null $match
     * @param array<array-key, mixed>|object|null $filter
     *
     * @throws ServerError when the answer is not a well-formed reading
     */
    public function search(string $collectionId, string $query, ?string $scope = null, array|object|null $match = null, array|object|null $filter = null, ?int $topK = null, ?bool $pinned = null, int|string|null $maxTokens = null): KnowledgeReading
    {
        return $this->searchAsync($collectionId, $query, $scope, $match, $filter, $topK, $pinned, $maxTokens)->wait();
    }

    /**
     * @param array<array-key, mixed>|object|null $match
     * @param array<array-key, mixed>|object|null $filter
     *
     * @return PromiseInterface resolves with a KnowledgeReading
     */
    public function searchAsync(string $collectionId, string $query, ?string $scope = null, array|object|null $match = null, array|object|null $filter = null, ?int $topK = null, ?bool $pinned = null, int|string|null $maxTokens = null): PromiseInterface
    {
        $body = ['query' => $query];
        foreach (['scope' => $scope, 'match' => $match, 'filter' => $filter, 'top_k' => $topK, 'pinned' => $pinned, 'max_tokens' => $maxTokens] as $key => $value) {
            if ($value !== null) {
                $body[$key] = $value;
            }
        }

        return $this->api->request('POST', self::path($collectionId) . '/search', Json::encode($body))->then(
            static function (array $result): KnowledgeReading {
                $body = self::object($result);
                if (!KnowledgeReading::wellFormed($body)) {
                    throw self::invalid($result, 'the response is not a well-formed knowledge reading');
                }

                return new KnowledgeReading($body);
            },
        );
    }

    /**
     * Metadata as the API takes it: always a JSON object, so an empty one is
     * sent as {}.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>|\stdClass
     */
    private static function metadataObject(array $metadata): array|\stdClass
    {
        return $metadata === [] ? new \stdClass() : $metadata;
    }

    private static function path(string $collectionId): string
    {
        return '/v1/collections/' . rawurlencode($collectionId);
    }

    private static function itemPath(string $collectionId, string $itemId): string
    {
        return self::path($collectionId) . '/items/' . rawurlencode($itemId);
    }

    private function documents(): Documents
    {
        return $this->documents ?? throw new \LogicException('This client takes no documents.');
    }

    /**
     * @return PromiseInterface resolves with the response body, a JSON object
     */
    private function request(string $method, string $path, ?string $body = null, string $policy = Api::RETRY_DEFAULT, ?string $idempotencyKey = null): PromiseInterface
    {
        return $this->api->request($method, $path, $body, $policy, idempotencyKey: $idempotencyKey)->then(
            static fn (array $result): array => self::object($result),
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
            throw self::invalid($result, 'the response body is not a JSON object');
        }

        return $result['body'];
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function invalid(array $result, string $why): ServerError
    {
        /** @var array<string, string> $headers */
        $headers = $result['headers'];

        return new ServerError(
            sprintf('HTTP %d: %s.', (int) $result['status'], $why),
            status: (int) $result['status'],
            requestId: $headers['x-request-id'] ?? null,
            body: $result['body'],
            headers: $headers,
        );
    }
}
