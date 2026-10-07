<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;


/**
 * A question in its wire form. Build one with {@see truth()}, {@see choice()}
 * or {@see score()} (or the static methods of the same names).
 *
 * A plain array in the wire form (`['type' => 'truth', 'instructions' => ...]`)
 * is accepted wherever a Question is, so a request copied from the API
 * documentation runs unchanged.
 */
final class Question implements \JsonSerializable
{
    /**
     * @param \stdClass|list<mixed>|null $criteria
     */
    private function __construct(
        public readonly string $type,
        public readonly mixed $instructions,
        private readonly \stdClass|array|null $criteria,
    ) {
    }

    /**
     * A yes-or-no question, answered with the probability that it is true.
     *
     * `$ifTrue` and `$ifFalse` describe what counts as true and as false; the
     * criteria are left out when neither is given.
     */
    public static function truth(mixed $instructions = null, mixed $ifTrue = null, mixed $ifFalse = null): self
    {
        $criteria = [];
        if ($ifTrue !== null) {
            $criteria['true'] = $ifTrue;
        }
        if ($ifFalse !== null) {
            $criteria['false'] = $ifFalse;
        }

        return new self('truth', $instructions, $criteria === [] ? null : (object) $criteria);
    }

    /**
     * One option of a set. `$options` is a map of option name to description,
     * or a list of option names (each description is then null). Names are sent
     * byte for byte, in the order given.
     *
     * A PHP list such as `['a', 'b']` is read as names. To use names that are
     * decimal integers ("0", "1"), pass a map with descriptions or an object.
     *
     * @param iterable<array-key, mixed>|object $options
     *
     * @throws \InvalidArgumentException when a list of names holds something other than a name
     */
    public static function choice(mixed $instructions, iterable|object $options): self
    {
        $map = [];
        if (is_array($options) && array_is_list($options)) {
            foreach ($options as $name) {
                if (!is_scalar($name)) {
                    throw new \InvalidArgumentException('A list of choice options must hold option names (strings).');
                }
                $map[(string) $name] = null;
            }
        } else {
            /** @var iterable<array-key, mixed> $iterable */
            $iterable = is_iterable($options) ? $options : get_object_vars($options);
            foreach ($iterable as $name => $description) {
                $map[(string) $name] = $description;
            }
        }

        return new self('choice', $instructions, (object) $map);
    }

    /**
     * A place on an ordered scale. `$levels` runs from low to high; each level
     * is a string or any JSON value.
     *
     * @param iterable<mixed> $levels
     */
    public static function score(mixed $instructions, iterable $levels): self
    {
        $list = [];
        foreach ($levels as $level) {
            $list[] = $level;
        }

        return new self('score', $instructions, $list);
    }

    /**
     * The question in its wire form, ready for `json_encode`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $wire = ['type' => $this->type];
        if ($this->instructions !== null) {
            $wire['instructions'] = $this->instructions;
        }
        if ($this->criteria !== null) {
            $wire['criteria'] = $this->criteria instanceof \stdClass ? clone $this->criteria : $this->criteria;
        }

        return $wire;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
