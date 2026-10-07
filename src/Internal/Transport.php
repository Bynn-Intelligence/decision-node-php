<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Sends one HTTP request. The promise resolves with the response, whatever its
 * status, or is rejected with a ConnectionError or TimeoutError when no
 * response arrived.
 *
 * @internal
 */
interface Transport
{
    /**
     * @param float|null $delay   seconds to wait before sending a retry; null on the first attempt
     * @param float      $timeout seconds the whole attempt may take, above 0
     */
    public function send(RequestInterface $request, ?float $delay, float $timeout): PromiseInterface;
}
