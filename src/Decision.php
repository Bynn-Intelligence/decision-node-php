<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The result of one `decide` call.
 *
 * Answers are read by question id: `$decision['route']` or
 * `$decision->answers['route']`. Every number is the value the API sent, with
 * no rounding or renormalising. `warnings` holds what the API said about the
 * request, such as a video it read at a lower rate or resolution than asked.
 * `evidence` holds what each booster the request named read, by booster name
 * in the request's order; null when the request named none. `model` is the
 * model that answered; for a fine-tuned model, its name pinned to the version
 * that answered (`decisionnode-flash@acme/fraud:3`). `fallback` says that
 * the API answered a request for one model with another (Flash for
 * `decisionnode-moe` when no worker could take it at once); null on an
 * ordinary answer.
 *
 * @implements \ArrayAccess<array-key, Answer>
 * @implements \IteratorAggregate<array-key, Answer>
 */
final class Decision implements DecisionResult, \ArrayAccess, \Countable, \IteratorAggregate
{
    /**
     * @param array<array-key, Answer> $answers  by question id
     * @param array<array-key, mixed>  $raw      the parsed response body
     * @param list<string>             $warnings as the API sent them; empty when it sent none
     * @param array<string, Evidence>|null $evidence by booster name; null when the request named no boosters
     */
    public function __construct(
        public readonly array $answers,
        public readonly ?string $model,
        public readonly Usage $usage,
        public readonly ?string $requestId,
        public readonly int|float|null $latencyMs,
        public readonly ?Safety $safety,
        public readonly array $raw,
        public readonly array $warnings = [],
        public readonly ?array $evidence = null,
        public readonly ?Fallback $fallback = null,
    ) {
    }

    /**
     * @internal
     *
     * @param array<array-key, mixed> $body
     * @param array<string, string>   $headers names in lower case
     * @param mixed                   $shape   the same body decoded with objects kept as \stdClass, when known,
     *                                         so an answer whose map fields are JSON lists is told apart
     */
    public static function fromResponse(array $body, array $headers, mixed $shape = null): self
    {
        $shapes = $shape instanceof \stdClass && ($shape->answers ?? null) instanceof \stdClass ? $shape->answers : null;
        $answers = [];
        foreach (is_array($body['answers'] ?? null) ? $body['answers'] : [] as $id => $answer) {
            if (is_array($answer)) {
                $answerShape = $shapes !== null && property_exists($shapes, (string) $id) ? $shapes->{(string) $id} : null;
                $answers[$id] = Answer::fromArray($answer, $answerShape instanceof \stdClass ? $answerShape : null);
            }
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];
        $seconds = $usage['video_seconds'] ?? null;
        $warnings = [];
        foreach (is_array($body['warnings'] ?? null) ? $body['warnings'] : [] as $warning) {
            if (is_string($warning)) {
                $warnings[] = $warning;
            }
        }

        $latency = null;
        $header = isset($headers['x-decisionnode-latency-ms']) ? trim($headers['x-decisionnode-latency-ms']) : null;
        if ($header !== null && is_numeric($header)) {
            $value = (float) $header;
            $latency = floor($value) === $value && abs($value) < PHP_INT_MAX ? (int) $value : $value;
        }

        return new self(
            answers: $answers,
            model: is_string($body['model'] ?? null) ? $body['model'] : null,
            usage: new Usage(
                is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : null,
                is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null,
                is_int($usage['video_frames'] ?? null) ? $usage['video_frames'] : null,
                is_int($seconds) || is_float($seconds) ? $seconds : null,
                is_int($usage['booster_tokens'] ?? null) ? $usage['booster_tokens'] : null,
                is_int($usage['boosters'] ?? null) ? $usage['boosters'] : null,
                Cost::fromUsage($usage),
            ),
            requestId: $headers['x-request-id'] ?? null,
            latencyMs: $latency,
            safety: Safety::fromHeader($headers['x-decisionnode-safety'] ?? null),
            raw: $body,
            warnings: $warnings,
            evidence: self::evidence($body['evidence'] ?? null),
            fallback: Fallback::fromHeaders($headers),
        );
    }

    /**
     * The body's evidence by booster name, or null when it has none.
     *
     * @return array<string, Evidence>|null
     */
    private static function evidence(mixed $evidence): ?array
    {
        if (!is_array($evidence) || ($evidence !== [] && array_is_list($evidence))) {
            return null;
        }
        $out = [];
        foreach ($evidence as $name => $entry) {
            $out[(string) $name] = Evidence::fromArray((string) $name, is_array($entry) ? $entry : []);
        }

        return $out;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->answers[$offset]);
    }

    public function offsetGet(mixed $offset): Answer
    {
        if (!isset($this->answers[$offset])) {
            throw new \OutOfBoundsException(sprintf('The decision has no answer with the id "%s".', (string) $offset));
        }

        return $this->answers[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new \LogicException('A decision is read-only.');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('A decision is read-only.');
    }

    public function count(): int
    {
        return count($this->answers);
    }

    /**
     * @return \ArrayIterator<array-key, Answer>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->answers);
    }
}
