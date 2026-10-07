<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\DecisionNode;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected const KEY = 'dn_test_unit';

    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    protected array $history = [];

    protected MockHandler $handler;

    protected function setUp(): void
    {
        foreach (['DECISIONNODE_API_KEY', 'DECISIONNODE_BASE_URL', 'DECISIONNODE_MODEL'] as $var) {
            putenv($var);
        }
        $this->history = [];
    }

    protected function tearDown(): void
    {
        foreach (['DECISIONNODE_API_KEY', 'DECISIONNODE_BASE_URL', 'DECISIONNODE_MODEL'] as $var) {
            putenv($var);
        }
    }

    /**
     * A client whose Guzzle handler answers from `$queue` (responses or exceptions).
     *
     * @param list<mixed>          $queue
     * @param array<string, mixed> $args
     */
    protected function client(array $queue, array $args = []): DecisionNode
    {
        $this->history = [];
        $this->handler = new MockHandler($queue);
        $stack = HandlerStack::create($this->handler);
        $stack->push(Middleware::history($this->history));

        return new DecisionNode(...($args + [
            'apiKey' => self::KEY,
            'baseUrl' => 'https://api.example.test',
            'httpClient' => new Client(['handler' => $stack]),
        ]));
    }

    /**
     * @param array<string, string> $headers
     */
    protected static function json(int $status, mixed $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, is_string($body) ? $body : json_encode($body, JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function truthResponse(): Response
    {
        return self::truthBody();
    }

    protected static function truthBody(float $truth = 0.94, string $id = 'q'): Response
    {
        return self::json(200, [
            'model' => 'decisionnode-1.0',
            'answers' => [$id => ['type' => 'truth', 'truth' => $truth]],
            'usage' => ['input_tokens' => 9, 'output_tokens' => 0],
        ], [
            'x-request-id' => 'req_1',
            'x-decisionnode-model' => 'decisionnode-1.0',
            'x-decisionnode-latency-ms' => '23',
            'x-decisionnode-safety' => 'harm=0.031, self_harm=0.004',
        ]);
    }

    protected function sent(int $index = 0): RequestInterface
    {
        return $this->history[$index]['request'];
    }

    /**
     * @return mixed the request body decoded with objects kept as \stdClass
     */
    protected function sentBody(int $index = 0): mixed
    {
        return json_decode((string) $this->sent($index)->getBody(), false, 512, JSON_THROW_ON_ERROR);
    }
}
