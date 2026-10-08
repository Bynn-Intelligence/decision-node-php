<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The answers to one session frame. `dropped` lists the `seq` of frames this
 * one superseded before they were answered (the latest frame wins), as the
 * server sent them.
 */
final class Reply
{
    /**
     * @param array<array-key, Answer>  $answers by question id
     * @param list<int>|null            $dropped
     * @param array<string, mixed>|null $usage
     * @param array<string, mixed>|null $safety
     * @param array<array-key, mixed>   $raw     the message exactly as the server sent it
     */
    public function __construct(
        public readonly ?int $seq,
        public readonly array $answers,
        public readonly ?array $dropped,
        public readonly ?array $usage,
        public readonly int|float|null $latencyMs,
        public readonly ?array $safety,
        public readonly array $raw,
    ) {
    }

    /**
     * @internal
     *
     * @param array<array-key, mixed> $raw
     */
    public static function fromArray(array $raw, mixed $shape = null): self
    {
        $shapes = $shape instanceof \stdClass && ($shape->answers ?? null) instanceof \stdClass ? $shape->answers : null;
        $answers = [];
        foreach (is_array($raw['answers'] ?? null) ? $raw['answers'] : [] as $id => $answer) {
            if (is_array($answer)) {
                $answerShape = $shapes !== null && property_exists($shapes, (string) $id) ? $shapes->{(string) $id} : null;
                $answers[$id] = Answer::fromArray($answer, $answerShape instanceof \stdClass ? $answerShape : null);
            }
        }
        $dropped = null;
        if (is_array($raw['dropped'] ?? null)) {
            $dropped = array_values(array_filter($raw['dropped'], 'is_int'));
        }
        $latency = $raw['latency_ms'] ?? null;

        return new self(
            seq: is_int($raw['seq'] ?? null) ? $raw['seq'] : null,
            answers: $answers,
            dropped: $dropped,
            usage: is_array($raw['usage'] ?? null) ? $raw['usage'] : null,
            latencyMs: is_int($latency) || is_float($latency) ? $latency : null,
            safety: is_array($raw['safety'] ?? null) ? $raw['safety'] : null,
            raw: $raw,
        );
    }
}
