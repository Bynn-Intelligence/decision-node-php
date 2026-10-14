<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\AgeReading;
use Bynn\DecisionNode\AiGeneratedReading;
use Bynn\DecisionNode\BatchRequest;
use Bynn\DecisionNode\Booster;
use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\OverloadedError;
use Bynn\DecisionNode\Exception\PermissionDeniedError;
use Bynn\DecisionNode\Exception\RefusalError;
use Bynn\DecisionNode\UnknownReading;

use function Bynn\DecisionNode\truth;

final class BoosterTest extends TestCase
{
    private const EVIDENCE = [
        'age' => ['version' => 'age-v3', 'status' => 'ok', 'latency_ms' => 212, 'billed_calls' => 2, 'results' => [
            ['image' => 'a1b2', 'age_years' => 27, 'low' => 22, 'high' => 33, 'confidence' => 0.81, 'faces' => 1],
            ['image' => 'c3d4', 'faces' => 0],
        ]],
        'ai_generated' => ['version' => 'ai_generated-v2', 'status' => 'ok', 'latency_ms' => 340, 'results' => [
            ['image' => 'c3d4', 'generated' => 0.93, 'confidence' => 0.86, 'method' => 'inference', 'top_generator' => ['name' => 'Generator A', 'probability' => 0.71], 'c2pa_signer' => null],
            ['image' => 'e5f6', 'generated' => 0.99, 'confidence' => 0.99, 'method' => 'c2pa', 'top_generator' => null, 'c2pa_signer' => 'Example Camera Co.'],
            ['image' => 'g7h8', 'generated' => 0.04, 'confidence' => 0.9, 'method' => 'inference', 'top_generator' => null, 'c2pa_signer' => null, 'c2pa' => 'untrusted', 'region' => ['x' => 4]],
        ]],
        'faces' => ['version' => 'faces-v1', 'status' => 'ok', 'latency_ms' => 90, 'results' => [['image' => 'a1b2', 'count' => 1]]],
        'text' => ['version' => 'text-v1', 'status' => 'timeout', 'latency_ms' => 800, 'results' => []],
    ];

    public function testBoostersWireForm(): void
    {
        $dn = $this->client([self::truthBody(), self::truthBody(), self::truthBody()]);
        $dn->decide(questions: ['q' => truth('?')], model: 'decisionnode-flash@acme/fraud:3', boosters: [
            Booster::AGE,
            new Booster(Booster::AI_GENERATED, images: ['c3d4'], require: true),
            new Booster('objects', options: ['min' => 0.5]),
            new Booster('text', require: false, options: []),
            ['name' => 'faces'],
            new Booster('objects_v2'),
        ]);
        self::assertSame(
            '{"model":"decisionnode-flash@acme/fraud:3","state":"","questions":{"q":{"type":"truth","instructions":"?"}},'
            . '"boosters":["age",{"name":"ai_generated","images":["c3d4"],"require":true},{"name":"objects","options":{"min":0.5}},'
            . '{"name":"text","require":false,"options":{}},{"name":"faces"},"objects_v2"]}',
            (string) $this->sent()->getBody(),
        );

        // Left out or empty: no boosters key, byte for byte.
        $dn->decide(questions: ['q' => truth('?')]);
        $dn->decide(questions: ['q' => truth('?')], boosters: []);
        $plain = '{"model":"decisionnode-moe","state":"","questions":{"q":{"type":"truth","instructions":"?"}}}';
        self::assertSame($plain, (string) $this->sent(1)->getBody());
        self::assertSame($plain, (string) $this->sent(2)->getBody());
    }

