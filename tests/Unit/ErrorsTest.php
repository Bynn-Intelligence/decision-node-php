<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Unit;

use Bynn\DecisionNode\Exception\AuthenticationError;
use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\ContextLimitError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\InsufficientCreditError;
use Bynn\DecisionNode\Exception\NotFoundError;
use Bynn\DecisionNode\Exception\OverloadedError;
use Bynn\DecisionNode\Exception\PermissionDeniedError;
use Bynn\DecisionNode\Exception\RateLimitError;
use Bynn\DecisionNode\Exception\RefusalError;
use Bynn\DecisionNode\Exception\ServerError;
use Bynn\DecisionNode\Exception\ValidationError;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;

use function Bynn\DecisionNode\truth;

final class ErrorsTest extends TestCase
{
    /**
     * @return iterable<string, array{int, mixed, class-string<DecisionNodeError>}>
     */
    public static function statuses(): iterable
    {
        yield '400 string detail' => [400, ['detail' => 'Too many levels.'], BadRequestError::class];
        yield '400 context limit' => [400, ['detail' => ['error_type' => 'max_tokens_exceeded']], ContextLimitError::class];
        yield '401' => [401, ['detail' => ['error_type' => 'authentication_error', 'message' => 'Bad key.']], AuthenticationError::class];
        yield '402' => [402, ['detail' => ['error_type' => 'insufficient_credit_error', 'message' => 'No credit.']], InsufficientCreditError::class];
        yield '403 refusal' => [403, ['detail' => ['error_type' => 'refusal_error', 'category' => 'harm', 'message' => 'Refused.']], RefusalError::class];
        yield '403 other' => [403, ['detail' => ['error_type' => 'permission_error']], PermissionDeniedError::class];
        yield '404' => [404, ['detail' => ['error_type' => 'not_found_error', 'message' => 'Not found.']], NotFoundError::class];
        yield '405' => [405, ['detail' => 'Method Not Allowed'], DecisionNodeError::class];
        yield '409' => [409, ['detail' => ['error_type' => 'conflict', 'message' => 'Conflict.']], DecisionNodeError::class];
        yield '302' => [302, ['detail' => ['message' => 'Moved.']], DecisionNodeError::class];
        yield '422' => [422, ['detail' => [['type' => 'missing', 'loc' => ['body', 'questions'], 'msg' => 'Field required']]], ValidationError::class];
        yield '429' => [429, ['detail' => ['error_type' => 'rate_limit_error']], RateLimitError::class];
        yield '500' => [500, ['detail' => ['error_type' => 'api_error']], ServerError::class];
        yield '502 not json' => [502, '<html>Bad gateway</html>', ServerError::class];
        yield '529' => [529, ['detail' => ['error_type' => 'overloaded_error']], OverloadedError::class];
    }

    #[DataProvider('statuses')]
    public function testStatusMapsToClass(int $status, mixed $body, string $class): void
    {
        $dn = $this->client([self::json($status, $body, ['x-request-id' => 'req_e', 'x-decisionnode-error' => 'why'])], ['maxRetries' => 0]);
        try {
            $dn->decide('s', ['q' => truth('?')]);
            self::fail('no error');
        } catch (DecisionNodeError $e) {
            self::assertSame($class, $e::class);
            self::assertSame($status, $e->status);
            self::assertSame('req_e', $e->requestId);
            self::assertSame('why', $e->reason);
        }
        self::assertCount(1, $this->history);
    }

    public function testRedirectsAreNeverFollowed(): void
    {
        $dn = $this->client([self::json(307, ['detail' => ['message' => 'Moved.']], ['location' => 'https://elsewhere.example.test/v1/decide'])]);
        try {
            $dn->decide('s', ['q' => truth('?')]);
            self::fail('no error');
        } catch (DecisionNodeError $e) {
            self::assertSame(DecisionNodeError::class, $e::class);
            self::assertSame(307, $e->status);
            self::assertSame('Moved.', $e->getMessage());
        }
        self::assertCount(1, $this->history);
        self::assertFalse($this->history[0]['options']['allow_redirects']);
    }

