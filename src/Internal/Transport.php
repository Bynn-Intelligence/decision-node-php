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
     * @param bool       $bare    send exactly the request's own headers, none of the HTTP client's defaults
     * @param bool       $stream  resolve when the headers have arrived, with a body read as it is consumed
     */
    public function send(RequestInterface $request, ?float $delay, float $timeout, bool $bare = false, bool $stream = false): PromiseInterface;

    /**
     * The HTTP client's TLS and proxy settings, for a session socket opened
     * outside the HTTP client: `ssl` stream context options and the proxy
     * URL for wss, with the hosts that bypass it.
     *
     * @return array{ssl: array<string, mixed>, proxy: string|null, no_proxy: list<string>}
     */
    public function socketSettings(): array;
}
