<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One passage of a {@see KnowledgeReading}: number `n` in the block, whether
 * it is `pinned`, the document (`item`, its `reference` and `title`), the
 * chunk's `heading` path and number `chunk`, and where it sits in the
 * document's stored text: `byteStart` to `byteEnd`, UTF-8 byte offsets,
 * the end exclusive, into the text of `collections->getItem(..., includeText: true)`.
 * `score` is the similarity (null for a pin), `lexical` whether it matched
 * on words, `rendered` false when it was left out to fit (null on a
 * search's passages), and `text` the passage's text, with `include_text`
 * and always on a search. Each number as sent.
 */
final class KnowledgePassage
{
    public readonly int $n;

    public readonly bool $pinned;

    public readonly string $item;

    public readonly int $chunk;

    public readonly int $byteStart;

    public readonly int $byteEnd;

    public readonly ?string $reference;

    public readonly ?string $title;

    public readonly ?string $heading;

    public readonly ?int $tokens;

    public readonly int|float|null $score;

    public readonly ?bool $lexical;

    public readonly ?bool $rendered;

    public readonly ?string $text;

    /**
     * @param array<array-key, mixed> $raw the passage exactly as the API sent it, well formed
     */
    public function __construct(public readonly array $raw)
    {
        $string = static fn (string $key): ?string => is_string($raw[$key] ?? null) ? $raw[$key] : null;
        $bool = static fn (string $key): ?bool => is_bool($raw[$key] ?? null) ? $raw[$key] : null;
        $this->n = (int) $raw['n'];
        $this->pinned = (bool) $raw['pinned'];
        $this->item = (string) $raw['item'];
        $this->chunk = (int) $raw['chunk'];
        $this->byteStart = (int) $raw['byte_start'];
        $this->byteEnd = (int) $raw['byte_end'];
        $this->reference = $string('reference');
        $this->title = $string('title');
        $this->heading = $string('heading');
        $this->tokens = is_int($raw['tokens'] ?? null) ? $raw['tokens'] : null;
        $score = $raw['score'] ?? null;
        $this->score = is_int($score) || is_float($score) ? $score : null;
        $this->lexical = $bool('lexical');
        $this->rendered = $bool('rendered');
        $this->text = $string('text');
    }

    /**
     * Whether a passage has its required fields, and every field it has, of
     * its JSON type.
     *
     * @internal
     */
    public static function wellFormed(mixed $raw): bool
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            return false;
        }
        foreach (['n', 'chunk', 'byte_start', 'byte_end'] as $key) {
            if (!is_int($raw[$key] ?? null)) {
                return false;
            }
        }

        $number = static fn (mixed $v): bool => is_int($v) || is_float($v);

        return is_bool($raw['pinned'] ?? null) && is_string($raw['item'] ?? null)
            && KnowledgeReading::optional($raw, 'reference', 'is_string') && KnowledgeReading::optional($raw, 'title', 'is_string')
            && KnowledgeReading::optional($raw, 'heading', 'is_string') && KnowledgeReading::optional($raw, 'text', 'is_string')
            && KnowledgeReading::optional($raw, 'score', $number) && KnowledgeReading::optional($raw, 'tokens', 'is_int')
            && KnowledgeReading::optional($raw, 'lexical', 'is_bool') && KnowledgeReading::optional($raw, 'rendered', 'is_bool');
    }
}
