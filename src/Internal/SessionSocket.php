<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

use Bynn\DecisionNode\Exception\ConnectionError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\TimeoutError;
use Bynn\DecisionNode\SessionClosed;
use Psr\Http\Message\MessageInterface;
use WebSocket\Client;
use WebSocket\Connection;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Exception\ExceptionInterface;
use WebSocket\Exception\HandshakeException;
use WebSocket\Message\Close;
use WebSocket\Message\Text;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;
use WebSocket\Middleware\ProcessHttpIncomingInterface;
use WebSocket\Middleware\ProcessHttpStack;

/**
 * One session socket over phrity/websocket, blocking. Pings are answered
 * while messages are read; a close frame or a lost connection becomes a
 * SessionClosed. Redirects are never followed and TLS certificates are
 * checked by PHP's defaults.
 *
 * @internal
 */
final class SessionSocket implements ProcessHttpIncomingInterface
{
    private const LOOPBACK = ['localhost', '127.0.0.1', '::1'];

    private ?Connection $connection = null;

    private function __construct(private readonly Client $client)
    {
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws \InvalidArgumentException|DecisionNodeError
     */
    public static function open(string $url, array $headers, float $timeout): self
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($host === '' || !($scheme === 'wss' || ($scheme === 'ws' && in_array($host, self::LOOPBACK, true)))) {
            throw new \InvalidArgumentException(sprintf('The stream URL must be wss (ws only on this machine), got "%s".', $url));
        }

        $client = new Client($url);
        $client->setTimeout($timeout);
        $socket = new self($client);
        $client->addMiddleware(new CloseHandler());
        $client->addMiddleware(new PingResponder());
        $client->addMiddleware($socket);
        foreach ($headers as $name => $value) {
            $client->addHeader($name, $value);
        }
        try {
            $client->connect();
        } catch (ExceptionInterface $e) {
            if ($e instanceof HandshakeException) {
                $status = $e->getResponse()->getStatusCode();

                throw new DecisionNodeError(sprintf('The session socket was refused with HTTP %d.', $status), status: $status, previous: $e);
            }
            if ($e instanceof ConnectionTimeoutException) {
                throw new TimeoutError('The session socket timed out while connecting.', previous: $e);
            }

            throw new ConnectionError('Connection error: ' . $e->getMessage(), previous: $e);
        }

        return $socket;
    }

    public function __toString(): string
    {
        return 'decisionnode-session-socket';
    }

    /**
     * Keeps the connection of the handshake, so `next()` can wait on its socket.
     */
    public function processHttpIncoming(ProcessHttpStack $stack, Connection $connection): MessageInterface
    {
        $this->connection = $connection;

        return $stack->handleHttpIncoming();
    }

    /**
     * @throws ConnectionError when the socket is gone
     */
    public function send(string $text): void
    {
        try {
            $this->client->send(new Text($text));
        } catch (ExceptionInterface $e) {
            throw new ConnectionError('The session socket is closed: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * The next text message, or the SessionClosed that ends the socket, or
     * null when nothing arrived within `$timeout` seconds.
     */
    public function next(?float $timeout): string|SessionClosed|null
    {
        $deadline = $timeout === null ? null : microtime(true) + max(0.0, $timeout);
        while (true) {
            if (!$this->readable($deadline)) {
                return null;
            }
            try {
                $message = $this->client->receive();
            } catch (ConnectionTimeoutException) {
                return null;
            } catch (ExceptionInterface) {
                $this->disconnect();

                return new SessionClosed(1006, 'the connection was lost');
            }
            if ($message instanceof Close) {
                $this->disconnect();

                return new SessionClosed($message->getCloseStatus() ?? 1005, $message->getContent());
            }
            if ($message instanceof Text) {
                return $message->getContent();
            }
            // Pings were answered by the responder; pongs and binary messages carry nothing.
        }
    }

    public function close(): void
    {
        try {
            if ($this->client->isConnected()) {
                $this->client->send(new Close(1000, 'closed by the client'));
            }
        } catch (ExceptionInterface) {
            // Already gone.
        }
        $this->disconnect();
    }

    private function disconnect(): void
    {
        try {
            $this->client->disconnect();
        } catch (\Throwable) {
            // Already gone.
        }
    }

    /**
     * Waits until a message can be read or the deadline passes. PHP reports
     * a stream with buffered bytes as readable at once.
     */
    private function readable(?float $deadline): bool
    {
        $resource = $this->connection?->getStream()->getResource();
        if (!is_resource($resource)) {
            return true;
        }
        while (true) {
            $read = [$resource];
            $write = null;
            $except = null;
            if ($deadline === null) {
                $seconds = null;
                $micro = null;
            } else {
                $left = max(0.0, $deadline - microtime(true));
                $seconds = (int) floor($left);
                $micro = (int) (($left - $seconds) * 1_000_000);
            }
            $ready = @stream_select($read, $write, $except, $seconds, $micro);
            if ($ready === false) {
                return true;
            }
            if ($ready > 0) {
                return true;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                return false;
            }
        }
    }
}
