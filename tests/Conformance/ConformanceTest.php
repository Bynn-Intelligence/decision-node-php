<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The conformance suite: every case file is one test, run once through the
 * blocking methods and once through their Async forms. Point
 * DECISIONNODE_CONFORMANCE_DIR at a checkout of the suite; its mock server is
 * started on a free port (or set DECISIONNODE_MOCK_URL to use a running one).
 * Without the variable every test fails.
 */
final class ConformanceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (Suite::configured()) {
            Suite::startMock();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Suite::stopMock();
    }

    /** @return array<string, array{string, bool}> */
    public static function requestCases(): array
    {
        return Suite::files('requests', both: true);
    }

    /** @return array<string, array{string, bool}> */
    public static function responseCases(): array
    {
        return Suite::files('responses', both: true);
    }

    /** @return array<string, array{string, bool}> */
    public static function errorCases(): array
    {
        return Suite::files('errors', both: true);
    }

    /** @return array<string, array{string, bool}> */
    public static function behaviourCases(): array
    {
        return Suite::files('behaviour', both: true);
    }

    /** @return array<string, array{string, bool}> */
    public static function constantCases(): array
    {
        return Suite::files('constants', both: false);
    }

    #[DataProvider('requestCases')]
    public function testRequest(string $file, bool $async): void
    {
        $this->run_($file, static fn () => Suite::runRequest(Suite::load($file), $async));
    }

    #[DataProvider('responseCases')]
    public function testResponse(string $file, bool $async): void
    {
        $this->run_($file, static fn () => Suite::runResponse(Suite::load($file), $async));
    }

    #[DataProvider('errorCases')]
    public function testError(string $file, bool $async): void
    {
        $this->run_($file, static fn () => Suite::runError(Suite::load($file), $async));
    }

    #[DataProvider('behaviourCases')]
    public function testBehaviour(string $file, bool $async): void
    {
        $this->run_($file, static fn () => Suite::runBehaviour(Suite::load($file), $async));
    }

    #[DataProvider('constantCases')]
    public function testConstant(string $file, bool $async): void
    {
        $this->run_($file, static fn () => Suite::runConstant(Suite::load($file)));
    }

    private function run_(string $file, \Closure $case): void
    {
        if ($file === '') {
            // Without the suite the conformance tests fail; they never skip.
            self::fail('Set DECISIONNODE_CONFORMANCE_DIR to a checkout of the conformance suite.');
        }
        try {
            $case();
        } catch (Fail $fail) {
            self::fail($fail->getMessage());
        } finally {
            foreach (['DECISIONNODE_API_KEY', 'DECISIONNODE_BASE_URL', 'DECISIONNODE_MODEL'] as $var) {
                putenv($var);
            }
        }
        $this->addToAssertionCount(1);
    }
}
