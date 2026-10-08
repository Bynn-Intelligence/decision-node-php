<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * A video sent with a request as the frames the caller sampled, each with its
 * time. Its `id` is unique across a request's images and videos; the model
 * sees the video as "Video <n>" (its place in the request's videos, from 1)
 * with this id beside it, so text in the state or the instructions can refer
 * to it by either.
 *
 *     Video::of('dock', [
 *         VideoFrame::fromData(30.0, $jpeg1),
 *         VideoFrame::fromData(30.5, $jpeg2),
 *     ], fps: 2)
 *
 * The API reads the frames as one video, in order and with their times. Only
 * the frames are sent: never a video file, a video URL or sound. `fps` caps
 * the rate the API reads (above 0, at most 4; the API's default is 2 a
 * second), and `start` and `end` choose the window `[start, end)` on the
 * frames' own times. Each is left out of the request when not given.
 *
 * A wire-form array or object (`['id' => ..., 'frames' => [...]]`) is
 * accepted wherever a Video is, and a frame's wire form wherever a VideoFrame is.
 */
final class Video implements \JsonSerializable
{
    /** @var list<VideoFrame|array<array-key, mixed>|object> */
    public readonly array $frames;

    /**
     * @param iterable<VideoFrame|array<array-key, mixed>|object> $frames in time order
     */
    public function __construct(
        public readonly string $id,
        iterable $frames,
        public readonly int|float|null $fps = null,
        public readonly int|float|null $start = null,
        public readonly int|float|null $end = null,
    ) {
        $list = [];
        foreach ($frames as $frame) {
            $list[] = $frame;
        }
        $this->frames = $list;
    }

    /**
     * @param iterable<VideoFrame|array<array-key, mixed>|object> $frames in time order
     */
    public static function of(string $id, iterable $frames, int|float|null $fps = null, int|float|null $start = null, int|float|null $end = null): self
    {
        return new self($id, $frames, $fps, $start, $end);
    }

    /**
     * The video's wire form: `{id, fps?, start?, end?, frames}`, each frame
     * `{t, media_type, data}` with its data as base64.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $wire = ['id' => $this->id];
        foreach (['fps' => $this->fps, 'start' => $this->start, 'end' => $this->end] as $name => $value) {
            if ($value !== null) {
                $wire[$name] = $value;
            }
        }
        $wire['frames'] = array_map(static fn (mixed $frame): mixed => $frame instanceof VideoFrame ? $frame->toArray() : $frame, $this->frames);

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
