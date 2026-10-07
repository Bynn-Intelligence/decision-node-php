<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

if (!function_exists(__NAMESPACE__ . '\truth')) {
    /**
     * A yes-or-no question. See {@see Question::truth()}.
     */
    function truth(mixed $instructions = null, mixed $ifTrue = null, mixed $ifFalse = null): Question
    {
        return Question::truth($instructions, $ifTrue, $ifFalse);
    }
}

if (!function_exists(__NAMESPACE__ . '\choice')) {
    /**
     * One option of a set. See {@see Question::choice()}.
     *
     * @param iterable<array-key, mixed>|object $options
     */
    function choice(mixed $instructions, iterable|object $options): Question
    {
        return Question::choice($instructions, $options);
    }
}

if (!function_exists(__NAMESPACE__ . '\score')) {
    /**
     * A place on an ordered scale. See {@see Question::score()}.
     *
     * @param iterable<mixed> $levels
     */
    function score(mixed $instructions, iterable $levels): Question
    {
        return Question::score($instructions, $levels);
    }
}

if (!defined(__NAMESPACE__ . '\\SPEC_VERSION')) {
    /** The version of the DecisionNode SDK specification this package implements. */
    define(__NAMESPACE__ . '\\SPEC_VERSION', DecisionNode::SPEC_VERSION);
}
