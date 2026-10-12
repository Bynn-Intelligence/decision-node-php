<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * An item of a collection: one enrolled face, known by its `reference` and,
 * when it has one, its `label` (null otherwise). The picture is never kept.
 *
 * The answer to an enrollment also carries `collection`, `facesFound` (how
 * many faces the image held; the largest was enrolled), `box` (the enrolled
 * face's corners in pixels of the image as sent) and `usage` (`enrollments`
 * and `cost`, as sent); a listed item has none of them.
 */
final class CollectionItem
{
    /**
     * @param array{x1: int|float, y1: int|float, x2: int|float, y2: int|float}|null $box
     * @param array<string, mixed>|null $usage
     * @param array<array-key, mixed>   $raw   the item object exactly as the API sent it
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $collection,
        public readonly ?string $reference,
        public readonly ?string $label,
        public readonly ?int $facesFound,
        public readonly ?array $box,
        public readonly ?string $createdAt,
        public readonly ?array $usage,
        public readonly array $raw,
    ) {
    }

    /**
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $string = static fn (string $key): ?string => is_string($raw[$key] ?? null) ? $raw[$key] : null;
        $box = null;
        $sent = $raw['box'] ?? null;
        if (is_array($sent)) {
            $corners = [];
            foreach (['x1', 'y1', 'x2', 'y2'] as $corner) {
                $v = $sent[$corner] ?? null;
                if (!is_int($v) && !is_float($v)) {
                    $corners = null;
                    break;
                }
                $corners[$corner] = $v;
            }
            /** @var array{x1: int|float, y1: int|float, x2: int|float, y2: int|float}|null $corners */
            $box = $corners;
        }

        return new self(
            id: $string('id'),
            collection: $string('collection'),
            reference: $string('reference'),
            label: $string('label'),
            facesFound: is_int($raw['faces_found'] ?? null) ? $raw['faces_found'] : null,
            box: $box,
            createdAt: $string('created_at'),
            usage: is_array($raw['usage'] ?? null) ? $raw['usage'] : null,
            raw: $raw,
        );
    }
}
