<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

use Bynn\DecisionNode\Question;

/**
 * Turns a call's arguments into their wire forms.
 *
 * @internal
 */
final class Prepare
{
    /**
     * The questions in wire form by id.
     *
     * @param iterable<array-key, mixed>|object $questions
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException for an empty question map
     */
    public static function questions(iterable|object $questions): array
    {
        $wire = [];
        $iterable = is_iterable($questions) ? $questions : get_object_vars($questions);
        foreach ($iterable as $id => $question) {
            if ($question instanceof Question) {
                $question = $question->toArray();
            }
            $wire[(string) $id] = $question;
        }
        if ($wire === []) {
            throw new \InvalidArgumentException('questions must hold at least one question.');
        }

        return $wire;
    }

    /**
     * The images as a list.
     *
     * @param iterable<mixed>|null $images
     *
     * @return list<mixed>
     */
    public static function images(?iterable $images): array
    {
        $list = [];
        foreach ($images ?? [] as $image) {
            $list[] = $image;
        }

        return $list;
    }

    /**
     * The videos as a list. Video and VideoFrame objects are written in their wire
     * forms when the body is encoded; frames always go inline.
     *
     * @param iterable<mixed>|null $videos
     *
     * @return list<mixed>
     */
    public static function videos(?iterable $videos): array
    {
        $list = [];
        foreach ($videos ?? [] as $video) {
            $list[] = $video;
        }

        return $list;
    }

    /**
     * The boosters as a list, in the caller's order. Booster objects are
     * written in their wire forms when the body is encoded; nothing about
     * them is checked here.
     *
     * @param iterable<mixed>|null $boosters
     *
     * @return list<mixed>
     */
    public static function boosters(?iterable $boosters): array
    {
        $list = [];
        foreach ($boosters ?? [] as $booster) {
            $list[] = $booster;
        }

        return $list;
    }

    /**
     * A request's body: model, state and questions, the boosters, then the
     * images and the videos. No boosters leaves the field out, so the body
     * is the body of a request without them.
     *
     * @param array<string, mixed> $questions
     * @param list<mixed>          $images    wire forms
     * @param list<mixed>          $videos    Video objects or wire forms
     * @param array<string, mixed> $head      fields that go first (a batch line's custom_id)
     * @param array<string, mixed> $tail      fields that go last
     * @param list<mixed>          $boosters  names, Booster objects or wire forms
     */
    public static function body(string $model, mixed $state, array $questions, array $images, array $videos = [], array $head = [], array $tail = [], array $boosters = []): string
    {
        $body = $head + [
            'model' => $model,
            'state' => $state ?? '',
            'questions' => (object) $questions,
        ];
        if ($boosters !== []) {
            $body['boosters'] = $boosters;
        }
        if ($images !== []) {
            $body['images'] = $images;
        }
        if ($videos !== []) {
            $body['videos'] = $videos;
        }

        return Json::encode($body + $tail);
    }
}
