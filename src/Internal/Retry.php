<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

/**
 * When to retry and how long to wait.
 *
 * @internal
 */
final class Retry
{
    private const BACKOFF_START = 0.5;
    private const BACKOFF_CAP = 8.0;

    /** The longest wait the client takes when the server asks for one, in seconds. */
    public const MAX_SERVER_WAIT = 60.0;

    /**
     * 408, 429 and every 5xx (529 included) are retried. 400, 401, 402, 403,
     * 404 and 422 never are: the same request would get the same answer.
     */
    public static function retryable(int $status): bool
    {
        return $status === 408 || $status === 429 || ($status >= 500 && $status <= 599);
    }

    /**
     * The wait the server asked for, in seconds: `retry-after-ms` when sent,
     * else `Retry-After` (seconds or an HTTP date), else null. Capped at 60 s,
     * so a server can never stall a call for longer than that.
     *
     * @param array<string, string> $headers names in lower case
     * @param float|null            $cap     the longest wait returned, in seconds; null for none
     */
    public static function after(array $headers, ?float $cap = self::MAX_SERVER_WAIT): ?float
    {
        $ms = isset($headers['retry-after-ms']) ? trim($headers['retry-after-ms']) : null;
        if ($ms !== null && is_numeric($ms)) {
            return self::cap((float) $ms / 1000.0, $cap);
        }
        $seconds = isset($headers['retry-after']) ? trim($headers['retry-after']) : null;
        if ($seconds !== null && $seconds !== '') {
            if (is_numeric($seconds)) {
                return self::cap((float) $seconds, $cap);
            }
            $at = self::httpDate($seconds);
            if ($at !== null) {
                return self::cap((float) ($at - time()), $cap);
            }
        }

        return null;
    }

    /**
     * An HTTP date (IMF-fixdate, or the obsolete RFC 850 and asctime forms) as a Unix time.
     */
    private static function httpDate(string $value): ?int
    {
        $utc = new \DateTimeZone('UTC');
        foreach (['D, d M Y H:i:s \\G\\M\\T', 'l, d-M-y H:i:s \\G\\M\\T', 'D M j H:i:s Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, $utc);
            if ($date !== false) {
                return $date->getTimestamp();
            }
        }

        return null;
    }

    private static function cap(float $seconds, ?float $cap): float
    {
        $seconds = max(0.0, $seconds);

        return $cap === null ? $seconds : min($cap, $seconds);
    }

    /**
     * Exponential backoff from 0.5 s, capped at 8 s, with jitter.
     */
    public static function backoff(int $attempt): float
    {
        $base = min(self::BACKOFF_CAP, self::BACKOFF_START * (2 ** $attempt));

        return $base * (0.75 + (mt_rand() / mt_getrandmax()) * 0.25);
    }
}
