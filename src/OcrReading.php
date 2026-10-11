<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The `ocr` booster's reading of one picture: `text`, the text it shows or the
 * answer to the prompt, exactly as sent (empty when the picture shows no
 * text), and `truncated`, true when the reading was cut at the context
 * limit. The API always sends both.
 */
final class OcrReading extends Reading
{
    public readonly string $text;

    public readonly bool $truncated;

    /**
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function wellFormed(array $raw): bool
    {
        return is_string($raw['image'] ?? null) && is_string($raw['text'] ?? null) && is_bool($raw['truncated'] ?? null);
    }

    /**
     * @param array<array-key, mixed> $raw a well-formed ocr reading
     */
    public function __construct(array $raw)
    {
        parent::__construct((string) ($raw['image'] ?? ''), $raw);
        $this->text = is_string($raw['text'] ?? null) ? $raw['text'] : '';
        $this->truncated = ($raw['truncated'] ?? null) === true;
    }
}
