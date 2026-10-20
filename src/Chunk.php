<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One live chunk of a document, as `collections->listChunks()` returns it:
 * its number `chunk`, its `heading` path, where it sits in the stored text
 * (`byteStart` to `byteEnd`, UTF-8 byte offsets, the end exclusive), the
 * pages it came from for a converted file (`pageStart`, `pageEnd`), its
 * `tokens` and its `text`. Each field as sent, null when absent.
 */
final class Chunk
{
    /**
     * @param array<array-key, mixed> $raw the chunk exactly as the API sent it
     */
    public function __construct(
        public readonly ?int $chunk,
        public readonly ?string $heading,
        public readonly ?int $byteStart,
        public readonly ?int $byteEnd,
        public readonly ?int $pageStart,
        public readonly ?int $pageEnd,
        public readonly ?int $tokens,
        public readonly ?string $text,
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
        $int = static fn (string $key): ?int => is_int($raw[$key] ?? null) ? $raw[$key] : null;
        $string = static fn (string $key): ?string => is_string($raw[$key] ?? null) ? $raw[$key] : null;

        return new self($int('chunk'), $string('heading'), $int('byte_start'), $int('byte_end'), $int('page_start'),
            $int('page_end'), $int('tokens'), $string('text'), $raw);
    }
}