    public function testHelpersDecideManyAndBatchLinesCarryBoosters(): void
    {
        $dn = $this->client([self::truthBody(0.9, 'check'), self::truthBody(), self::truthBody()]);
        $dn->check(instructions: '?', boosters: ['age']);
        self::assertSame(['age'], $this->sentBody()->boosters);

        $dn->decideMany([
            ['questions' => ['q' => truth('?')], 'boosters' => [new Booster('age', require: true)]],
            ['questions' => ['q' => truth('?')]],
        ]);
        $bodies = [$this->sentBody(1), $this->sentBody(2)];
        $boosted = array_values(array_filter($bodies, static fn ($b): bool => isset($b->boosters)));
        self::assertCount(1, $boosted);
        self::assertTrue($boosted[0]->boosters[0]->require);

        $lines = $dn->batches->lines([
            new BatchRequest('l1', ['q' => truth('?')], boosters: ['age']),
            ['custom_id' => 'l2', 'questions' => ['q' => truth('?')], 'boosters' => [new Booster('ai_generated', images: ['x'])]],
        ])->wait();
        self::assertStringEndsWith(',"boosters":["age"]}' . "\n", $lines[0]);
        self::assertStringEndsWith(',"boosters":[{"name":"ai_generated","images":["x"]}]}' . "\n", $lines[1]);
        $dn->checkRequest('decide', ['questions' => ['q' => truth('?')], 'boosters' => ['age']]);
    }

    public function testBoosterOptionsCountTowardNesting(): void
    {
        $dn = $this->client([]);
        $deep = [];
        // body, boosters, the booster, options: 4 levels, then 124 more.
        for ($i = 0; $i < 124; $i++) {
            $deep = ['x' => $deep === [] ? new \stdClass() : $deep];
        }
        $dn->checkRequest('decide', ['questions' => ['q' => truth('?')], 'boosters' => [new Booster('age', options: $deep)]]);
        try {
            $dn->checkRequest('decide', ['questions' => ['q' => truth('?')], 'boosters' => [new Booster('age', options: ['x' => $deep])]]);
            self::fail('129 levels must be refused');
        } catch (BadRequestError $e) {
            self::assertSame('api_usage_error', $e->errorType);
            self::assertSame('Invalid request.', $e->getMessage());
            self::assertStringContainsString('129', (string) $e->reason);
        }
    }

    public function testEvidenceAndUsage(): void
    {
        $dn = $this->client([self::json(200, [
            'model' => 'decisionnode-moe',
            'answers' => ['q' => ['type' => 'truth', 'truth' => 0.97]],
            'evidence' => self::EVIDENCE,
            'usage' => ['input_tokens' => 341, 'output_tokens' => 0, 'booster_tokens' => 72, 'boosters' => 3],
        ], ['x-decisionnode-boosters' => 'age=ok:212,ai_generated=ok:340'])]);
        $d = $dn->decide(questions: ['q' => truth('?')], boosters: ['age', 'ai_generated']);

        self::assertSame(72, $d->usage->boosterTokens);
        self::assertSame(3, $d->usage->boosters);
        self::assertNotNull($d->evidence);
        self::assertSame(['age', 'ai_generated', 'faces', 'text'], array_keys($d->evidence));
        $age = $d->evidence['age'];
        self::assertSame(['age-v3', 'ok', 212, 2], [$age->version, $age->status, $age->latencyMs, $age->billedCalls]);
        self::assertNull($d->evidence['text']->billedCalls);
        $read = $age->results[0];
        self::assertInstanceOf(AgeReading::class, $read);
        self::assertSame(['a1b2', 1, 27, 22, 33, 0.81], [$read->image, $read->faces, $read->ageYears, $read->low, $read->high, $read->confidence]);
        $none = $age->results[1];
        self::assertInstanceOf(AgeReading::class, $none);
        self::assertSame([0, null, null], [$none->faces, $none->ageYears, $none->confidence]);

        [$inference, $c2pa, $untrusted] = $d->evidence['ai_generated']->results;
        self::assertInstanceOf(AiGeneratedReading::class, $untrusted);
        self::assertSame(['untrusted', null, null], [$untrusted->c2pa, $untrusted->topGenerator, $untrusted->c2paSigner]);
        self::assertInstanceOf(AiGeneratedReading::class, $inference);
        self::assertSame([0.93, 'inference', ['name' => 'Generator A', 'probability' => 0.71], null], [$inference->generated, $inference->method, $inference->topGenerator, $inference->c2paSigner]);
        self::assertInstanceOf(AiGeneratedReading::class, $c2pa);
        self::assertSame(['c2pa', null, 'Example Camera Co.'], [$c2pa->method, $c2pa->topGenerator, $c2pa->c2paSigner]);

        $unknown = $d->evidence['faces']->results[0];
        self::assertInstanceOf(UnknownReading::class, $unknown);
        self::assertSame(['image' => 'a1b2', 'count' => 1], $unknown->raw);
        self::assertSame('timeout', $d->evidence['text']->status);
        self::assertSame([], $d->evidence['text']->results);
    }

