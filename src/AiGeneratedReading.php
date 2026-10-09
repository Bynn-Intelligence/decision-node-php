<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The `ai_generated` booster's reading of one picture.
 *
 * `generated` is the probability, 0 to 1, that an image generator made the
 * picture, and `confidence` 0 to 1. `method` is `inference` (read from the
 * pixels), `c2pa` (the file's Content Credentials say so), or any other value
 * as the API sent it. `topGenerator` is the likeliest generator,
 * `['name' => ..., 'probability' => ...]`, null unless the picture reads as
 * likely generated and on every `c2pa` reading. `c2paSigner` is who signed
 * the file's Content Credentials, null unless they are trusted; `c2pa` is
 * `untrusted` when the file carries Content Credentials that are not trusted
 * (any other value kept as the API sent it), else null. Fields this version
 * does not know are ignored.
 */
final class AiGeneratedReading extends Reading
{
    public readonly int|float $generated;

    public readonly int|float $confidence;

    public readonly string $method;

    /** @var array{name: string, probability: int|float}|null */
    public readonly ?array $topGenerator;

    public readonly ?string $c2paSigner;

    public readonly ?string $c2pa;

    /**
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function wellFormed(array $raw): bool
    {
        if (!is_string($raw['image'] ?? null) || !is_string($raw['method'] ?? null)
            || !self::isNumber($raw['generated'] ?? null) || !self::isNumber($raw['confidence'] ?? null)) {
            return false;
        }
        // The API always writes top_generator and c2pa_signer, null or not.
        if (!array_key_exists('top_generator', $raw) || !array_key_exists('c2pa_signer', $raw)) {
            return false;
        }
        $top = $raw['top_generator'];
        if ($top !== null && (!is_array($top) || !is_string($top['name'] ?? null) || !self::isNumber($top['probability'] ?? null))) {
            return false;
        }
        $c2pa = $raw['c2pa'] ?? null;

        return ($raw['c2pa_signer'] === null || is_string($raw['c2pa_signer'])) && ($c2pa === null || is_string($c2pa));
    }

    /**
     * @param array<array-key, mixed> $raw a well-formed ai_generated reading
     */
    public function __construct(array $raw)
    {
        parent::__construct((string) ($raw['image'] ?? ''), $raw);
        $this->generated = self::isNumber($raw['generated'] ?? null) ? $raw['generated'] : 0;
        $this->confidence = self::isNumber($raw['confidence'] ?? null) ? $raw['confidence'] : 0;
        $this->method = (string) ($raw['method'] ?? '');
        $top = $raw['top_generator'] ?? null;
        $this->topGenerator = is_array($top) && is_string($top['name'] ?? null) && self::isNumber($top['probability'] ?? null)
            ? ['name' => $top['name'], 'probability' => $top['probability']]
            : null;
        $this->c2paSigner = is_string($raw['c2pa_signer'] ?? null) ? $raw['c2pa_signer'] : null;
        $this->c2pa = is_string($raw['c2pa'] ?? null) ? $raw['c2pa'] : null;
    }
}
