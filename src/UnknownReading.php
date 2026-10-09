<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * A reading of a booster this version of the SDK does not know, or a reading
 * with a field missing or of the wrong type. It holds the raw fields, so a new
 * booster on the server does not break an older client.
 */
final class UnknownReading extends Reading
{
    /**
     * @param array<array-key, mixed> $raw the reading exactly as the API sent it
     */
    public function __construct(array $raw)
    {
        parent::__construct(is_string($raw['image'] ?? null) ? $raw['image'] : null, $raw);
    }
}