    public function testNoBoostersNoEvidence(): void
    {
        $d = $this->client([self::truthBody()])->decide(questions: ['q' => truth('?')]);
        self::assertNull($d->evidence);
        self::assertNull($d->usage->boosterTokens);
        self::assertNull($d->usage->boosters);
    }

    public function testMalformedReadingsAreGeneric(): void
    {
        $readings = [
            'age' => [
                ['image' => 'a', 'faces' => 1],
                ['image' => 'a', 'faces' => '1'],
                ['faces' => 0],
                ['image' => 'a', 'faces' => 1, 'age_years' => '27', 'low' => 22, 'high' => 33, 'confidence' => 0.8],
            ],
            'ai_generated' => [
                ['image' => 'a', 'generated' => 0.9, 'confidence' => 0.9],
                ['image' => 'a', 'generated' => 0.9, 'confidence' => 0.9, 'method' => 'inference', 'top_generator' => ['name' => 'x']],
                ['image' => 'a', 'generated' => 0.9, 'confidence' => 0.9, 'method' => 'c2pa', 'top_generator' => null, 'c2pa_signer' => 7],
                ['image' => 'a', 'generated' => 0.9, 'confidence' => 0.9, 'method' => 'inference', 'c2pa_signer' => null],
                ['image' => 'a', 'generated' => 0.9, 'confidence' => 0.9, 'method' => 'inference', 'top_generator' => null],
                ['image' => 'a', 'generated' => 0.9, 'confidence' => 0.9, 'method' => 'inference', 'top_generator' => 'x', 'c2pa_signer' => null],
                ['image' => 'a', 'generated' => 0.9, 'confidence' => 0.9, 'method' => 'inference', 'top_generator' => null, 'c2pa_signer' => null, 'c2pa' => true],
            ],
        ];
        foreach ($readings as $booster => $list) {
            foreach ($list as $reading) {
                $d = $this->client([self::json(200, [
                    'model' => 'm',
                    'answers' => [],
                    'evidence' => [$booster => ['status' => 'ok', 'results' => [$reading]]],
                ])])->decide(questions: ['q' => truth('?')]);
                self::assertNotNull($d->evidence);
                self::assertInstanceOf(UnknownReading::class, $d->evidence[$booster]->results[0], json_encode($reading) ?: '');
                self::assertNull($d->evidence[$booster]->latencyMs);
            }
        }
    }

    public function testBoosterErrors(): void
    {
        $cases = [
            [400, 'booster_unknown', BadRequestError::class],
            [400, 'booster_input_mismatch', BadRequestError::class],
            [403, 'booster_not_enabled', PermissionDeniedError::class],
            [403, 'workspace_suspended', PermissionDeniedError::class],
        ];
        foreach ($cases as [$status, $type, $class]) {
            $dn = $this->client([self::json($status, ['detail' => ['error_type' => $type, 'message' => 'm']], ['x-decisionnode-boosters' => 'age=error:3'])]);
            try {
                $dn->decide(questions: ['q' => truth('?')], boosters: ['age']);
                self::fail($type);
            } catch (\Throwable $e) {
                self::assertSame($class, $e::class, $type);
                self::assertNotInstanceOf(RefusalError::class, $e);
                self::assertInstanceOf(\Bynn\DecisionNode\Exception\DecisionNodeError::class, $e);
                self::assertSame($type, $e->errorType);
                self::assertSame(['age' => ['status' => 'error', 'latency_ms' => 3]], $e->boosters);
                self::assertCount(1, $this->history, 'never retried');
            }
        }
    }

