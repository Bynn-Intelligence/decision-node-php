<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\BatchResult;
use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\Image;
use Bynn\DecisionNode\Internal\GuzzleTransport;
use Bynn\DecisionNode\Internal\Held;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;

use function Bynn\DecisionNode\choice;
use function Bynn\DecisionNode\number;
use function Bynn\DecisionNode\truth;

final class ConsistencyTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/images/';

    /**
     * @return array<string, mixed>
     */
    private static function models(int $maxWindow = 16): array
    {
        return ['models' => [
            ['name' => 'decisionnode-moe-0.9.0', 'version' => 'decisionnode-moe-0.9.0', 'alias' => 'decisionnode-moe', 'number_max_grid_values' => 4096, 'sessions' => ['enabled' => true, 'max_window' => $maxWindow]],
            ['name' => 'decisionnode-flash-0.9.0', 'version' => 'decisionnode-flash-0.9.0', 'alias' => 'decisionnode-flash', 'number_max_grid_values' => 0, 'sessions' => ['enabled' => false]],
        ]];
    }

    public function testHeldLimitsAreFoundByNameAliasOrVersion(): void
    {
        $held = new Held();
        $held->keep(self::models()['models']);
        self::assertSame(16, $held->limits('decisionnode-moe-0.9.0')['sessions']['max_window']);
        self::assertSame(16, $held->limits('decisionnode-moe')['sessions']['max_window']);
        self::assertSame('decisionnode-moe', $held->limits('decisionnode-moe')['model']);
        self::assertSame(0, $held->limits('decisionnode-flash')['number_max_grid_values']);
        self::assertSame([], $held->limits('decisionnode-9'));
    }

    public function testCheckRequestRaisesWhatSendingRaisesAndSendsNothing(): void
    {
        $dn = $this->client([self::json(200, self::models())]);
        $dn->models();
        $this->history = [];

        $dn->checkRequest('decide', [
            'state' => 's',
            'questions' => ['q' => truth('?'), 'n' => number('How many?', 0, 50)],
            'images' => [Image::fromUrl('u', 'https://files.example.com/a.jpg'), Image::fromData('big', (string) file_get_contents(self::DIR . '3x2.png') . str_repeat("\0", 600_000))],
        ]);
        $dn->checkRequest('batches.create', ['requests' => [['custom_id' => 'a', 'questions' => ['q' => truth('?')]]]]);

        $refusals = [
            ['decide', ['questions' => ['c' => choice('?', [])]], BadRequestError::class],
            ['decide', ['questions' => ['n' => number('?', 60, 50)]], ValidationError::class],
            ['decide', ['questions' => ['n' => number('?')], 'model' => 'decisionnode-flash'], BadRequestError::class],
            ['open_session', ['questions' => ['a' => truth('?')], 'model' => 'decisionnode-moe', 'window' => 17], ValidationError::class],
            ['openSession', ['questions' => ['a' => truth('?')], 'model' => 'decisionnode-flash'], BadRequestError::class],
            ['batches.add', ['batch_id' => 'b', 'requests' => []], BadRequestError::class],
            ['decide', ['questions' => ['q' => truth('?')], 'images' => [Image::fromUrl('u', 'https://example.com:8443/a.jpg')]], BadRequestError::class],
        ];
        foreach ($refusals as [$method, $request, $class]) {
            try {
                $dn->checkRequest($method, $request);
                self::fail($method . ': no error');
            } catch (BadRequestError|ValidationError $e) {
                self::assertSame($class, get_class($e), $method);
            }
        }
        self::assertSame([], $this->history, 'a check sends and uploads nothing');
    }

    public function testCheckRequestRefusesAnUnknownMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client([])->checkRequest('gate', []);
    }

    public function testAModelTheHeldListDoesNotStateIsLeftToTheServer(): void
    {
        $dn = $this->client([self::json(200, self::models()), self::json(200, ['session_id' => 'ses_1', 'questions' => ['a']])]);
        $dn->models();
        $dn->openSession(['a' => truth('?')], model: 'decisionnode-9', window: 17);
        self::assertCount(2, $this->history);
    }

    public function testStoragePutsHaveTheirOwnTimeout(): void
    {
        $presign = self::json(201, ['uploads' => [[
            'upload_id' => 'upl_a',
            'url' => 'https://storage.example.test/obj',
            'headers' => ['Content-Type' => 'image/png'],
            'expires_at' => '2099-01-01T00:00:00Z',
        ]], 'expires_in' => 300]);
        $dn = $this->client([$presign, new Response(200), self::truthBody()], ['timeout' => 2.0, 'uploadTimeout' => 45.0]);
        $png = (string) file_get_contents(self::DIR . '3x2.png');
        $dn->decide('s', ['q' => truth('?')], [Image::fromData('a', $png . str_repeat("\0", 600_000))]);
        self::assertSame(2.0, $this->history[0]['options']['timeout']);
        self::assertSame(45.0, $this->history[1]['options']['timeout']);
        self::assertSame(2.0, $this->history[2]['options']['timeout']);
        self::assertSame(60.0, $this->client([])->uploadTimeout);
    }

    public function testUploadTimeoutMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client([], ['uploadTimeout' => 0.0]);
    }

    public function testResultsAreReadLineByLine(): void
    {
        $lines = '';
        for ($i = 0; $i < 1500; ++$i) {
            $lines .= sprintf('{"custom_id":"r%d","status":200,"body":{"answers":{"q":{"type":"truth","truth":0.5}}},"headers":{}}' . "\n", $i);
        }
        $dn = $this->client([new Response(200, ['Content-Type' => 'application/x-ndjson'], $lines), new Response(200, [], $lines)]);
        $stream = $dn->batches->stream('batch_1');
        self::assertInstanceOf(\Generator::class, $stream);
        $first = $stream->current();
        self::assertInstanceOf(BatchResult::class, $first);
        self::assertSame('r0', $first->customId);
        self::assertTrue($this->history[0]['options']['stream'] ?? false, 'the body is a stream read as it is consumed');
        $results = $dn->batches->results('batch_1');
        self::assertCount(1500, $results);
        self::assertSame('r1499', $results[1499]->customId);
    }

    public function testSessionSocketsUseTheClientsTlsAndProxySettings(): void
    {
        $transport = new GuzzleTransport(new Client([
            'verify' => '/etc/ssl/custom-ca.pem',
            'cert' => ['/etc/ssl/client.pem', 'secret'],
            'proxy' => ['https' => 'http://proxy.example.test:3128', 'no' => ['.internal.example']],
        ]));
        $settings = $transport->socketSettings();
        self::assertSame('/etc/ssl/custom-ca.pem', $settings['ssl']['cafile']);
        self::assertSame('/etc/ssl/client.pem', $settings['ssl']['local_cert']);
        self::assertSame('secret', $settings['ssl']['passphrase']);
        self::assertTrue($settings['ssl']['verify_peer']);
        self::assertTrue($settings['ssl']['verify_peer_name']);
        self::assertSame('http://proxy.example.test:3128', $settings['proxy']);
        self::assertSame(['.internal.example'], $settings['no_proxy']);

        $plain = (new GuzzleTransport(new Client()))->socketSettings();
        self::assertNull($plain['proxy']);
        self::assertTrue($plain['ssl']['verify_peer']);
    }
}
