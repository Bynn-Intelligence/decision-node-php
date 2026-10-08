<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Internal\Errors;
use Bynn\DecisionNode\Internal\Json;

/**
 * One line of a batch's results, in the order the requests were added.
 *
 * `status` is the HTTP status the request would have had on its own, or the
 * string `expired` or `cancelled` for a request that never ran. A 200 line
 * holds its `decision`, read exactly as `decide` reads a response; any other
 * status holds its `error`, the error class and fields `decide` would have
 * raised, as a value (never thrown).
 */
final class BatchResult
{
    /**
     * @param array<string, string>   $headers names in lower case
     * @param array<array-key, mixed> $raw     the line exactly as the API sent it
     */
    public function __construct(
        public readonly ?string $customId,
        public readonly int|string|null $status,
        public readonly array $headers,
        public readonly ?Decision $decision,
        public readonly ?DecisionNodeError $error,
        public readonly array $raw,
    ) {
    }

    /**
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function fromArray(array $raw, mixed $shape = null): self
    {
        $headers = [];
        foreach (is_array($raw['headers'] ?? null) ? $raw['headers'] : [] as $name => $value) {
            if (is_scalar($value)) {
                $headers[strtolower((string) $name)] = (string) $value;
            }
        }
        $status = $raw['status'] ?? null;
        $status = is_int($status) || is_string($status) ? $status : null;
        $body = $raw['body'] ?? null;
        $decision = null;
        $error = null;
        if ($status === 200 && is_array($body)) {
            $bodyShape = $shape instanceof \stdClass ? ($shape->body ?? null) : null;
            $decision = Decision::fromResponse($body, $headers, $bodyShape);
        } elseif (is_int($status)) {
            $error = Errors::fromResponse($status, $headers, Json::encode($body));
        }

        return new self(
            customId: is_scalar($raw['custom_id'] ?? null) ? (string) $raw['custom_id'] : null,
            status: $status,
            headers: $headers,
            decision: $decision,
            error: $error,
            raw: $raw,
        );
    }
}
