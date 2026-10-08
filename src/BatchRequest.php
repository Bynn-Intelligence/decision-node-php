<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One request of a batch: the arguments of `decide` and the `customId` its
 * result line carries. A plain array with the keys `custom_id`, `state`,
 * `questions`, `images` and `model` is accepted wherever a BatchRequest is.
 */
final class BatchRequest
{
    /**
     * @param iterable<array-key, Question|array<string, mixed>|object>|object $questions by question id
     * @param iterable<Image|array<string, mixed>>|null                       $images    up to 4
     */
    public function __construct(
        public readonly string $customId,
        public readonly iterable|object $questions,
        public readonly mixed $state = '',
        public readonly ?iterable $images = null,
        public readonly ?string $model = null,
    ) {
    }
}
