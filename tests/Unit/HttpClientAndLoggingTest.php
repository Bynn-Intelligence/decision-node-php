<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\DecisionNode;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\TimeoutError;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\TransferStats;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;

use function Bynn\DecisionNode\truth;

final class HttpClientAndLoggingTest extends TestCase
{
    public function testACallersClientSetToFollowRedirectsDoesNotFollowThem(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(302, ['Location' => 'http://elsewhere.example.test/steal']),
            self::truthBody(),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack, 'allow_redirects' => ['max' => 5]]);
        $dn = new DecisionNode(apiKey: 'k', baseUrl: 'https://api.example.test', maxRetries: 0, httpClient: $client);

        try {
            $dn->decide('s', ['q' => truth('?')]);
            self::fail('no error');
        } catch (DecisionNodeError $e) {
            self::assertSame(DecisionNodeError::class, $e::class);
            self::assertSame(302, $e->status);
        }
        self::assertCount(1, $history);
        self::assertSame('api.example.test', $history[0]['request']->getUri()->getHost());
    }

    public function testACallersCurlOptionsAreKeptButCurlNeverFollows(): void
    {
        if (!defined('CURLOPT_FOLLOWLOCATION')) {
            self::markTestSkipped('needs the curl extension');
        }
        $history = [];
        $stack = HandlerStack::create(new MockHandler([self::truthBody()]));
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack, 'curl' => [\CURLOPT_FOLLOWLOCATION => true, \CURLOPT_MAXREDIRS => 3]]);
        $dn = new DecisionNode(apiKey: 'k', baseUrl: 'https://api.example.test', httpClient: $client);
        $dn->decide('s', ['q' => truth('?')]);

        $curl = $history[0]['options']['curl'];
        self::assertFalse($curl[\CURLOPT_FOLLOWLOCATION]);
        self::assertSame(3, $curl[\CURLOPT_MAXREDIRS]);
        self::assertFalse($history[0]['options']['allow_redirects']);
    }

    public function testAResponseFromAnotherHostIsRefused(): void
    {
        $dn = $this->withStats(static fn (RequestInterface $r): TransferStats => new TransferStats(
            $r->withUri(new Uri('http://elsewhere.example.test/v1/decide')),
            self::truthBody(),
            0.01,
        ));
        $this->expectException(DecisionNodeError::class);
        $this->expectExceptionMessage('followed a redirect');
        $dn->decide('s', ['q' => truth('?')]);
    }

    public function testAResponseAfterARedirectCountIsRefused(): void
    {
        $dn = $this->withStats(static fn (RequestInterface $r): TransferStats => new TransferStats($r, self::truthBody(), 0.01, null, ['redirect_count' => 1]));
        $this->expectException(DecisionNodeError::class);
        $dn->decide('s', ['q' => truth('?')]);
    }

    public function testTheTimeoutCoversTheWholeAttempt(): void
    {
        // A handler that finished the response after the timeout, as one that
        // only times out between reads can: the attempt is a timeout.
        $dn = $this->withStats(static fn (RequestInterface $r): TransferStats => new TransferStats($r, self::truthBody(), 2.5), timeout: 2.0);
        $this->expectException(TimeoutError::class);
        $dn->decide('s', ['q' => truth('?')]);
    }

    public function testAMiddlewareThatSendsTheRequestTwiceIsRefused(): void
    {
        $stack = HandlerStack::create(new MockHandler([self::truthBody(), self::truthBody()]));
        $stack->push(static function (callable $next): \Closure {
            return static fn (RequestInterface $request, array $options) => $next($request, $options)
                ->then(static fn () => $next($request->withUri(new Uri('https://api.example.test/elsewhere')), $options));
        });
        $dn = new DecisionNode(apiKey: 'k', baseUrl: 'https://api.example.test', maxRetries: 0, httpClient: new Client(['handler' => $stack]));
        $this->expectException(DecisionNodeError::class);
        $this->expectExceptionMessage('more than once');
        $dn->decide('s', ['q' => truth('?')]);
    }

    public function testAnUnknownHandlerOrClientTypeIsRefused(): void
    {
        $handler = static fn (RequestInterface $request, array $options) => Create::promiseFor(self::truthBody());
        $clients = [
            'a bare function as handler' => new Client(['handler' => $handler]),
            'a function in a handler stack' => new Client(['handler' => HandlerStack::create($handler)]),
            'a subclass of Client' => new class () extends Client {
            },
        ];
        foreach ($clients as $name => $client) {
            try {
                new DecisionNode(apiKey: 'k', httpClient: $client);
                self::fail('accepted ' . $name);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('redirect', $e->getMessage(), $name);
            }
        }
    }

    public function testGuzzlesOwnHandlersAreAccepted(): void
    {
        foreach ([new Client(), new Client(['handler' => HandlerStack::create(new MockHandler())]), new Client(['handler' => new MockHandler()])] as $client) {
            self::assertInstanceOf(DecisionNode::class, new DecisionNode(apiKey: 'k', httpClient: $client));
        }
    }

    public function testAClientThatTurnsOffTlsChecksIsRefused(): void
    {
        $configs = [
            'verify' => ['verify' => false],
            'stream_context' => ['stream_context' => ['ssl' => ['verify_peer' => false]]],
            'self-signed' => ['stream_context' => ['ssl' => ['allow_self_signed' => true]]],
        ];
        if (defined('CURLOPT_SSL_VERIFYPEER')) {
            $configs['curl peer'] = ['curl' => [\CURLOPT_SSL_VERIFYPEER => false]];
            $configs['curl host'] = ['curl' => [\CURLOPT_SSL_VERIFYHOST => 0]];
        }
        foreach ($configs as $name => $config) {
            try {
                new DecisionNode(apiKey: 'k', httpClient: new Client($config));
                self::fail('accepted ' . $name);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('TLS', $e->getMessage(), $name);
            }
        }
        // A CA bundle of the caller's own keeps checks on.
        self::assertInstanceOf(DecisionNode::class, new DecisionNode(apiKey: 'k', httpClient: new Client(['verify' => __FILE__])));
        // For a local http test server there is no TLS to check.
        self::assertInstanceOf(DecisionNode::class, new DecisionNode(apiKey: 'k', baseUrl: 'http://localhost:8787', httpClient: new Client(['verify' => false])));
    }

    public function testAPsr18ClientIsNotAccepted(): void
    {
        $psr18 = new class () implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
        $this->expectException(\TypeError::class);
        new DecisionNode(apiKey: 'k', httpClient: $psr18); // @phpstan-ignore argument.type
    }

    public function testDebugLoggingHasNoStateOrQuestions(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<array{string, string, array<mixed>}> */
            public array $lines = [];

            public function log($level, $message, array $context = []): void
            {
                $this->lines[] = [(string) $level, (string) $message, $context];
            }
        };
        $dn = $this->client(
            [self::json(529, ['detail' => 'busy'], ['retry-after-ms' => '1', 'x-request-id' => 'req_a']), self::truthBody()],
            ['logger' => $logger],
        );
        $dn->decide('a secret state', ['q' => truth('a secret question')]);

        self::assertCount(2, $logger->lines);
        [$level, $message, $context] = $logger->lines[0];
        self::assertSame('debug', $level);
        self::assertStringContainsString('POST /v1/decide 529 request_id=req_a', $message);
        self::assertSame(['method', 'path', 'status', 'request_id', 'duration_ms', 'attempt'], array_keys($context));
        self::assertStringContainsString('200 request_id=req_1', $logger->lines[1][1]);
        foreach ($logger->lines as [, $line]) {
            self::assertStringNotContainsString('secret', $line);
        }
    }

    /**
     * A client whose mock handler answers with a decision, its transfer
     * reported with the stats `$stats` builds.
     *
     * @param \Closure(RequestInterface): TransferStats $stats
     */
    private function withStats(\Closure $stats, float $timeout = 10.0): DecisionNode
    {
        $stack = HandlerStack::create(new MockHandler([self::truthBody()]));
        $stack->push(static function (callable $next) use ($stats): \Closure {
            return static function (RequestInterface $request, array $options) use ($next, $stats) {
                $report = $options['on_stats'];
                $options['on_stats'] = static fn () => $report($stats($request));

                return $next($request, $options);
            };
        });

        return new DecisionNode(
            apiKey: 'k',
            baseUrl: 'https://api.example.test',
            timeout: $timeout,
            maxRetries: 0,
            httpClient: new Client(['handler' => $stack]),
        );
    }
}
