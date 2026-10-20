<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * An item of a collection: one enrolled face, or one document of a knowledge
 * base, known by its `reference` and, when it has one, its `label` (null
 * otherwise). A face's picture is never kept.
 *
 * The answer to an enrollment also carries `collection`, `facesFound` (how
 * many faces the image held; the largest was enrolled), `box` (the enrolled
 * face's corners in pixels of the image as sent) and `usage` (`enrollments`
 * and `cost`, as sent); a listed item has none of them. `metadata` is the
 * item's own keys and values as sent, null when it has none.
 *
 * A document also carries, each as sent and null when absent: `title`,
 * `format` (`markdown` or `text`), `pinned`, `pinRank`, `source` (`article`
 * for text sent as text, `file` for a converted file), `file` (`filename`,
 * `content_type`, `byte_size`, `pages`, `original_held`; null for an
 * article), `status` (`queued`, `processing`, `ready`, `failed`, or any
 * other value as sent), `stage` (`extracting`, `chunking`, `embedding`, or
 * null), `progress` (`chunks_embedded`, `chunks_total`, `pages`, or null),
 * `serving` (`current`, `previous`, `none`), `error` (why it failed, or
 * null), `delayed`, `warnings` (an empty list when absent), `chunks`,
 * `tokens`, `contentSha256`, `version`, `queuedAt`, `readyAt` and `text`,
 * the stored text, only from `getItem()` with `includeText` (null while
 * nothing serves). Fields this version does not know are in `raw`.
 */
final class CollectionItem
{
    /**
     * @param array{x1: int|float, y1: int|float, x2: int|float, y2: int|float}|null $box
     * @param array<string, mixed>|null $usage
     * @param array<array-key, mixed>|null $metadata
     * @param array<array-key, mixed>   $raw   the item object exactly as the API sent it
     * @param array<array-key, mixed>|null $file     a document's file, or null
     * @param array<array-key, mixed>|null $progress a document's progress while it is processed, or null
     * @param list<string>              $warnings
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
        public readonly ?array $metadata = null,
        public readonly ?string $title = null,
        public readonly ?string $format = null,
        public readonly ?bool $pinned = null,
        public readonly ?int $pinRank = null,
        public readonly ?string $source = null,
        public readonly ?array $file = null,
        public readonly ?string $status = null,
        public readonly ?string $stage = null,
        public readonly ?array $progress = null,
        public readonly ?string $serving = null,
        public readonly ?string $error = null,
        public readonly ?bool $delayed = null,
        public readonly array $warnings = [],
        public readonly ?int $chunks = null,
        public readonly ?int $tokens = null,
        public readonly ?string $contentSha256 = null,
        public readonly ?int $version = null,
        public readonly ?string $queuedAt = null,
        public readonly ?string $readyAt = null,
        public readonly ?string $text = null,
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
        $int = static fn (string $key): ?int => is_int($raw[$key] ?? null) ? $raw[$key] : null;
        $bool = static fn (string $key): ?bool => is_bool($raw[$key] ?? null) ? $raw[$key] : null;
        $map = static fn (string $key): ?array => is_array($raw[$key] ?? null) ? $raw[$key] : null;
        $warnings = [];
        foreach (is_array($raw['warnings'] ?? null) ? $raw['warnings'] : [] as $warning) {
            if (is_string($warning)) {
                $warnings[] = $warning;
            }
        }
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
            metadata: is_array($raw['metadata'] ?? null) ? $raw['metadata'] : null,
            title: $string('title'),
            format: $string('format'),
            pinned: $bool('pinned'),
            pinRank: $int('pin_rank'),
            source: $string('source'),
            file: $map('file'),
            status: $string('status'),
            stage: $string('stage'),
            progress: $map('progress'),
            serving: $string('serving'),
            error: $string('error'),
            delayed: $bool('delayed'),
            warnings: $warnings,
            chunks: $int('chunks'),
            tokens: $int('tokens'),
            contentSha256: $string('content_sha256'),
            version: $int('version'),
            queuedAt: $string('queued_at'),
            readyAt: $string('ready_at'),
            text: $string('text'),
        );
    }

    /**
     * Whether the document's ingestion has ended: `ready` or `failed`.
     */
    public function settled(): bool
    {
        return $this->status === 'ready' || $this->status === 'failed';
    }
}