    public function testFieldsFromAnObjectDetail(): void
    {
        $dn = $this->client([self::json(401, ['detail' => ['error_type' => 'authentication_error', 'message' => 'Bad key.']])]);
        try {
            $dn->decide('s', ['q' => truth('?')]);
            self::fail('no error');
        } catch (AuthenticationError $e) {
            self::assertSame('authentication_error', $e->errorType);
            self::assertSame('Bad key.', $e->getMessage());
            self::assertNull($e->reason);
        }
    }

    public function testMessageIsTheServerMessageAndReasonTheHeader(): void
    {
        $dn = $this->client([self::json(400, ['detail' => ['error_type' => 'invalid_request_error', 'message' => 'A score question takes 1 to 10 levels.']], [
            'x-decisionnode-error' => "question 'q': a score takes up to 10 levels, got 11",
        ])]);
        try {
            $dn->decide('s', ['q' => truth('?')]);
            self::fail('no error');
        } catch (BadRequestError $e) {
            self::assertSame('A score question takes 1 to 10 levels.', $e->getMessage());
            self::assertSame("question 'q': a score takes up to 10 levels, got 11", $e->reason);
        }
    }

    public function testFieldsFromAStringDetail(): void
    {
        $dn = $this->client([self::json(400, ['detail' => 'Too many choices.'])]);
        try {
            $dn->decide('s', ['q' => truth('?')]);
            self::fail('no error');
        } catch (BadRequestError $e) {
            self::assertNull($e->errorType);
            self::assertSame('Too many choices.', $e->getMessage());
        }
    }

    public function testRefusalCarriesCategoryAndSafety(): void
    {
        $dn = $this->client([self::json(403, ['detail' => ['error_type' => 'refusal_error', 'category' => 'self_harm']], [
            'x-decisionnode-safety' => 'harm=0.011, self_harm=0.951;blocked',
        ])]);
        try {
            $dn->decide('s', ['q' => truth('?')]);
            self::fail('no error');
        } catch (RefusalError $e) {
            self::assertSame('self_harm', $e->category);
            self::assertNotNull($e->safety);
            self::assertSame(['self_harm'], $e->safety->flagged);
            self::assertSame(0.951, $e->safety->selfHarm);
        }
        self::assertCount(1, $this->history);
    }

    public function testValidationEntries(): void
    {
        $entries = [['type' => 'missing', 'loc' => ['body', 'questions'], 'msg' => 'Field required']];
        $dn = $this->client([self::json(422, ['detail' => $entries])]);
        try {
            $dn->decide('s', ['q' => truth('?')]);
            self::fail('no error');
        } catch (ValidationError $e) {
            self::assertSame($entries, $e->entries);
            self::assertSame('', $e->getMessage());
        }
    }

    public function testRetryAfterPrefersMilliseconds(): void
    {
        $dn = $this->client([self::json(429, ['detail' => ['error_type' => 'rate_limit_error']], ['retry-after' => '3', 'retry-after-ms' => '1500'])], ['maxRetries' => 0]);
        try {
            $dn->decide('s', ['q' => truth('?')]);
            self::fail('no error');
        } catch (RateLimitError $e) {
            self::assertSame(1.5, $e->retryAfter);
        }

        $dn = $this->client([self::json(529, ['detail' => ['error_type' => 'overloaded_error']], ['retry-after' => '3'])], ['maxRetries' => 0]);
        try {
            $dn->decide('s', ['q' => truth('?')]);
            self::fail('no error');
        } catch (OverloadedError $e) {
            self::assertSame(3.0, $e->retryAfter);
        }

        $dn = $this->client([new Response(529)], ['maxRetries' => 0]);
        try {
            $dn->decide('s', ['q' => truth('?')]);
            self::fail('no error');
        } catch (OverloadedError $e) {
            self::assertNull($e->retryAfter);
        }
    }

    public function testSuccessWithANonJsonBodyIsAServerError(): void
    {
        $dn = $this->client([new Response(200, [], 'not json')]);
        $this->expectException(ServerError::class);
        $dn->decide('s', ['q' => truth('?')]);
    }
}
