<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

use Bynn\DecisionNode\Exception\AuthenticationError;
use Bynn\DecisionNode\Exception\ConnectionError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\ServerError;
use Bynn\DecisionNode\Exception\TimeoutError;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends API requests with the client's key, headers, timeout and retry rules,
 * and the storage PUTs of an upload without any of them.
 *
 * @internal
 */
final class Api
{
    /** Retried as every API call: 408, 429, 5xx, timeouts and connection errors. */
    public const RETRY_DEFAULT = 'default';

    /**
     * Batch create, add and finalize: retried only on 429 and 529, which the
     * API answers before applying anything; never after a timeout, a lost
     * answer or another 5xx, since a repeat could apply twice.
     */
    public const RETRY_MUTATION = 'mutation';

    /** Opening a session: as the default, but a 429 session_limit_exceeded is never retried. */
    public const RETRY_SESSION_OPEN = 'session_open';

    /**
     * @param array<string, string> $headers the caller's extra headers
     */
    public function __construct(
        public readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly array $headers,
        public readonly float $timeout,
        public readonly int $maxRetries,
        private readonly Transport $transport,
        private readonly string $userAgent,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Sends with retries.
     *
     * @param float|null $delay seconds to wait before the first attempt
     *
     * @return PromiseInterface resolves with ["status" => int, "headers" => array<string, string>, "body" => mixed, "text" => string];
     *                          with `$json` false, "body" is null and "text" is the raw body
     */
    public function request(
        string $method,
        string $path,
        ?string $body = null,
        string $policy = self::RETRY_DEFAULT,
        string $contentType = 'application/json',
        bool $json = true,
        ?float $delay = null,
    ): PromiseInterface {
        if ($this->apiKey === null) {
            return Create::rejectionFor(new AuthenticationError(
                'No API key: pass apiKey to the client or set DECISIONNODE_API_KEY.',
            ));
        }

        $attempt = function (int $n, ?float $wait) use (&$attempt, $method, $path, $body, $policy, $contentType, $json): PromiseInterface {
            $request = $this->buildRequest($method, $path, $body, $contentType);
            $started = microtime(true) + ($wait ?? 0.0);

            return $this->transport->send($request, $wait, $this->timeout)->then(
                function (ResponseInterface $response) use (&$attempt, $n, $method, $path, $started, $policy, $json): mixed {
                    $status = $response->getStatusCode();
                    $headers = self::headers($response);
                    $this->log($method, $path, $n, $status, $headers['x-request-id'] ?? null, $started);

                    if ($status >= 200 && $status < 300) {
                        $text = (string) $response->getBody();
                        if (!$json) {
                            return ['status' => $status, 'headers' => $headers, 'body' => null, 'text' => $text];
                        }
                        try {
                            $parsed = Json::decode($text);
                        } catch (\JsonException $e) {
                            throw new ServerError(
                                sprintf('HTTP %d: the response body is not JSON.', $status),
                                status: $status,
                                requestId: $headers['x-request-id'] ?? null,
                                reason: $headers['x-decisionnode-error'] ?? null,
                                body: $text,
                                headers: $headers,
                                previous: $e,
                            );
                        }

                        return ['status' => $status, 'headers' => $headers, 'body' => $parsed, 'text' => $text];
                    }

                    $text = (string) $response->getBody();
                    if ($n < $this->maxRetries && self::retryable($policy, $status, $text)) {
                        return $attempt($n + 1, Retry::after($headers) ?? Retry::backoff($n));
                    }

                    throw Errors::fromResponse($status, $headers, $text);
                },
                function (mixed $reason) use (&$attempt, $n, $method, $path, $started, $policy): mixed {
                    if ($reason instanceof ConnectionError || $reason instanceof TimeoutError) {
                        $this->log($method, $path, $n, null, null, $started, $reason->getMessage());
                        if ($n < $this->maxRetries && $policy !== self::RETRY_MUTATION) {
                            return $attempt($n + 1, Retry::backoff($n));
                        }
                    }

                    throw $reason instanceof \Throwable ? $reason : new ConnectionError('The request failed.');
                },
            );
        };

        return $attempt(0, $delay);
    }

    private static function retryable(string $policy, int $status, string $text): bool
    {
        if ($policy === self::RETRY_MUTATION) {
            return $status === 429 || $status === 529;
        }
        if ($policy === self::RETRY_SESSION_OPEN && $status === 429 && Errors::errorType($text) === 'session_limit_exceeded') {
            return false;
        }

        return Retry::retryable($status);
    }

    /**
     * One storage PUT of an upload: to the presigned URL, with exactly the
     * headers the API returned and the bytes. No key, none of the client's
     * headers, no redirect followed. Retried on 5xx and connection errors; a
     * 412 on a retry means an earlier attempt stored the object (it is
     * written once), so it is success.
     *
     * @param array<string, string> $headers
     *
     * @return PromiseInterface resolves with null
     */
    public function put(string $url, array $headers, string $bytes): PromiseInterface
    {
        $attempt = function (int $n, ?float $wait) use (&$attempt, $url, $headers, $bytes): PromiseInterface {
            $request = new Request('PUT', $url, $headers, $bytes);
            $started = microtime(true) + ($wait ?? 0.0);
            $path = (string) parse_url($url, PHP_URL_PATH);

            return $this->transport->send($request, $wait, $this->timeout, true)->then(
                function (ResponseInterface $response) use (&$attempt, $n, $started, $path): mixed {
                    $status = $response->getStatusCode();
                    $this->log('PUT', $path, $n, $status, null, $started);
                    if ($status >= 200 && $status < 300) {
                        return null;
                    }
                    if ($status === 412 && $n > 0) {
                        return null;
                    }
                    if ($status >= 500 && $status <= 599 && $n < $this->maxRetries) {
                        return $attempt($n + 1, Retry::backoff($n));
                    }
                    $text = (string) $response->getBody();
                    $code = preg_match('~<Code>([^<]*)</Code>~', $text, $m) === 1 ? $m[1] : null;

                    throw new DecisionNodeError(
                        sprintf('The upload to storage failed with HTTP %d%s.', $status, $code !== null ? ' (' . $code . ')' : ''),
                        status: $status,
                        errorType: 'upload_failed',
                        reason: $code,
                        body: $text,
                        headers: self::headers($response),
                    );
                },
                function (mixed $reason) use (&$attempt, $n, $started, $path): mixed {
                    if ($reason instanceof ConnectionError || $reason instanceof TimeoutError) {
                        $this->log('PUT', $path, $n, null, null, $started, $reason->getMessage());
                        if ($n < $this->maxRetries) {
                            return $attempt($n + 1, Retry::backoff($n));
                        }
                    }

                    throw $reason instanceof \Throwable ? $reason : new ConnectionError('The upload failed.');
                },
            );
        };

        return $attempt(0, null);
    }

    /**
     * The headers of a session's WebSocket handshake: the key, the User-Agent
     * and the caller's extra headers.
     *
     * @return array<string, string>
     *
     * @throws AuthenticationError when there is no API key
     */
    public function handshakeHeaders(): array
    {
        if ($this->apiKey === null) {
            throw new AuthenticationError('No API key: pass apiKey to the client or set DECISIONNODE_API_KEY.');
        }

        return ['Authorization' => 'Bearer ' . $this->apiKey, 'User-Agent' => $this->userAgent] + $this->headers;
    }

    private function buildRequest(string $method, string $path, ?string $body, string $contentType): Request
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'User-Agent' => $this->userAgent,
            'Accept' => 'application/json',
        ];
        if ($body !== null) {
            $headers['Content-Type'] = $contentType;
        }
        $request = new Request($method, $this->baseUrl . $path, $headers, $body);
        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader((string) $name, $value);
        }

        return $request;
    }

    /**
     * @return array<string, string>
     */
    public static function headers(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }

        return $headers;
    }

    public function log(string $method, string $path, int $attempt, ?int $status, ?string $requestId, float $started, ?string $error = null): void
    {
        if ($this->logger === null) {
            return;
        }
        $ms = (int) round(max(0.0, microtime(true) - $started) * 1000);
        $this->logger->debug(
            sprintf(
                'decisionnode: %s %s %s request_id=%s %d ms attempt=%d',
                $method,
                $path,
                $status === null ? 'no response (' . ($error ?? 'error') . ')' : (string) $status,
                $requestId ?? '-',
                $ms,
                $attempt + 1,
            ),
            ['method' => $method, 'path' => $path, 'status' => $status, 'request_id' => $requestId, 'duration_ms' => $ms, 'attempt' => $attempt + 1],
        );
    }
}
