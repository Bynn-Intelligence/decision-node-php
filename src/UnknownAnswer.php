<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * An answer of a question type this version of the SDK does not know. It
 * holds the type and the raw fields, so a new question type on the server
 * does not break an older client.
 */
final class UnknownAnswer extends Answer
{
    /**
     * @param array<array-key, mixed> $raw the answer exactly as the API sent it
     */
    public function __construct(?string $type, array $raw)
    {
        parent::__construct($type, $raw);
    }
}
