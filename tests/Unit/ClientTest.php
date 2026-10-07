<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\ChoiceAnswer;
use Bynn\DecisionNode\DecisionNode;
use Bynn\DecisionNode\Exception\AuthenticationError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\ServerError;
use Bynn\DecisionNode\Image;
use Bynn\DecisionNode\ScoreAnswer;
use Bynn\DecisionNode\TruthAnswer;
use Bynn\DecisionNode\UnknownAnswer;

use function Bynn\DecisionNode\choice;
use function Bynn\DecisionNode\score;
use function Bynn\DecisionNode\truth;

final class ClientTest extends TestCase
{
    public function testDecideSendsTheWireForm(): void
    {
        $dn = $this->client([self::truthBody()]);
        $dn->decide(
            state: ['ticket' => 'charged twice'],
            questions: [
                'route' => choice('Where?', ['billing' => 'money', 'bug' => 'broken']),
                'urgency' => score('How urgent?', ['low', 'high']),
                'q' => truth('Escalate?'),
                'wire' => ['type' => 'truth', 'instructions' => 'copied from the docs'],
            ],
        );

        $request = $this->sent();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.example.test/v1/decide', (string) $request->getUri());
        self::assertSame('Bearer ' . self::KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('decisionnode-php/' . DecisionNode::VERSION, $request->getHeaderLine('User-Agent'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertFalse($request->hasHeader('x-decisionnode-safety-body'));
        self::assertSame(
            '{"model":"decisionnode-latest","state":{"ticket":"charged twice"},"questions":{'
            . '"route":{"type":"choice","instructions":"Where?","criteria":{"billing":"money","bug":"broken"}},'
            . '"urgency":{"type":"score","instructions":"How urgent?","criteria":["low","high"]},'
            . '"q":{"type":"truth","instructions":"Escalate?"},'
            . '"wire":{"type":"truth","instructions":"copied from the docs"}}}',
            (string) $request->getBody(),
        );
    }

    public function testStateDefaultsToEmptyStringAndQuestionIdsStayObjectKeys(): void
    {
        $dn = $this->client([self::truthBody()]);
        $dn->decide(questions: ['0' => truth('a'), '' => truth('b')]);
        self::assertSame('{"model":"decisionnode-latest","state":"","questions":{"0":{"type":"truth","instructions":"a"},"":{"type":"truth","instructions":"b"}}}', (string) $this->sent()->getBody());
    }

    public function testImagesAreSentInOrder(): void
    {
        $dn = $this->client([self::truthBody()]);
        $dn->decide('', ['q' => truth('?')], [Image::fromUrl('a', 'https://example.com/a.jpg'), ['id' => 'b', 'url' => 'https://example.com/b.jpg']]);
        $body = $this->sentBody();
        self::assertSame('a', $body->images[0]->id);
        self::assertSame('https://example.com/b.jpg', $body->images[1]->url);
    }

    public function testDecisionFields(): void
    {
        $dn = $this->client([self::json(200, [
            'model' => 'decisionnode-1.0',
            'answers' => [
                'route' => ['type' => 'choice', 'choice' => 'billing', 'confidence' => 0.73, 'probabilities' => ['account' => 0.12, 'billing' => 0.8200000000000001, 'bug' => 0.060000000000000005]],
                'urgency' => ['type' => 'score', 'score' => 2.31, 'confidence' => 0.31, 'legend' => (object) ['0' => 'routine', '1' => 'today'], 'probabilities' => (object) ['0' => 0.01, '1' => 0.99]],
                'escalate' => ['type' => 'truth', 'truth' => 0.94],
                'future' => ['type' => 'ranking', 'order' => ['b', 'a']],
            ],
            'usage' => ['input_tokens' => 62, 'output_tokens' => 0],
        ], ['X-Request-Id' => 'req_9', 'X-DecisionNode-Latency-Ms' => '23', 'X-DecisionNode-Safety' => 'harm=0.031, self_harm=0.004'])]);

        $d = $dn->decide('s', ['q' => truth('?')]);
        self::assertSame('decisionnode-1.0', $d->model);
        self::assertSame('req_9', $d->requestId);
        self::assertSame(23, $d->latencyMs);
        self::assertSame(62, $d->usage->inputTokens);
        self::assertSame(0, $d->usage->outputTokens);
        self::assertNotNull($d->safety);
        self::assertSame(0.031, $d->safety->harm);
        self::assertCount(4, $d);

        $route = $d['route'];
        self::assertInstanceOf(ChoiceAnswer::class, $route);
        self::assertSame('billing', $route->choice);
        self::assertSame(0.8200000000000001, $route->probabilities['billing']);
        self::assertSame(0.060000000000000005, $route->probabilities['bug']);

        $urgency = $d['urgency'];
        self::assertInstanceOf(ScoreAnswer::class, $urgency);
        self::assertSame([0 => 0.01, 1 => 0.99], $urgency->probabilities);
        self::assertSame([0 => 'routine', 1 => 'today'], $urgency->legend);

        self::assertInstanceOf(TruthAnswer::class, $d['escalate']);
        self::assertSame(0.94, $d['escalate']->truth);

        $future = $d['future'];
        self::assertSame(UnknownAnswer::class, $future::class);
        self::assertSame('ranking', $future->type);
        self::assertSame(['type' => 'ranking', 'order' => ['b', 'a']], $future->raw);

        self::assertFalse(isset($d['missing']));
        $this->expectException(\OutOfBoundsException::class);
        $d['missing'];
    }

    public function testNoSafetyHeaderMeansNoSafety(): void
    {
        $dn = $this->client([self::json(200, ['model' => 'm', 'answers' => ['q' => ['type' => 'truth', 'truth' => 1.0]], 'usage' => []])]);
        $d = $dn->decide('s', ['q' => truth('?')]);
        self::assertNull($d->safety);
        self::assertNull($d->latencyMs);
        self::assertSame(1.0, $d['q']->truth);
    }

    public function testEnvironmentAndPrecedence(): void
    {
        putenv('DECISIONNODE_API_KEY=dn_from_env');
        putenv('DECISIONNODE_MODEL=decisionnode-1.0-flash');
        putenv('DECISIONNODE_BASE_URL=https://env.example.test/');

        $dn = $this->client([self::truthBody(), self::truthBody(), self::truthBody()], ['apiKey' => null, 'baseUrl' => null]);
        $dn->decide('s', ['q' => truth('?')]);
        self::assertSame('Bearer dn_from_env', $this->sent(0)->getHeaderLine('Authorization'));
        self::assertSame('https://env.example.test/v1/decide', (string) $this->sent(0)->getUri());
        self::assertSame('decisionnode-1.0-flash', $this->sentBody(0)->model);

        $dn->decide('s', ['q' => truth('?')], model: 'decisionnode-1.0');
        self::assertSame('decisionnode-1.0', $this->sentBody(1)->model);

        $explicit = $this->client([self::truthBody()], ['apiKey' => 'dn_explicit', 'model' => 'decisionnode-flash-latest']);
        $explicit->decide('s', ['q' => truth('?')]);
        self::assertSame('Bearer dn_explicit', $this->sent(0)->getHeaderLine('Authorization'));
        self::assertSame('decisionnode-flash-latest', $this->sentBody(0)->model);
    }

    public function testEmptyArgumentsCountAsNotGiven(): void
    {
        putenv('DECISIONNODE_API_KEY=dn_from_env');
        putenv('DECISIONNODE_MODEL=decisionnode-1.0-flash');
        putenv('DECISIONNODE_BASE_URL=https://env.example.test');
        $dn = $this->client([self::truthBody(), self::truthBody()], ['apiKey' => '', 'baseUrl' => '', 'model' => '']);
        $dn->decide('s', ['q' => truth('?')], model: '');
        self::assertSame('Bearer dn_from_env', $this->sent(0)->getHeaderLine('Authorization'));
        self::assertSame('https://env.example.test/v1/decide', (string) $this->sent(0)->getUri());
        self::assertSame('decisionnode-1.0-flash', $this->sentBody(0)->model);

        putenv('DECISIONNODE_MODEL');
        $dn = $this->client([self::truthBody()], ['model' => '']);
        $dn->decide('s', ['q' => truth('?')]);
        self::assertSame('decisionnode-latest', $this->sentBody(0)->model);
    }

    public function testDefaults(): void
    {
        $dn = new DecisionNode(apiKey: 'k');
        self::assertSame('https://api.decisionnode.com', $dn->baseUrl);
        self::assertSame('decisionnode-latest', $dn->model);
        self::assertSame(10.0, $dn->timeout);
        self::assertSame(2, $dn->maxRetries);
    }

    public function testCustomHeadersAreAdded(): void
    {
        $dn = $this->client([self::truthBody()], ['headers' => ['x-decisionnode-safety-body' => 'true', 'x-team' => 'tools']]);
        $dn->decide('s', ['q' => truth('?')]);
        self::assertSame('true', $this->sent()->getHeaderLine('x-decisionnode-safety-body'));
        self::assertSame('tools', $this->sent()->getHeaderLine('x-team'));
    }

    public function testMissingKeyFailsBeforeSending(): void
    {
        $dn = $this->client([self::truthBody()], ['apiKey' => '']);
        try {
            $dn->decide('s', ['q' => truth('?')]);
            self::fail('no error');
        } catch (AuthenticationError $e) {
            self::assertNull($e->status);
        }
        self::assertCount(0, $this->history);
    }

    public function testEmptyQuestionsFailBeforeSending(): void
    {
        $dn = $this->client([self::truthBody()]);
        try {
            $dn->decide('s', []);
            self::fail('no error');
        } catch (\InvalidArgumentException) {
        }
        self::assertCount(0, $this->history);
    }

    public function testUnserialisableValueFailsBeforeSending(): void
    {
        $dn = $this->client([self::truthBody()]);
        $this->expectException(\InvalidArgumentException::class);
        try {
            $dn->decide("\xB1\x31", ['q' => truth('?')]);
        } finally {
            self::assertCount(0, $this->history);
        }
    }

    public function testCheckClassifyScoreUseFixedIds(): void
    {
        $dn = $this->client([
            self::truthBody(0.97, 'check'),
            self::json(200, ['model' => 'm', 'answers' => ['classify' => ['type' => 'choice', 'choice' => 'SEK', 'confidence' => 0.9, 'probabilities' => ['SEK' => 0.95, 'EUR' => 0.05]]], 'usage' => []]),
            self::json(200, ['model' => 'm', 'answers' => ['score' => ['type' => 'score', 'score' => 1.2, 'confidence' => 0.5, 'legend' => (object) ['0' => 'low', '1' => 'high'], 'probabilities' => (object) ['0' => 0.4, '1' => 0.6]]], 'usage' => []]),
        ]);

        $truth = $dn->check('Refund me.', 'Refund?', ifTrue: 'money back');
        self::assertSame(0.97, $truth->truth);
        self::assertSame('{"model":"decisionnode-latest","state":"Refund me.","questions":{"check":{"type":"truth","instructions":"Refund?","criteria":{"true":"money back"}}}}', (string) $this->sent(0)->getBody());

        $choice = $dn->classify('Paid in kronor', 'Currency?', ['SEK', 'EUR']);
        self::assertSame('SEK', $choice->choice);
        self::assertSame('classify', array_key_first(get_object_vars($this->sentBody(1)->questions)));

        $score = $dn->score('s', 'How urgent?', ['low', 'high']);
        self::assertSame(1.2, $score->score);
        self::assertSame(['low', 'high'], $this->sentBody(2)->questions->score->criteria);
    }

    public function testGateSendsThreeQuestions(): void
    {
        $dn = $this->client([self::truthBody(0.5, 'touches_production')]);
        $dn->gate('DROP TABLE invoices;', ['environment' => 'production']);
        $body = $this->sentBody();
        self::assertSame('DROP TABLE invoices;', $body->state->action);
        self::assertSame('production', $body->state->context->environment);
        self::assertSame(['risk', 'touches_production', 'verdict'], array_keys(get_object_vars($body->questions)));
        self::assertSame('score', $body->questions->risk->type);
        self::assertSame('truth', $body->questions->touches_production->type);
        self::assertSame(['allow', 'confirm', 'block'], array_keys(get_object_vars($body->questions->verdict->criteria)));
        self::assertSame('How much harm could this action cause if it is wrong?', $body->questions->risk->instructions);
        self::assertSame(['none', 'low', 'moderate', 'high', 'severe'], $body->questions->risk->criteria);
        self::assertSame('Does this action change a production system, production data or anything customers use?', $body->questions->touches_production->instructions);
        self::assertSame('Should this action run as it is, wait for a person to confirm it, or be blocked?', $body->questions->verdict->instructions);
        self::assertSame('a person should confirm it first', $body->questions->verdict->criteria->confirm);
    }

    public function testGateTakesImagesAndModel(): void
    {
        $dn = $this->client([self::truthBody()]);
        $dn->gate('Restart the payments service.', images: [Image::fromUrl('scan', 'https://example.com/scan.png')], model: 'decisionnode-1.0-flash');
        $body = $this->sentBody();
        self::assertSame('decisionnode-1.0-flash', $body->model);
        self::assertSame('https://example.com/scan.png', $body->images[0]->url);
    }

    public function testSpecVersion(): void
    {
        self::assertSame('1.0', DecisionNode::SPEC_VERSION);
        self::assertSame('1.0', \Bynn\DecisionNode\SPEC_VERSION);
    }

    public function testGateWithoutContextSendsOnlyTheAction(): void
    {
        $dn = $this->client([self::truthBody()]);
        $dn->gate('rm -rf build/');
        self::assertSame(['action' => 'rm -rf build/'], get_object_vars($this->sentBody()->state));
    }

    public function testModels(): void
    {
        $dn = $this->client([self::json(200, ['models' => [
            ['name' => 'decisionnode-1.0', 'description' => 'Main.', 'release_date' => '2026-10-01', 'max_input_tokens' => 32768],
        ]])]);
        $models = $dn->models();
        self::assertSame('GET', $this->sent()->getMethod());
        self::assertSame('/v1/models', $this->sent()->getUri()->getPath());
        self::assertSame('', (string) $this->sent()->getBody());
        self::assertFalse($this->sent()->hasHeader('Content-Type'));
        self::assertCount(1, $models);
        self::assertSame('decisionnode-1.0', $models[0]->name);
        self::assertSame('2026-10-01', $models[0]->releaseDate);
        self::assertSame(['max_input_tokens' => 32768], $models[0]->limits);
    }

    public function testDecideRawSendsTheBodyAsGiven(): void
    {
        $dn = $this->client([self::truthBody(), self::truthBody(), self::truthBody()]);
        $raw = $dn->decideRaw(['state' => 's', 'questions' => (object) [], 'future_field' => ['kept' => [1, 2.5, null]]]);
        self::assertSame('{"state":"s","questions":{},"future_field":{"kept":[1,2.5,null]}}', (string) $this->sent(0)->getBody());
        self::assertSame(0.94, $raw['answers']['q']['truth']);

        $dn->decideRaw('{"state":"s","questions":{}}');
        self::assertSame('{"state":"s","questions":{}}', (string) $this->sent(1)->getBody());

        $dn->decideRaw(json_decode('{"state":"s","questions":{"0":{"type":"truth"}}}'));
        self::assertSame('{"state":"s","questions":{"0":{"type":"truth"}}}', (string) $this->sent(2)->getBody());
    }

    public function testAsyncFormsReturnPromises(): void
    {
        $dn = $this->client([self::truthBody(0.25, 'check')]);
        $promise = $dn->checkAsync('s', 'Is it?');
        self::assertSame(0.25, $promise->wait()->truth);
    }

    public function testAsyncArgumentErrorsAreThrownBeforeSending(): void
    {
        $dn = $this->client([]);
        $this->expectException(\InvalidArgumentException::class);
        $dn->decideAsync('s', []);
    }

    public function testMalformedKnownAnswersAreUnknown(): void
    {
        $dn = $this->client([self::json(200, '{"model":"m","answers":{'
            . '"ok":{"type":"truth","truth":0.25},'
            . '"flag":{"type":"truth","truth":"0.94"},'
            . '"pick":{"type":"choice","choice":"a","confidence":0.8},'
            . '"list":{"type":"choice","choice":"a","confidence":0.8,"probabilities":[0.8,0.2]},'
            . '"level":{"type":"score","score":1.2,"confidence":0.5,"probabilities":[0.2,0.8],"legend":{"0":"low","1":"high"}},'
            . '"nolegend":{"type":"score","score":1.2,"confidence":0.5,"probabilities":{"0":0.2,"1":0.8}}'
            . '},"usage":{}}')]);
        $d = $dn->decide('s', ['ok' => truth('?')]);
        self::assertInstanceOf(TruthAnswer::class, $d['ok']);
        foreach (['flag' => 'truth', 'pick' => 'choice', 'list' => 'choice', 'level' => 'score', 'nolegend' => 'score'] as $id => $type) {
            self::assertInstanceOf(UnknownAnswer::class, $d[$id], $id);
            self::assertSame($type, $d[$id]->type);
        }
        self::assertSame('0.94', $d['flag']->raw['truth']);
    }

    public function testHelperRaisesServerErrorForAMalformedOrMissingAnswer(): void
    {
        $headers = ['x-request-id' => 'req_h', 'x-decisionnode-error' => "question 'check': the answer could not be completed"];
        $cases = [
            'check' => [['check' => ['type' => 'truth', 'truth' => '0.94']], static fn (DecisionNode $dn) => $dn->check('s', '?')],
            'missing' => [new \stdClass(), static fn (DecisionNode $dn) => $dn->check('s', '?')],
            'classify' => [['classify' => ['type' => 'choice', 'choice' => 'a']], static fn (DecisionNode $dn) => $dn->classify('s', '?', ['a', 'b'])],
            'score' => [['score' => ['type' => 'score', 'score' => 1.2, 'confidence' => 0.5, 'probabilities' => [0.2, 0.8]]], static fn (DecisionNode $dn) => $dn->score('s', '?', ['low', 'high'])],
            'async' => [['check' => ['type' => 'truth']], static fn (DecisionNode $dn) => $dn->checkAsync('s', '?')->wait()],
        ];
        foreach ($cases as $name => [$answers, $call]) {
            $dn = $this->client([self::json(200, ['model' => 'm', 'answers' => $answers, 'usage' => []], $headers)], ['maxRetries' => 0]);
            try {
                $call($dn);
                self::fail('no error for ' . $name);
            } catch (ServerError $e) {
                self::assertSame(200, $e->status, $name);
                self::assertSame('req_h', $e->requestId, $name);
                self::assertSame("question 'check': the answer could not be completed", $e->reason, $name);
            }
        }
    }

    public function testTimeoutAndMaxRetriesAreChecked(): void
    {
        foreach ([['timeout' => 0.0], ['timeout' => -1.0], ['timeout' => INF], ['timeout' => NAN], ['maxRetries' => -1]] as $args) {
            try {
                new DecisionNode(...$args + ['apiKey' => 'k']);
                self::fail('no error for ' . json_encode(array_keys($args)));
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame(0, (new DecisionNode(apiKey: 'k', maxRetries: 0))->maxRetries);
    }

    public function testPlainHttpOnlyForThisMachine(): void
    {
        foreach (['http://localhost:8080', 'http://127.0.0.1:1', 'http://[::1]:8787', 'https://api.example.test', 'HTTPS://API.EXAMPLE.TEST'] as $url) {
            self::assertSame(rtrim($url, '/'), (new DecisionNode(apiKey: 'k', baseUrl: $url))->baseUrl);
        }
        foreach (['http://api.example.test', 'http://localhost.example.invalid', 'http://127.0.0.2', 'ftp://localhost', 'api.example.test', 'https://'] as $url) {
            try {
                new DecisionNode(apiKey: 'k', baseUrl: $url);
                self::fail('no error for ' . $url);
            } catch (\InvalidArgumentException) {
            }
        }
        putenv('DECISIONNODE_BASE_URL=http://decisions.example.invalid');
        $this->expectException(\InvalidArgumentException::class);
        new DecisionNode(apiKey: 'k');
    }
}
