<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\ServerError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\Internal\Api;
use Bynn\DecisionNode\Internal\Images;
use Bynn\DecisionNode\Internal\Json;
use Bynn\DecisionNode\Internal\LocalChecks;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * The Collections API: the workspace's collections of faces, which the
 * `face_recognition` booster searches, and the public ones it may search.
 * Reach it as `$dn->collections`.
 *
 *     $staff = $dn->collections->create('Staff, Stockholm office');
 *     $dn->collections->enroll($staff->id, Image::fromPath('badge', 'emp-1042.jpg'), reference: 'emp-1042', label: 'Reception');
 *
 * Every result holds the object's fields as the API sent them and `raw`.
 * Create and enroll send an Idempotency-Key, a new UUID for each call and
 * the same on every attempt, so the server creates the collection or
 * enrolls and bills the face once however often the call is tried: they are
 * retried like every API call, and on a 409 idempotency_in_progress after
 * its wait. A 409 idempotency_key_reused is raised. The other calls carry
 * no key and are retried like every API call.
 */
final class Collections
{
    /** An argument left out, told apart from null. */
    private const UNSET = "\0unset";

    private const STALE_UPLOAD = ['upload_expired', 'upload_consumed', 'upload_not_found', 'upload_incomplete'];

    /**
     * @internal
     */
    public function __construct(
        private readonly Api $api,
        private readonly Images $images,
    ) {
    }

    /**
     * `POST /v1/collections`: a new collection. The type is `face`, the only
     * one; the name 1 to 80 characters. A type or a name the API would
     * refuse is refused before anything is sent, with its entries.
     *
     * @throws ValidationError|DecisionNodeError
     */
    public function create(string $name, string $type = 'face'): Collection
    {
        return $this->createAsync($name, $type)->wait();
    }

    /**
     * @return PromiseInterface resolves with a Collection
     */
    public function createAsync(string $name, string $type = 'face'): PromiseInterface
    {
        LocalChecks::collection($type, $name);

        return $this->request('POST', '/v1/collections', Json::encode(['type' => $type, 'name' => $name]), Api::RETRY_IDEMPOTENT, Api::idempotencyKey())->then(
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
     * in it at once. A public collection cannot be deleted: a
     * PermissionDeniedError (`collection_read_only`).
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
     * @param array<string, mixed>|null $metadata
     *
     * @throws ValidationError|DecisionNodeError
     */
    public function updateItem(string $collectionId, string $itemId, ?string $reference = null, mixed $label = self::UNSET, ?array $metadata = null): CollectionItem
    {
        return $this->updateItemAsync($collectionId, $itemId, $reference, $label, $metadata)->wait();
    }

    /**
     * @param array<string, mixed>|null $metadata
     *
     * @return PromiseInterface resolves with a CollectionItem
     */
    public function updateItemAsync(string $collectionId, string $itemId, ?string $reference = null, mixed $label = self::UNSET, ?array $metadata = null): PromiseInterface
    {
        $hasLabel = $label !== self::UNSET;
        if ($hasLabel && $label !== null && !is_string($label)) {
            throw new \InvalidArgumentException('label is a string, or null to remove it');
        }
        LocalChecks::itemUpdate($reference !== null, $reference, $hasLabel, $label, $metadata !== null, $metadata);
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
     * @throws BadRequestError for a query out of bounds, before anything is sent
     */
    public function listItems(string $collectionId, ?int $offset = null, ?int $limit = null, ?string $reference = null, ?string $referencePrefix = null): CollectionItemPage
    {
        return $this->listItemsAsync($collectionId, $offset, $limit, $reference, $referencePrefix)->wait();
    }

    /**
     * @return PromiseInterface resolves with a CollectionItemPage
     */
    public function listItemsAsync(string $collectionId, ?int $offset = null, ?int $limit = null, ?string $reference = null, ?string $referencePrefix = null): PromiseInterface
    {
        LocalChecks::listQuery($offset, $limit);
        LocalChecks::referenceQuery($reference, $referencePrefix);
        $params = array_filter(['offset' => $offset, 'limit' => $limit, 'reference' => $reference, 'reference_prefix' => $referencePrefix], static fn (int|string|null $v): bool => $v !== null);
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
