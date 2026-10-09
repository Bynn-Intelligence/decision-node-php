<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\ContextLimitError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\PermissionDeniedError;
use Bynn\DecisionNode\Exception\RefusalError;
use Bynn\DecisionNode\Exception\RequestTooLargeError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\Image;
use GuzzleHttp\Psr7\Response;

use function Bynn\DecisionNode\truth;

final class NonJsonErrorTest extends TestCase
{
    public function testAnErrorBodyThatIsNotJsonIsClassedByItsStatusAlone(): void
    {
        $cases = [
            [413, null, RequestTooLargeError::class, 'HTTP 413 Content Too Large'],
            [400, 'req_1', BadRequestError::class, 'HTTP 400 Bad Request (request id req_1)'],
            [403, null, PermissionDeniedError::class, 'HTTP 403 Forbidden'],
            [422, null, ValidationError::class, 'HTTP 422 Unprocessable Content'],
            [418, 'req_2', DecisionNodeError::class, 'HTTP 418 (request id req_2)'],
        ];
        foreach ($cases as [$status, $id, $class, $message]) {
            foreach (['<html><body>busy</body></html>', ''] as $body) {
                $headers = ['content-type' => 'text/html', 'x-decisionnode-error' => 'from the edge'] + ($id === null ? [] : ['x-request-id' => $id]);
                $dn = $this->client([new Response($status, $headers, $body)]);
                try {
                    $dn->decide(questions: ['q' => truth('?')]);
                    self::fail((string) $status);
                } catch (DecisionNodeError $e) {
                    self::assertSame($class, $e::class, (string) $status);
                    self::assertNotInstanceOf(ContextLimitError::class, $e);
                    self::assertNotInstanceOf(RefusalError::class, $e);
                    self::assertSame([$status, null, $message, $id, 'from the edge', null], [$e->status, $e->errorType, $e->getMessage(), $e->requestId, $e->reason, $e->body]);
                    self::assertCount(1, $this->history);
                }
            }
        }
    }

    public function testAJsonEnvelopeWithoutAMessageKeepsAnEmptyMessage(): void
    {
        $dn = $this->client([self::json(400, new \stdClass())]);
        try {
            $dn->decide(questions: ['q' => truth('?')]);
            self::fail('400');
        } catch (BadRequestError $e) {
            self::assertSame('', $e->getMessage());
        }
    }

    public function testAnImageIdOfAtMost64Characters(): void
    {
        $dn = $this->client([]);
        $url = 'https://files.example.com/a.jpg';
        foreach ([str_repeat('a', 64), str_repeat("\u{20000}", 64)] as $id) {
            $dn->checkRequest('decide', ['questions' => ['q' => truth('?')], 'images' => [Image::fromUrl($id, $url)]]);
        }
        $long = str_repeat('a', 65);
        foreach ([[Image::fromUrl('first', $url), Image::fromUrl($long, $url)], [Image::fromUrl($long, $url), Image::fromUrl($long, $url)]] as $images) {
            $want = sprintf('Image %d: its id is longer than 64 characters.', $images[0]->id === $long ? 0 : 1);
            try {
                $dn->checkRequest('decide', ['questions' => ['q' => truth('?')], 'images' => $images]);
                self::fail($want);
            } catch (BadRequestError $e) {
                self::assertSame([$want, 'api_usage_error'], [$e->getMessage(), $e->errorType]);
            }
        }
        self::assertCount(0, $this->history);
    }
}
