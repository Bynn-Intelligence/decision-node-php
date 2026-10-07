<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One entry of `GET /v1/models`: the model id, its description, its release
 * date and its published limits (every other field of the entry, such as the
 * input token limit).
 */
final class ModelInfo
{
    /**
     * @param array<string, mixed>    $limits
     * @param array<array-key, mixed> $raw    the entry exactly as the API sent it
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $description,
        public readonly ?string $releaseDate,
        public readonly array $limits,
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
        $limits = $raw;
        unset($limits['name'], $limits['description'], $limits['release_date']);

        return new self(
            name: is_string($raw['name'] ?? null) ? $raw['name'] : null,
            description: is_string($raw['description'] ?? null) ? $raw['description'] : null,
            releaseDate: is_string($raw['release_date'] ?? null) ? $raw['release_date'] : null,
            limits: $limits,
            raw: $raw,
        );
    }
}
