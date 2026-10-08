<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

/**
 * The client's uploads by content: the SHA-256 of the bytes and the media
 * type to the upload id and its expiry. In memory only, never shared between
 * clients. An entry is used until 5 minutes before the upload expires; at
 * most 256 entries, the least recently used out first.
 *
 * @internal
 */
final class UploadCache
{
    public const MAX_ENTRIES = 256;

    /** Seconds before `expires_at` at which an entry stops being used. */
    public const MARGIN = 300;

    /** @var array<string, array{string, float}> key => [upload id, expires at (Unix time)], oldest first */
    private array $entries = [];

    /** @var \Closure(): float */
    private \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock the current Unix time; tests may replace it
     */
    public function __construct(?\Closure $clock = null, private readonly int $capacity = self::MAX_ENTRIES)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public static function key(string $bytes, string $mediaType): string
    {
        return hash('sha256', $bytes) . ' ' . $mediaType;
    }

    public function get(string $key): ?string
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        unset($this->entries[$key]);
        if (($this->clock)() >= $entry[1] - self::MARGIN) {
            return null;
        }
        // Most recently used last.
        $this->entries[$key] = $entry;

        return $entry[0];
    }

    public function put(string $key, string $uploadId, float $expiresAt): void
    {
        unset($this->entries[$key]);
        $this->entries[$key] = [$uploadId, $expiresAt];
        while (count($this->entries) > $this->capacity && ($oldest = array_key_first($this->entries)) !== null) {
            unset($this->entries[$oldest]);
        }
    }

    /**
     * Drops the entries of these upload ids.
     *
     * @param list<string> $uploadIds
     */
    public function drop(array $uploadIds): void
    {
        $drop = array_flip($uploadIds);
        foreach ($this->entries as $key => [$uploadId]) {
            if (isset($drop[$uploadId])) {
                unset($this->entries[$key]);
            }
        }
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
