<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

use Phrity\Net\Context;
use Phrity\Net\SocketClient;
use Phrity\Net\StreamFactory;
use Psr\Http\Message\UriInterface;

/**
 * Opens a session socket through the HTTP client's proxy: a CONNECT tunnel to
 * the stream's host, then TLS to that host over the tunnel, with the same
 * certificate checks as a direct connection.
 *
 * @internal
 */
final class TunnelSocketFactory extends StreamFactory
{
    public function __construct(private readonly string $proxy)
    {
        parent::__construct();
    }

    public function createSocketClient(UriInterface $uri, ?Context $context = null): SocketClient
    {
        return new TunnelSocketClient($uri, $this->proxy, $context);
    }
}
