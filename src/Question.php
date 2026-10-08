<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;


/**
 * A question in its wire form. Build one with {@see truth()}, {@see choice()},
 * {@see score()}, {@see number()}, {@see point()} or {@see box()} (or the
 * static methods of the same names).
 *
 * A plain array in the wire form (`['type' => 'truth', 'instructions' => ...]`)
 * is accepted wherever a Question is, so a request copied from the API
 * documentation runs unchanged.
 */
final class Question implements \JsonSerializable
{
    /**
     * @param \stdClass|list<mixed>|null $criteria
     * @param array<string, mixed>       $fields   the type's own fields, as given: a number question's
     *                                             min, max and step, a point or box question's image and units
     */
    private function __construct(
        public readonly string $type,
        public readonly mixed $instructions,
        private readonly \stdClass|array|null $criteria,
        private readonly array $fields = [],
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
     * A number on a grid from `$min` to `$max` in steps of `$step`, answered
     * with the most likely value, the expected value and the probability of
     * each likely value. An argument left out is left out of the request, so
     * the server's defaults apply (0, 255 and 1).
     */
    public static function number(mixed $instructions = null, int|float|null $min = null, int|float|null $max = null, int|float|null $step = null): self
    {
        $grid = [];
        foreach (['min' => $min, 'max' => $max, 'step' => $step] as $name => $value) {
            if ($value !== null) {
                $grid[$name] = $value;
            }
        }

        return new self('number', $instructions, null, $grid);
    }

    /**
     * One spot in an image, answered with its `x` and `y`, the probability
     * that the thing is there at all (`present`) and the probability that the
     * spot is on it (`confidence`).
     *
     * `$image` is the id of the image to look in, needed when the request has
     * two or more images; `$units` is `pixels` (the default) or `normalized`
     * (fractions of the width and height). Each is left out of the request
     * when not given.
     */
    public static function point(mixed $instructions, ?string $image = null, ?string $units = null): self
    {
        return new self('point', $instructions, null, self::place($image, $units));
    }

    /**
     * A box around something in an image, answered with its top-left
     * (`x1`, `y1`) and bottom-right (`x2`, `y2`) corners, `present` and
     * `confidence`. `$image` and `$units` as for {@see point()}.
     */
    public static function box(mixed $instructions, ?string $image = null, ?string $units = null): self
    {
        return new self('box', $instructions, null, self::place($image, $units));
    }

    /**
     * @return array<string, string>
     */
    private static function place(?string $image, ?string $units): array
    {
        $fields = [];
        if ($image !== null) {
            $fields['image'] = $image;
        }
        if ($units !== null) {
            $fields['units'] = $units;
        }

        return $fields;
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
        foreach ($this->fields as $name => $value) {
            $wire[$name] = $value;
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
