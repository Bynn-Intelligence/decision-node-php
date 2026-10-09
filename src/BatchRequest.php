<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One request of a batch: the arguments of `decide` and the `customId` its
 * result line carries. A plain array with the keys `custom_id`, `state`,
 * `questions`, `images`, `videos`, `model`, `boosters` and `fallback` is
 * accepted wherever a BatchRequest is.
 */
final class BatchRequest
{
    /**
     * @param iterable<array-key, Question|array<string, mixed>|object>|object $questions by question id
     * @param iterable<Image|array<string, mixed>>|null                       $images    up to 16
     * @param iterable<Video|array<string, mixed>>|null                       $videos    up to 4, each as frames with their times
     * @param iterable<string|Booster|array<string, mixed>>|null              $boosters  up to 4, in order
     * @param bool|null                                                       $fallback  sent only when given
     */
    public function __construct(
        public readonly string $customId,
        public readonly iterable|object $questions,
        public readonly mixed $state = '',
        public readonly ?iterable $images = null,
        public readonly ?iterable $videos = null,
        public readonly ?string $model = null,
        public readonly ?iterable $boosters = null,
        public readonly ?bool $fallback = null,
    ) {
    }
}