    public function testBoosterUnavailableIsRetried(): void
    {
        $busy = fn () => self::json(529, ['detail' => ['error_type' => 'booster_unavailable', 'message' => "The booster 'age' did not answer in time."]], [
            'x-decisionnode-boosters' => 'age=timeout:800, ai_generated=ok:120.5',
            'retry-after-ms' => '1',
        ]);
        $dn = $this->client([$busy(), $busy(), $busy()]);
        try {
            $dn->decide(questions: ['q' => truth('?')], boosters: [new Booster('age', require: true), 'ai_generated']);
            self::fail('529');
        } catch (OverloadedError $e) {
            self::assertSame('booster_unavailable', $e->errorType);
            self::assertCount(3, $this->history);
            self::assertSame([
                'age' => ['status' => 'timeout', 'latency_ms' => 800],
                'ai_generated' => ['status' => 'ok', 'latency_ms' => 120.5],
            ], $e->boosters);
        }
    }

    public function testFaceReadings(): void
    {
        $d = $this->client([self::json(200, [
            'model' => 'm', 'answers' => [],
            'evidence' => [
                'face_detection' => ['status' => 'ok', 'results' => [
                    ['image' => 'gate', 'faces' => 70, 'detections' => [['box' => ['x1' => 0.3219, 'y1' => 0.2611, 'x2' => 0.3797, 'y2' => 0.3806], 'confidence' => 0.97]]],
                    ['image' => 'gate', 'faces' => 1, 'detections' => [['box' => ['x1' => 1, 'y1' => 2, 'x2' => 3], 'confidence' => 0.5]]],
                    ['image' => 'gate', 'faces' => 1],
                ]],
                'face_recognition' => ['status' => 'ok', 'results' => [
                    ['image' => 'gate', 'faces' => 2, 'matches' => [
                        ['box' => ['x1' => 412, 'y1' => 188, 'x2' => 486, 'y2' => 274], 'score' => 0.93, 'collection' => 'celebrities', 'reference' => 'celeb-00412', 'name' => 'A Public Figure'],
                        ['box' => ['x1' => 5, 'y1' => 6, 'x2' => 7, 'y2' => 8], 'score' => 0.81, 'collection' => 'col_3f6c1a9e0b2d4c7f8a1e5d9b2c4f6a80', 'reference' => 'employee-1042'],
                    ]],
                    ['image' => 'gate', 'faces' => 1, 'matches' => [['box' => ['x1' => 1, 'y1' => 2, 'x2' => 3, 'y2' => 4], 'score' => 0.9, 'collection' => 'celebrities', 'reference' => 192]]],
                    ['image' => 'gate', 'faces' => 1, 'matches' => [['box' => ['x1' => 1, 'y1' => 2, 'x2' => 3, 'y2' => 4], 'score' => 0.9, 'reference' => 'r']]],
                    ['image' => 'gate', 'faces' => 1, 'matches' => [['box' => ['x1' => 1, 'y1' => 2, 'x2' => 3, 'y2' => 4], 'score' => 0.9, 'collection' => 'c', 'reference' => 'r', 'label' => 7]]],
                ]],
            ],
        ])])->decide(questions: ['q' => truth('?')]);
        self::assertNotNull($d->evidence);
        [$found, $noCorner, $noList] = $d->evidence['face_detection']->results;
        self::assertInstanceOf(\Bynn\DecisionNode\FaceDetectionReading::class, $found);
        self::assertSame([70, 0.3219, 0.97], [$found->faces, $found->detections[0]['box']['x1'], $found->detections[0]['confidence']]);
        self::assertInstanceOf(UnknownReading::class, $noCorner);
        self::assertInstanceOf(UnknownReading::class, $noList);
        [$match, $badReference, $noCollection, $badLabel] = $d->evidence['face_recognition']->results;
        self::assertInstanceOf(\Bynn\DecisionNode\FaceRecognitionReading::class, $match);
        self::assertSame(
            [2, 'A Public Figure', null, 274, 'employee-1042', null, 'col_3f6c1a9e0b2d4c7f8a1e5d9b2c4f6a80'],
            [$match->faces, $match->matches[0]['name'], $match->matches[0]['label'], $match->matches[0]['box']['y2'], $match->matches[1]['reference'], $match->matches[1]['name'], $match->matches[1]['collection']],
        );
        self::assertInstanceOf(UnknownReading::class, $badReference);
        self::assertInstanceOf(UnknownReading::class, $noCollection);
        self::assertInstanceOf(UnknownReading::class, $badLabel);
    }

