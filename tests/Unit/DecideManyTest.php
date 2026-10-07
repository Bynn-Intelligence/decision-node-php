<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\Decision;
use Bynn\DecisionNode\DecisionNode;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\ValidationError;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Promise;
use Psr\Http\Message\RequestInterface;

use function Bynn\DecisionNode\truth;

final class DecideManyTest extends TestCase
{
    public function testResultsInInputOrderWithFailuresInPlace(): void
    {
        $dn = $this->client([
            self::truthBody(0.1),
            self::json(422, ['detail' => [['type' => 'missing']]]),
            self::truthBody(0.3),
        ]);
        $results = $dn->decideMany([
            ['state' => 'a', 'questions' => ['q' => truth('a?')]],
            ['state' => 'b', 'questions' => ['q' => truth('b?')]],
            (object) ['state' => 'c', 'questions' => ['q' => truth('c?')]],
        ], concurrency: 1);

        self::assertCount(3, $results);
        self::assertInstanceOf(Decision::class, $results[0]);
        self::assertSame(0.1, $results[0]['q']->truth);
        self::assertInstanceOf(ValidationError::class, $results[1]);
        self::assertInstanceOf(Decision::class, $results[2]);
        self::assertSame(0.3, $results[2]['q']->truth);
    }

    public function testConcurrencyBelowOneIsAnArgumentError(): void
    {
        foreach ([0, -1] as $concurrency) {
            $dn = $this->client([self::truthBody()]);
            try {
                $dn->decideMany([['state' => 'a', 'questions' => ['q' => truth('a?')]]], concurrency: $concurrency);
                self::fail('no error');
            } catch (\InvalidArgumentException) {
            }
            self::assertCount(0, $this->history);
        }
    }

    public function testAnArgumentErrorInAnyRequestStopsBeforeSending(): void
    {
        $dn = $this->client([self::truthBody(0.1)]);
        try {
            $dn->decideMany([
                ['state' => 'b', 'questions' => ['q' => truth('b?')]],
                ['state' => 'a', 'questions' => []],
            ]);
            self::fail('no error');
        } catch (\InvalidArgumentException) {
        }
        self::assertCount(0, $this->history);
    }

    public function testEmptyInput(): void
    {
        self::assertSame([], $this->client([])->decideMany([]));
    }

    public function testConcurrencyIsLimited(): void
    {
        $inFlight = 0;
        $peak = 0;
        $pending = [];
        $handler = static function (RequestInterface $request) use (&$inFlight, &$peak, &$pending): Promise {
            $inFlight++;
            $peak = max($peak, $inFlight);
            $promise = new Promise(static function () use (&$promise, &$inFlight, &$pending): void {
                // Settle every request that is in flight, as a server answering them together would.
                foreach ($pending as $i => $p) {
                    unset($pending[$i]);
                    $inFlight--;
                    $p->resolve(TestCase::truthResponse());
                }
            });
            $pending[] = $promise;

            return $promise;
        };
        // Guzzle's mock handler runs the function for each request.
        $mock = new MockHandler(array_fill(0, 30, $handler));
        $dn = new DecisionNode(apiKey: 'k', baseUrl: 'https://api.example.test', httpClient: new Client(['handler' => HandlerStack::create($mock)]));
        $requests = array_map(static fn (int $i) => ['state' => (string) $i, 'questions' => ['q' => truth('?')]], range(1, 7));

        $results = $dn->decideMany($requests, concurrency: 3);
        self::assertCount(7, $results);
        self::assertContainsOnlyInstancesOf(Decision::class, $results);
        self::assertSame(3, $peak);

        $peak = 0;
        $dn->decideMany(array_merge($requests, $requests), concurrency: DecisionNode::DEFAULT_CONCURRENCY);
        self::assertSame(8, $peak);
        self::assertSame(8, DecisionNode::DEFAULT_CONCURRENCY);
    }
}
