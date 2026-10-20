<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * A booster with settings: a specialist model that reads the request's images
 * before the decision. Its reading is added to what the model sees as
 * evidence and comes back in `Decision::$evidence`.
 *
 *     $dn->decide(questions: $questions, images: $images, boosters: [
 *         Booster::AGE,
 *         new Booster(Booster::AI_GENERATED, images: ['listing'], require: true),
 *     ]);
 *
 * A booster with no settings is named by its name alone, as a string; a
 * Booster with no option set is sent the same way. A booster's wire form
 * (`['name' => 'age', 'require' => true]`) is accepted wherever a Booster is. Names are plain strings, so a booster added to the API later works with
 * this version too.
 */
final class Booster implements \JsonSerializable
{
    /** Reads how old the person in a picture is (the largest face). */
    public const AGE = 'age';

    /** Reads how likely a picture was made by an image generator, and who signed its Content Credentials. */
    public const AI_GENERATED = 'ai_generated';

    /** Finds the faces in a picture. */
    public const FACE_DETECTION = 'face_detection';

    /** Matches the faces in a picture against a collection of faces: the workspace's own or a public one. */
    public const FACE_RECOGNITION = 'face_recognition';

    /** Reads the text in a picture, or answers a prompt about it. */
    public const OCR = 'ocr';

    /**
     * Depth Vision: the distance in meters to what is in a picture, the
     * nearest obstacle straight ahead, seven directions from left to right,
     * and a grid. Estimates from one picture, for decisions and planning,
     * never a safety stop.
     */
    public const DEPTH = 'bynn_depth';

    /**
     * Segment Vision: finds what a prompt names in a picture, each match's
     * outline, box and centre, and with depth on its distance and size in
     * meters. It needs a prompt: see {@see Booster::segment()}.
     */
    public const SEGMENT = 'bynn_segment';

    /**
     * Knowledge: the passages of the workspace's own documents (a collection
     * of type `text`) that apply to the request. It needs a collection: see
     * {@see Booster::knowledge()}.
     */
    public const KNOWLEDGE = 'knowledge';

    /** @var list<string>|null */
    public readonly ?array $images;

    /**
     * @param iterable<string>|null              $images  the ids of the request's images the booster reads;
     *                                                   null reads every image of the request
     * @param bool|null                          $require true fails the request with a 529 OverloadedError
     *                                                   (`booster_unavailable`) when the booster fails or does
     *                                                   not answer in time; otherwise the decision runs without
     *                                                   the reading and its evidence says why
     * @param array<string, mixed>|object|null   $options the booster's settings
     */
    public function __construct(
        public readonly string $name,
        ?iterable $images = null,
        public readonly ?bool $require = null,
        public readonly array|object|null $options = null,
    ) {
        if ($images === null) {
            $this->images = null;
        } else {
            $list = [];
            foreach ($images as $id) {
                $list[] = $id;
            }
            $this->images = $list;
        }
    }

    /**
     * The ocr booster with a prompt: a task, an instruction or a JSON schema
     * for what to read from the picture, at most 2,000 characters (the API's
     * limit, not checked here).
     *
     * @param iterable<string>|null $images
     */
    public static function ocr(string $prompt, ?iterable $images = null, ?bool $require = null): self
    {
        return new self(self::OCR, $images, $require, ['prompt' => $prompt]);
    }

    /**
     * The face_recognition booster searching a collection: a public
     * collection's name or the id of one of the workspace's own. A
     * `$threshold` of 0 to 1 sets the lowest score a match may have; null
     * leaves the server's default (0.5). Both are sent as given: the API
     * checks them.
     *
     * @param iterable<string>|null $images
     */
    public static function faceRecognition(string $collection, int|float|null $threshold = null, ?iterable $images = null, ?bool $require = null): self
    {
        $options = ['collection' => $collection];
        if ($threshold !== null) {
            $options['threshold'] = $threshold;
        }

        return new self(self::FACE_RECOGNITION, $images, $require, $options);
    }