    public function testFaceRecognitionCollectionNameAndMetadata(): void
    {
        $box = ['x1' => 1, 'y1' => 2, 'x2' => 3, 'y2' => 4];
        $d = $this->client([self::json(200, [
            'model' => 'm', 'answers' => [],
            'evidence' => ['face_recognition' => ['status' => 'ok', 'results' => [
                ['image' => 'gate', 'collection_name' => 'Staff', 'faces' => 1, 'matches' => [
                    ['box' => $box, 'score' => 0.9, 'collection' => 'col_1', 'collection_name' => 'Staff', 'reference' => 'employee-1042', 'metadata' => ['site' => 'north', 'floor' => 3]],
                ]],
                ['image' => 'gate', 'faces' => 1, 'matches' => [['box' => $box, 'score' => 0.9, 'collection' => 'celebrities', 'reference' => 'celeb-1']]],
                ['image' => 'gate', 'collection_name' => 7, 'faces' => 0, 'matches' => []],
                ['image' => 'gate', 'faces' => 1, 'matches' => [['box' => $box, 'score' => 0.9, 'collection' => 'c', 'reference' => 'r', 'metadata' => ['x']]]],
                ['image' => 'gate', 'faces' => 1, 'matches' => [['box' => $box, 'score' => 0.9, 'collection' => 'c', 'reference' => 'r', 'collection_name' => ['Staff']]]],
            ]]],
        ])])->decide(questions: ['q' => truth('?')]);
        self::assertNotNull($d->evidence);
        [$own, $public, $badName, $listMetadata, $badMatchName] = $d->evidence['face_recognition']->results;
        self::assertInstanceOf(\Bynn\DecisionNode\FaceRecognitionReading::class, $own);
        self::assertSame(['Staff', 'Staff', ['site' => 'north', 'floor' => 3]], [$own->collectionName, $own->matches[0]['collection_name'], $own->matches[0]['metadata']]);
        self::assertInstanceOf(\Bynn\DecisionNode\FaceRecognitionReading::class, $public);
        self::assertSame([null, null, null], [$public->collectionName, $public->matches[0]['collection_name'], $public->matches[0]['metadata']]);
        self::assertInstanceOf(UnknownReading::class, $badName);
        self::assertInstanceOf(UnknownReading::class, $listMetadata);
        self::assertInstanceOf(UnknownReading::class, $badMatchName);
    }

    public function testOcrReading(): void
    {
        $d = $this->client([self::json(200, [
            'model' => 'm', 'answers' => [],
            'evidence' => ['ocr' => ['status' => 'ok', 'results' => [
                ['image' => 'receipt', 'text' => 'Total 740.00 SEK', 'truncated' => false],
                ['image' => 'blank', 'text' => '', 'truncated' => false],
                ['image' => 'form', 'text' => '{}', 'truncated' => true],
                ['image' => 'a', 'text' => 740, 'truncated' => false],
                ['image' => 'a', 'text' => 'x'],
            ]]],
        ])])->decide(questions: ['q' => truth('?')]);
        self::assertNotNull($d->evidence);
        [$read, $blank, $cut, $wrong, $badFlag] = $d->evidence['ocr']->results;
        self::assertInstanceOf(\Bynn\DecisionNode\OcrReading::class, $read);
        self::assertSame(['receipt', 'Total 740.00 SEK', false], [$read->image, $read->text, $read->truncated]);
        self::assertInstanceOf(\Bynn\DecisionNode\OcrReading::class, $blank);
        self::assertSame('', $blank->text);
        self::assertInstanceOf(\Bynn\DecisionNode\OcrReading::class, $cut);
        self::assertTrue($cut->truncated);
        self::assertInstanceOf(UnknownReading::class, $wrong);
        self::assertInstanceOf(UnknownReading::class, $badFlag);
    }

