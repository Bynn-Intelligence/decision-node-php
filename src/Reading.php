<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * One booster's reading of one image, named by `image`.
 *
 * The `age` booster's readings are {@see AgeReading}s, the `ai_generated`
 * booster's {@see AiGeneratedReading}s, the `face_detection` booster's
 * {@see FaceDetectionReading}s, the `face_recognition` booster's
 * {@see FaceRecognitionReading}s, the `ocr` booster's {@see OcrReading}s, Depth
 * Vision's (`bynn_depth`) {@see DepthReading}s, Segment Vision's
 * (`bynn_segment`) {@see SegmentReading}s and the `knowledge` booster's one
 * {@see KnowledgeReading}, whose `image` is null. A reading of a booster this version of
 * the SDK does not know, or one with a field missing or of the wrong type, is
 * an {@see UnknownReading} holding its raw fields: the decision does not fail.
 */
abstract class Reading
{
    /**
     * @param array<array-key, mixed> $raw the reading exactly as the API sent it
     */
    public function __construct(
        public readonly ?string $image,
        public readonly array $raw,
    ) {
    }

    /**
     * @internal
     */
    public static function fromArray(string $booster, mixed $raw): Reading
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            return new UnknownReading(is_array($raw) ? $raw : []);
        }

        return match (true) {
            $booster === Booster::AGE && AgeReading::wellFormed($raw) => new AgeReading($raw),
            $booster === Booster::AI_GENERATED && AiGeneratedReading::wellFormed($raw) => new AiGeneratedReading($raw),
            $booster === Booster::OCR && OcrReading::wellFormed($raw) => new OcrReading($raw),
            $booster === Booster::FACE_DETECTION && FaceDetectionReading::wellFormed($raw) => new FaceDetectionReading($raw),
            $booster === Booster::FACE_RECOGNITION && FaceRecognitionReading::wellFormed($raw) => new FaceRecognitionReading($raw),
            $booster === Booster::DEPTH && DepthReading::wellFormed($raw) => new DepthReading($raw),
            $booster === Booster::SEGMENT && SegmentReading::wellFormed($raw) => new SegmentReading($raw),
            $booster === Booster::KNOWLEDGE && KnowledgeReading::wellFormed($raw) => new KnowledgeReading($raw),
            default => new UnknownReading($raw),
        };
    }

    /**
     * Whether `$box` has the four corners `x1`, `y1`, `x2`, `y2`, each a number.
     *
     * @internal
     */
    protected static function box(mixed $box): bool
    {
        if (!is_array($box)) {
            return false;
        }
        foreach (['x1', 'y1', 'x2', 'y2'] as $corner) {
            if (!self::isNumber($box[$corner] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @internal
     */
    protected static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
