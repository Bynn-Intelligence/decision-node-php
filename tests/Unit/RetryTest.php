<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\DecisionNode;
use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\ConnectionError;
use Bynn\DecisionNode\Exception\OverloadedError;
use Bynn\DecisionNode\Exception\TimeoutError;
use Bynn\DecisionNode\Internal\Retry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

use function Bynn\DecisionNode\truth;

final class RetryTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function retried(): iterable
    {
        foreach ([408, 429, 500, 502, 503, 504, 520, 529, 599] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('retried')]
    public function testRetriedStatuses(int $status): void
    {
        $dn = $this->client([self::json($status, ['detail' => 'busy'], ['retry-after-ms' => '1']), self::truthBody()]);
        self::assertSame(0.94, $dn->decide('s', ['q' => truth('?')])['q']->truth);
        self::assertCount(2, $this->history);
        self::assertSame(1, $this->history[1]['options']['delay']);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function neverRetried(): iterable
    {
        foreach ([400, 401, 402, 403, 404, 422] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('neverRetried')]
    public function testNeverRetriedStatuses(int $status): void
    {
        $dn = $this->client([self::json($status, ['detail' => 'no']), self::truthBody()]);
        try {
            $dn->decide('s', ['q' => truth('?')]);
            self::fail('no error');
        } catch (\Bynn\DecisionNode\Exception\DecisionNodeError $e) {
            self::assertSame($status, $e->status);
        }
        self::assertCount(1, $this->history);
    }

    public function testRetriesStopAtMaxRetries(): void
    {
        $busy = fn () => self::json(529, ['detail' => ['error_type' => 'overloaded_error']], ['retry-after-ms' => '1']);
        $dn = $this->client([$busy(), $busy(), $busy(), $busy(), $busy()], ['maxRetries' => 4]);
        $this->expectException(OverloadedError::class);
        try {
            $dn->decide('s', ['q' => truth('?')]);
        } finally {
            self::assertCount(5, $this->history);
        }
    }

    public function testResultComesFromTheFinalAttempt(): void
    {
        $dn = $this->client([self::json(503, ['detail' => 'busy'], ['retry-after-ms' => '1', 'x-request-id' => 'req_first']), self::truthBody()]);
        self::assertSame('req_1', $dn->decide('s', ['q' => truth('?')])->requestId);
    }

    public function testConnectionErrorsAreRetriedThenRaised(): void
    {
        $drop = static fn () => new ConnectException('Empty reply from server', new Request('POST', 'https://api.example.test/v1/decide'), null, ['errno' => 52]);
        $dn = $this->client([$drop(), $drop()], ['maxRetries' => 1]);
        $this->expectException(ConnectionError::class);
        try {
            $dn->decide('s', ['q' => truth('?')]);
        } finally {
            self::assertCount(2, $this->history);
            self::assertGreaterThan(0, $this->history[1]['options']['delay']);
        }
    }

    public function testTimeoutsBecomeTimeoutError(): void
    {
        $timeout = new ConnectException('Operation timed out after 300 milliseconds', new Request('POST', 'https://api.example.test/v1/decide'), null, ['errno' => 28]);
        $dn = $this->client([$timeout], ['maxRetries' => 0, 'timeout' => 0.3]);
        try {
            $dn->decide('s', ['q' => truth('?')]);
            self::fail('no error');
        } catch (TimeoutError $e) {
            self::assertStringContainsString('0.3 s', $e->getMessage());
        }
        self::assertSame(0.3, $this->history[0]['options']['timeout']);
    }

    public function testTimeoutThenSuccess(): void
    {
        $timeout = new ConnectException('timed out', new Request('POST', 'https://api.example.test/v1/decide'), null, ['errno' => 28]);
        $dn = $this->client([$timeout, self::truthBody()], ['maxRetries' => 1]);
        self::assertSame(0.94, $dn->decide('s', ['q' => truth('?')])['q']->truth);
    }

    public function testRetryAfterParsing(): void
    {
        self::assertSame(0.2, Retry::after(['retry-after-ms' => '200', 'retry-after' => '1']));
        self::assertSame(1.0, Retry::after(['retry-after' => '1']));
        self::assertSame(0.0, Retry::after(['retry-after' => '-4']));
        self::assertNull(Retry::after([]));
        self::assertNull(Retry::after(['retry-after' => 'soon-ish']));
        $date = Retry::after(['retry-after' => gmdate('D, d M Y H:i:s', time() + 30) . ' GMT']);
        self::assertNotNull($date);
        self::assertGreaterThan(25.0, $date);
        self::assertSame(0.0, Retry::after(['retry-after' => 'Sun, 06 Nov 1994 08:49:37 GMT']));
        self::assertSame(0.0, Retry::after(['retry-after' => 'Sunday, 06-Nov-94 08:49:37 GMT']));
        self::assertSame(0.0, Retry::after(['retry-after' => 'Sun Nov  6 08:49:37 1994']));
    }

    public function testServerWaitsAreCappedAtSixtySeconds(): void
    {
        self::assertSame(60.0, Retry::after(['retry-after-ms' => '600000']));
        self::assertSame(60.0, Retry::after(['retry-after' => '3600']));
        self::assertSame(60.0, Retry::after(['retry-after' => gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT']));
        self::assertSame(59.5, Retry::after(['retry-after-ms' => '59500']));
    }

    public function testTheCappedWaitIsWhatTheClientSchedules(): void
    {
        $waits = [];
        $handler = new MockHandler([
            self::json(529, ['detail' => ['error_type' => 'overloaded_error']], ['retry-after-ms' => '600000']),
            self::truthBody(),
        ]);
        $stack = HandlerStack::create($handler);
        // Record the wait and send at once, so the test does not take a minute.
        $stack->unshift(static function (callable $next) use (&$waits): \Closure {
            return static function (RequestInterface $request, array $options) use ($next, &$waits) {
                if (array_key_exists('delay', $options)) {
                    $waits[] = $options['delay'];
                    unset($options['delay']);
                }

                return $next($request, $options);
            };
        });
        $dn = new DecisionNode(apiKey: self::KEY, baseUrl: 'https://api.example.test', httpClient: new Client(['handler' => $stack]));
        self::assertSame(0.94, $dn->decide('s', ['q' => truth('?')])['q']->truth);
        self::assertSame([60000], $waits);
    }

    public function testBackoffIsExponentialWithJitterAndCapped(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $first = Retry::backoff(0);
            self::assertGreaterThanOrEqual(0.375, $first);
            self::assertLessThanOrEqual(0.5, $first);
            $second = Retry::backoff(1);
            self::assertGreaterThanOrEqual(0.75, $second);
            self::assertLessThanOrEqual(1.0, $second);
            self::assertLessThanOrEqual(8.0, Retry::backoff(10));
            self::assertGreaterThanOrEqual(6.0, Retry::backoff(10));
        }
    }

    public function testNoRetryOnBadRequestEvenWithRetryHeaders(): void
    {
        $dn = $this->client([self::json(400, ['detail' => 'x'], ['retry-after-ms' => '1']), self::truthBody()]);
        $this->expectException(BadRequestError::class);
        $dn->decide('s', ['q' => truth('?')]);
    }
}
