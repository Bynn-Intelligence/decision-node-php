<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * How a request was answered by another model than the one it asked for:
 * `decisionnode-flash` for `decisionnode-moe` when no worker could take
 * the request at once. Read from the `x-decisionnode-fallback` and
 * `x-decisionnode-requested-model` response headers.
 */
final class Fallback
{
    public function __construct(
        public readonly string $servedModel,
        public readonly ?string $requestedModel,
    ) {
    }

    /**
     * @internal
     *
     * @param array<string, string> $headers names in lower case
     */
    public static function fromHeaders(array $headers): ?self
    {
        $served = trim($headers['x-decisionnode-fallback'] ?? '');
        if ($served === '') {
            return null;
        }
        $requested = trim($headers['x-decisionnode-requested-model'] ?? '');

        return new self($served, $requested === '' ? null : $requested);
    }
}
