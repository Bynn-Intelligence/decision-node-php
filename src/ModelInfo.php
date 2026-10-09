<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One entry of `GET /v1/models`: the model id, its description, its release
 * date and its published limits (every other field of the entry, such as the
 * input token limit).
 *
 * The workspace's fine-tuned models are listed beside the catalogue's, named
 * `<base>@<workspace>/<name>` (with `:<n>` for one version): `fineTuned` is
 * true, `base` names the model it was trained from, `adapterVersion` is its
 * version number and `status` is `deployed` (it answers decisions), `ready`
 * (trained, not deployed) or any other value as the API sent it. A catalogue
 * entry has all four null. `version` is the
 * pinned name the entry resolves to, when the entry states it.
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
        public readonly ?string $version = null,
        public readonly ?bool $fineTuned = null,
        public readonly ?string $base = null,
        public readonly ?int $adapterVersion = null,
        public readonly ?string $status = null,
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
            version: is_string($raw['version'] ?? null) ? $raw['version'] : null,
            fineTuned: is_bool($raw['fine_tuned'] ?? null) ? $raw['fine_tuned'] : null,
            base: is_string($raw['base'] ?? null) ? $raw['base'] : null,
            adapterVersion: is_int($raw['adapter_version'] ?? null) ? $raw['adapter_version'] : null,
            status: is_string($raw['status'] ?? null) ? $raw['status'] : null,
        );
    }
}
