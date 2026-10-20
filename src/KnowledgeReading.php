<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The `knowledge` booster's reading, and the answer of
 * `collections->search()`: the passages of the workspace's own documents
 * that apply, in the block's order (pinned first).
 *
 *     $reading = $decision->evidence['knowledge']->results[0];
 *     foreach ($reading->passages as $p) {
 *         echo $p->n, ' ', $p->title, ' > ', $p->heading, ' (', $p->item, ' chunk ', $p->chunk, ")\n";
 *     }
 *
 * `collection` is the knowledge base's id and `collectionName` its name
 * (null for a publishable key's reading). `corpus` is `{version}`; `query`
 * `{source, tokens, text}` (`source` `auto`, `request` or `none`; `text`
 * only with `include_text` and on a search); `budget` `{mode, tokens,
 * entry, cause}`; `scope` `{key, value}` or null; `warnings` (such as
 * `scope_not_named`, `over_budget`, `budget_floor`, `pinned_filtered`);
 * `omitted` the passages left out by cause; `pendingItems` the documents
 * not ready yet; `search` `{plan, candidates, fallback}`; `truncated`
 * whether a passage was dropped to fit the context (null on a search).
 * Each as sent; a field absent is null, `warnings` and `omitted` empty.
 *
 * A reading with `collection` or `passages` missing, or a field or a
 * passage's field of the wrong type, is an {@see UnknownReading} holding
 * its raw fields: the decision does not fail.
 */
final class KnowledgeReading extends Reading
{
    public readonly string $collection;

    public readonly ?string $collectionName;

    /** @var array<array-key, mixed>|null */
    public readonly ?array $corpus;

    /** @var array<array-key, mixed>|null */
    public readonly ?array $query;

    /** @var array<array-key, mixed>|null */
    public readonly ?array $budget;

    /** @var array<array-key, mixed>|null */
    public readonly ?array $scope;

    /** @var list<string> */
    public readonly array $warnings;

    /** @var list<KnowledgePassage> */
    public readonly array $passages;

    /** @var array<array-key, mixed> */
    public readonly array $omitted;

    public readonly ?int $pendingItems;

    /** @var array<array-key, mixed>|null */
    public readonly ?array $search;

    public readonly ?bool $truncated;

    /**
     * @param array<array-key, mixed> $raw the reading exactly as the API sent it, well formed
     */
    public function __construct(array $raw)
    {
        parent::__construct(is_string($raw['image'] ?? null) ? $raw['image'] : null, $raw);
        $map = static fn (string $key): ?array => is_array($raw[$key] ?? null) ? $raw[$key] : null;
        $this->collection = (string) $raw['collection'];
        $this->collectionName = is_string($raw['collection_name'] ?? null) ? $raw['collection_name'] : null;
        $this->corpus = $map('corpus');
        $this->query = $map('query');
        $this->budget = $map('budget');
        $this->scope = $map('scope');
        $warnings = [];
        foreach ($map('warnings') ?? [] as $warning) {
            if (is_string($warning)) {
                $warnings[] = $warning;
            }
        }
        $this->warnings = $warnings;
        $passages = [];
        foreach ((array) $raw['passages'] as $passage) {
            /** @var array<array-key, mixed> $passage */
            $passages[] = new KnowledgePassage($passage);
        }
        $this->passages = $passages;
        $this->omitted = $map('omitted') ?? [];
        $this->pendingItems = is_int($raw['pending_items'] ?? null) ? $raw['pending_items'] : null;
        $this->search = $map('search');
        $this->truncated = is_bool($raw['truncated'] ?? null) ? $raw['truncated'] : null;
    }

    /**
     * Whether `$raw[$key]` is absent, null or passes `$is`.
     *
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function optional(array $raw, string $key, callable $is): bool
    {
        return ($raw[$key] ?? null) === null || $is($raw[$key]);
    }

    /** Whether a decoded value was a JSON object. */
    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function wellFormed(array $raw): bool
    {
        $passages = $raw['passages'] ?? null;
        if (!is_string($raw['collection'] ?? null) || !is_array($passages) || !array_is_list($passages)) {
            return false;
        }
        foreach ($passages as $passage) {
            if (!KnowledgePassage::wellFormed($passage)) {
                return false;
            }
        }
        $warnings = $raw['warnings'] ?? null;
        if ($warnings !== null && (!is_array($warnings) || !array_is_list($warnings) || array_filter($warnings, 'is_string') !== $warnings)) {
            return false;
        }
        foreach (['corpus', 'query', 'budget', 'scope', 'omitted', 'search'] as $key) {
            if (($raw[$key] ?? null) !== null && !self::isObject($raw[$key])) {
                return false;
            }
        }

        return self::optional($raw, 'collection_name', 'is_string') && self::optional($raw, 'truncated', 'is_bool');
    }
}
