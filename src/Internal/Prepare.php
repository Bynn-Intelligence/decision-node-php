<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\Question;

/**
 * Turns a call's arguments into their wire forms and checks them before any
 * network call.
 *
 * @internal
 */
final class Prepare
{
    /**
     * The questions in wire form by id, checked.
     *
     * @param iterable<array-key, mixed>|object $questions
     * @param bool                              $strict    write `truth` questions with the wire type `/v1/systemone` takes
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException for an empty question map
     * @throws BadRequestError|ValidationError for a question the API would refuse
     */
    public static function questions(iterable|object $questions, bool $strict = false): array
    {
        $wire = [];
        $iterable = is_iterable($questions) ? $questions : get_object_vars($questions);
        foreach ($iterable as $id => $question) {
            if ($question instanceof Question) {
                $question = $question->toArray();
            }
            if ($strict) {
                $question = self::strictTruth($question);
            }
            $wire[(string) $id] = $question;
        }
        if ($wire === []) {
            throw new \InvalidArgumentException('questions must hold at least one question.');
        }
        LocalChecks::questions($wire);

        return $wire;
    }

    /**
     * The images as a list, checked.
     *
     * @param iterable<mixed>|null $images
     *
     * @return list<mixed>
     *
     * @throws BadRequestError for an image the API would refuse
     */
    public static function images(?iterable $images): array
    {
        $list = [];
        foreach ($images ?? [] as $image) {
            $list[] = $image;
        }
        LocalChecks::images($list);

        return $list;
    }

    /**
     * A request's body: model, state and questions, then the images.
     *
     * @param array<string, mixed> $questions
     * @param list<mixed>          $images    wire forms
     * @param array<string, mixed> $head      fields that go first (a batch line's custom_id)
     * @param array<string, mixed> $tail      fields that go last
     */
    public static function body(string $model, mixed $state, array $questions, array $images, array $head = [], array $tail = []): string
    {
        $body = $head + [
            'model' => $model,
            'state' => $state ?? '',
            'questions' => (object) $questions,
        ];
        if ($images !== []) {
            $body['images'] = $images;
        }

        return Json::encode($body + $tail);
    }

    private static function strictTruth(mixed $question): mixed
    {
        if (is_array($question) && ($question['type'] ?? null) === 'truth') {
            $question['type'] = 'noul';
        } elseif ($question instanceof \stdClass && ($question->type ?? null) === 'truth') {
            $question = clone $question;
            $question->type = 'noul';
        }

        return $question;
    }
}