    public function testNewBoosterOptions(): void
    {
        $dn = $this->client([self::json(400, ['detail' => ['error_type' => 'booster_unknown', 'message' => 'Unknown booster: ocr']])]);
        try {
            $dn->decide(questions: ['q' => truth('?')], boosters: [Booster::FACE_DETECTION, Booster::ocr('What is the total on the receipt?'), Booster::faceRecognition('celebrities', 0.9)]);
            self::fail('booster_unknown');
        } catch (BadRequestError $e) {
            self::assertSame('booster_unknown', $e->errorType);
        }
        self::assertStringEndsWith(',"boosters":["face_detection",{"name":"ocr","options":{"prompt":"What is the total on the receipt?"}},{"name":"face_recognition","options":{"collection":"celebrities","threshold":0.9}}]}', (string) $this->sent()->getBody());
    }

    public function testModelPrices(): void
    {
        $dn = $this->client([self::json(200, ['models' => [
            ['name' => 'decisionnode-moe', 'price_per_million_input' => 0.042, 'price_per_million_output' => 0, 'price_per_million_image' => 0.09, 'price_per_million_video' => 0.09, 'batch_price_factor' => 0.7],
            ['name' => 'decisionnode-flash@acme/triage', 'price_per_million_input' => 0.055, 'fine_tuned' => true],
        ]])]);
        [$moe, $tuned] = $dn->models();
        self::assertSame([0.042, 0, 0.09, 0.09, 0.7], [$moe->pricePerMillionInput, $moe->pricePerMillionOutput, $moe->pricePerMillionImage, $moe->pricePerMillionVideo, $moe->batchPriceFactor]);
        self::assertSame([0.055, null, null], [$tuned->pricePerMillionInput, $tuned->pricePerMillionImage, $tuned->batchPriceFactor]);
    }

    public function testFineTunedModels(): void
    {
        $dn = $this->client([
            self::json(200, ['models' => [
                ['name' => 'decisionnode-flash', 'version' => 'decisionnode-flash'],
                ['name' => 'decisionnode-flash@acme/fraud:3', 'version' => 'decisionnode-flash@acme/fraud:3', 'description' => 'Claims fraud', 'fine_tuned' => true, 'base' => 'decisionnode-flash', 'adapter_version' => 3, 'status' => 'deployed'],
                ['name' => 'decisionnode-flash@acme/fraud:4', 'fine_tuned' => true, 'base' => 'decisionnode-flash', 'adapter_version' => 4, 'status' => 'ready'],
            ]]),
            self::json(200, ['model' => 'decisionnode-flash@acme/fraud:3', 'answers' => ['q' => ['type' => 'truth', 'truth' => 0.5]]]),
        ]);
        [$catalogue, $deployed, $ready] = $dn->models();
        self::assertSame([null, null, null, null], [$catalogue->fineTuned, $catalogue->base, $catalogue->adapterVersion, $catalogue->status]);
        self::assertSame([true, 'decisionnode-flash', 3, 'deployed', 'decisionnode-flash@acme/fraud:3'], [$deployed->fineTuned, $deployed->base, $deployed->adapterVersion, $deployed->status, $deployed->version]);
        self::assertSame(['ready', 4], [$ready->status, $ready->adapterVersion]);

        $d = $dn->decide(questions: ['q' => truth('?')], model: 'decisionnode-flash@acme/fraud');
        self::assertSame('decisionnode-flash@acme/fraud:3', $d->model);
        self::assertSame('decisionnode-flash@acme/fraud', $this->sentBody(1)->model);
    }
}