    /**
     * Depth Vision with its grid of `$gridSize` by `$gridSize` cells, 2 to
     * 16 (the server's default is 8). Sent as given: the API checks it.
     *
     * @param iterable<string>|null $images
     */
    public static function depth(int $gridSize, ?iterable $images = null, ?bool $require = null): self
    {
        return new self(self::DEPTH, $images, $require, ['grid_size' => $gridSize]);
    }

    /**
     * Segment Vision with what to find: a phrase, or a list of 1 to 4
     * phrases of at most 120 characters each, sent as given. `$maxInstances`
     * (1 to 16, default 8), `$minScore` (0 to 1, default 0.25),
     * `$polygonPoints` (3 to 64, default 24) and `$depth` (default true)
     * are sent only when given. The API checks every value.
     *
     * @param string|list<string>   $prompt
     * @param iterable<string>|null $images
     */
    public static function segment(
        string|array $prompt,
        ?int $maxInstances = null,
        int|float|null $minScore = null,
        ?int $polygonPoints = null,
        ?bool $depth = null,
        ?iterable $images = null,
        ?bool $require = null,
    ): self {
        $options = ['prompt' => $prompt];
        foreach (['max_instances' => $maxInstances, 'min_score' => $minScore, 'polygon_points' => $polygonPoints, 'depth' => $depth] as $key => $value) {
            if ($value !== null) {
                $options[$key] = $value;
            }
        }

        return new self(self::SEGMENT, $images, $require, $options);
    }

    /**
     * The knowledge booster over one of the workspace's own knowledge bases
     * (a `col_` id). Every other option is sent only when given, as given:
     * `$query` replaces the query built from the request; `$scope` names
     * the value of the collection's scope key; `$match` (metadata equality)
     * and `$filter` (a filter tree) are JSON values passed through;
     * `$topK` (0 to 20, 0 for the pinned documents only), `$maxTokens` (an
     * integer or `"auto"`), `$pinned` (false leaves the pinned documents
     * out), `$minScore` (0 to 1) and `$includeText` (each passage's text;
     * a full-scope key only). `$require` true fails the request when the
     * reading does not run over a ready knowledge base. The API checks every
     * option.
     *
     * @param array<array-key, mixed>|object|null $match
     * @param array<array-key, mixed>|object|null $filter
     */
    public static function knowledge(
        string $collection,
        ?string $query = null,
        ?string $scope = null,
        array|object|null $match = null,
        array|object|null $filter = null,
        ?int $topK = null,
        int|string|null $maxTokens = null,
        ?bool $pinned = null,
        int|float|null $minScore = null,
        ?bool $includeText = null,
        bool $require = false,
    ): self {
        $options = ['collection' => $collection];
        $given = [
            'query' => $query, 'scope' => $scope, 'match' => $match, 'filter' => $filter, 'top_k' => $topK,
            'max_tokens' => $maxTokens, 'pinned' => $pinned, 'min_score' => $minScore, 'include_text' => $includeText,
        ];
        foreach ($given as $key => $value) {
            if ($value !== null) {
                $options[$key] = $value;
            }
        }

        return new self(self::KNOWLEDGE, null, $require ? true : null, $options);
    }

    /**
     * The booster's wire form: `{name, images?, require?, options?}`, each
     * argument left out when not given.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $wire = ['name' => $this->name];
        if ($this->images !== null) {
            $wire['images'] = $this->images;
        }
        if ($this->require !== null) {
            $wire['require'] = $this->require;
        }
        if ($this->options !== null) {
            // An empty PHP array is a JSON object here, not a list.
            $wire['options'] = $this->options === [] ? new \stdClass() : $this->options;
        }

        return $wire;
    }

    /**
     * The booster as sent: its bare name when no option is set, else
     * {@see toArray()}.
     *
     * @return string|array<string, mixed>
     */
    public function jsonSerialize(): string|array
    {
        if ($this->images === null && $this->require === null && $this->options === null) {
            return $this->name;
        }

        return $this->toArray();
    }
}
