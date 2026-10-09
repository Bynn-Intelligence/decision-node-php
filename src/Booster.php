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
