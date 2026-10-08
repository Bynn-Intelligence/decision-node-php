<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

use Phrity\Net\Context;
use Phrity\Net\SocketClient;
use Phrity\Net\SocketStream;
use Phrity\Net\StreamException;
use Psr\Http\Message\UriInterface;

/**
 * A socket client that reaches its host through an HTTP proxy's CONNECT tunnel.
 *
 * @internal
 */
final class TunnelSocketClient extends SocketClient
{
    public function __construct(UriInterface $uri, private readonly string $proxy, ?Context $context = null)
    {
        parent::__construct($uri, $context);
    }

    public function connect(): SocketStream
    {
        $proxy = parse_url($this->proxy);
        $proxyHost = is_array($proxy) ? (string) ($proxy['host'] ?? '') : '';
        if ($proxyHost === '') {
            throw new StreamException(StreamException::CLIENT_CONNECT_ERR, ['uri' => $this->uri]);
        }
        $proxyScheme = strtolower((string) ($proxy['scheme'] ?? 'http'));
        $proxyPort = (int) ($proxy['port'] ?? ($proxyScheme === 'https' ? 443 : 80));
        $timeout = $this->timeout ?? 10.0;
        $errno = 0;
        $error = '';
        $context = $this->context->getResource();
        $socket = @stream_socket_client(
            ($proxyScheme === 'https' ? 'ssl' : 'tcp') . '://' . $proxyHost . ':' . $proxyPort,
            $errno,
            $error,
            (float) $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (!is_resource($socket)) {
            throw new StreamException(StreamException::CLIENT_CONNECT_ERR, ['uri' => $this->uri]);
        }
        stream_set_timeout($socket, (int) ceil((float) $timeout));

        $target = $this->uri->getHost() . ':' . (string) $this->uri->getPort();
        $request = 'CONNECT ' . $target . " HTTP/1.1\r\nHost: " . $target . "\r\n";
        if (isset($proxy['user'])) {
            $request .= 'Proxy-Authorization: Basic ' . base64_encode(rawurldecode((string) $proxy['user']) . ':' . rawurldecode((string) ($proxy['pass'] ?? ''))) . "\r\n";
        }
        fwrite($socket, $request . "\r\n");
        $status = (string) fgets($socket);
        while (($line = fgets($socket)) !== false && rtrim($line, "\r\n") !== '') {
            // The proxy's response headers are not needed.
        }
        if (preg_match('~^HTTP/\d(?:\.\d)? 2\d\d~', $status) !== 1) {
            fclose($socket);
            throw new StreamException(StreamException::CLIENT_CONNECT_ERR, ['uri' => $this->uri]);
        }

        if (strtolower($this->uri->getScheme()) === 'ssl') {
            stream_context_set_option($socket, 'ssl', 'peer_name', $this->uri->getHost());
            foreach ($this->context->getOptions()['ssl'] ?? [] as $option => $value) {
                stream_context_set_option($socket, 'ssl', (string) $option, $value);
            }
            if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                fclose($socket);
                throw new StreamException(StreamException::CLIENT_CONNECT_ERR, ['uri' => $this->uri]);
            }
        }

        return new SocketStream($socket);
    }
}
