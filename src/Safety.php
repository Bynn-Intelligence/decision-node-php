<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The result of the safety check that runs on every request to the hosted API,
 * read from the `x-decisionnode-safety` response header.
 *
 * When the check ran, `checked` is true, `harm` and `selfHarm` are the scores
 * and `flagged` lists the categories above the key's thresholds. When it did
 * not run, `checked` is false and `reason` says why.
 */
final class Safety
{
    /**
     * @param list<string>              $flagged categories above the thresholds, in header order
     * @param array<string, float|null> $scores  every category in the header, by name
     */
    public function __construct(
        public readonly bool $checked,
        public readonly ?float $harm = null,
        public readonly ?float $selfHarm = null,
        public readonly array $flagged = [],
        public readonly ?string $reason = null,
        public readonly array $scores = [],
    ) {
    }

    /**
     * Parses the header value. Returns null when the header is absent, as on a
     * self-hosted server.
     */
    public static function fromHeader(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $members = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $m): bool => $m !== ''));
        $first = $members[0] ?? '';
        $parts = array_map('trim', explode(';', $first));
        if ($parts[0] === 'unchecked') {
            $reason = null;
            foreach (array_slice($parts, 1) as $param) {
                [$key, $val] = array_pad(explode('=', $param, 2), 2, null);
                if (trim((string) $key) === 'reason' && $val !== null) {
                    $reason = self::unquote($val);
                }
            }

            return new self(checked: false, reason: $reason);
        }

        $scores = [];
        $flagged = [];
        foreach ($members as $member) {
            $parts = array_map('trim', explode(';', $member));
            [$name, $number] = array_pad(explode('=', $parts[0], 2), 2, null);
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $number = $number === null ? null : trim($number);
            $scores[$name] = $number !== null && is_numeric($number) ? (float) $number : null;
            foreach (array_slice($parts, 1) as $param) {
                $flag = trim(explode('=', $param, 2)[0]);
                if ($flag === 'flagged' || $flag === 'blocked') {
                    $flagged[] = $name;
                    break;
                }
            }
        }

        return new self(
            checked: true,
            harm: $scores['harm'] ?? null,
            selfHarm: $scores['self_harm'] ?? null,
            flagged: $flagged,
            scores: $scores,
        );
    }

    private static function unquote(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }
}
