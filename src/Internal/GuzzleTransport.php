<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

use Bynn\DecisionNode\Exception\ConnectionError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\TimeoutError;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Handler\Proxy;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends requests through Guzzle without blocking, so `decideMany` runs its
 * requests side by side and waits between retries do not hold other requests.
 *
 * The same rules hold for the default client and for a client the caller
 * passes in: redirects are never followed, and the timeout covers the whole
 * attempt, from sending the request to the last byte of the body. A caller's
 * client is accepted only when it is shown to obey them (see accept()).
 *
 * @internal
 */
final class GuzzleTransport implements Transport
{
    private const CURLE_OPERATION_TIMEDOUT = 28;

    /** Guzzle's own handlers, which follow no redirect themselves. */
    private const KNOWN_HANDLERS = [
        CurlHandler::class,
        CurlMultiHandler::class,
        StreamHandler::class,
        MockHandler::class,
    ];

    /**
     * @param bool $https whether the base URL is https, so TLS checks must stay on
     *
     * @throws \InvalidArgumentException for a client that cannot be shown to refuse redirects,
     *                                   or that turns off TLS certificate or host name checks
     */
    public function __construct(private readonly ClientInterface $client, bool $https = true)
    {
        self::accept($client, $https);
    }

    /**
     * Accepts a client only when the SDK can show that it never follows a
     * redirect and keeps TLS checks on.
     *
     * The client must be a GuzzleHttp\Client, whose redirects the per-request
     * `allow_redirects` option switches off. Its handler, the code that does
     * the transfer, must be one of Guzzle's own (curl, curl multi, stream,
     * mock), alone or composed by Guzzle's Proxy: any other handler might
     * follow a redirect by itself and is refused. Middleware in the handler
     * stack is the caller's own code and is not inspected; a response it
     * fetched from another URL, or a request it sent more than once in one
     * attempt, is refused when the response arrives (see check()).
     *
     * @throws \InvalidArgumentException
     */
    public static function accept(ClientInterface $client, bool $https): void
    {
        if (get_class($client) !== Client::class) {
            throw new \InvalidArgumentException(sprintf(
                'httpClient must be a GuzzleHttp\Client, got %s: the SDK cannot show that another client never follows a redirect.',
                get_class($client),
            ));
        }
        $handler = self::clientConfig($client, 'handler');
        if ($handler instanceof HandlerStack) {
            $handler = self::stackHandler($handler);
        }
        if (!self::knownHandler($handler, 0)) {
            throw new \InvalidArgumentException(sprintf(
                'httpClient uses a handler of type %s, which the SDK cannot show never follows a redirect. Use one of Guzzle\'s own handlers (curl, stream or mock).',
                get_debug_type($handler),
            ));
        }
        if ($https) {
            self::checkTls($client);
        }
    }

    /**
     * Refuses transport settings that turn off TLS certificate or host name
     * checks, so the API key is only ever sent to the real server.
     */
    private static function checkTls(Client $client): void
    {
        $off = [];
        if (self::clientConfig($client, RequestOptions::VERIFY) === false) {
            $off[] = '"verify" => false';
        }
        $curl = self::clientConfig($client, RequestOptions::CURL);
        if (is_array($curl)) {
            foreach (['CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_PROXY_SSL_VERIFYPEER', 'CURLOPT_PROXY_SSL_VERIFYHOST'] as $name) {
                if (defined($name) && array_key_exists(constant($name), $curl) && !$curl[constant($name)]) {
                    $off[] = $name . ' off';
                }
            }
        }
        $context = self::clientConfig($client, 'stream_context');
        $ssl = is_array($context) && is_array($context['ssl'] ?? null) ? $context['ssl'] : [];
        foreach (['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true] as $key => $unsafe) {
            if (array_key_exists($key, $ssl) && (bool) $ssl[$key] === $unsafe) {
                $off[] = 'stream_context ssl ' . $key . ' ' . var_export($unsafe, true);
            }
        }
        if ($off !== []) {
            throw new \InvalidArgumentException(sprintf(
                'httpClient turns off TLS checks (%s). The base URL is https, so certificate and host name checks must stay on.',
                implode(', ', $off),
            ));
        }
    }

    private static function stackHandler(HandlerStack $stack): mixed
    {
        try {
            $property = new \ReflectionProperty(HandlerStack::class, 'handler');

            return $property->getValue($stack);
        } catch (\ReflectionException) {
            return null;
        }
    }

    /**
     * Whether `$handler` is one of Guzzle's own handlers, or a composition of
     * them made by Guzzle's Proxy.
     */
    private static function knownHandler(mixed $handler, int $depth): bool
    {
        if (is_object($handler) && in_array(get_class($handler), self::KNOWN_HANDLERS, true)) {
            return true;
        }
        if (!$handler instanceof \Closure || $depth > 4) {
            return false;
        }
        $function = new \ReflectionFunction($handler);
        if ($function->getClosureScopeClass()?->getName() !== Proxy::class) {
            return false;
        }
        $captured = $function->getStaticVariables();
        if ($captured === []) {
            return false;
        }
        foreach ($captured as $inner) {
            if (!self::knownHandler($inner, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    private static function clientConfig(Client $client, string $option): mixed
    {
        try {
            return $client->getConfig($option);
        } catch (\Throwable) {
            return null;
        }
    }

    public function send(RequestInterface $request, ?float $delay, float $timeout): PromiseInterface
    {
        /** @var TransferStats|null $stats */
        $stats = null;
        $transfers = 0;
        $callerOnStats = $this->config(RequestOptions::ON_STATS);

        $options = [
            RequestOptions::HTTP_ERRORS => false,
            // With curl, this is the limit for the whole transfer, body included.
            RequestOptions::TIMEOUT => $timeout,
            RequestOptions::CONNECT_TIMEOUT => $timeout,
            RequestOptions::EXPECT => false,
            // Never follow a redirect: the API key must not go to another URL
            // or over plain HTTP. A 3xx comes back as an error with its status.
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::ON_STATS => static function (TransferStats $transfer) use (&$stats, &$transfers, $callerOnStats): void {
                $stats = $transfer;
                ++$transfers;
                if (is_callable($callerOnStats)) {
                    $callerOnStats($transfer);
                }
            },
        ];
        if (defined('CURLOPT_FOLLOWLOCATION')) {
            // A client's own curl options are kept, except that curl must not
            // follow a redirect by itself either.
            $curl = $this->config(RequestOptions::CURL);
            $curl = is_array($curl) ? $curl : [];
            $curl[\CURLOPT_FOLLOWLOCATION] = false;
            $options[RequestOptions::CURL] = $curl;
        }
        if ($delay !== null) {
            // The wait between attempts is Guzzle's own `delay` option, so it
            // does not block other requests in flight.
            $options[RequestOptions::DELAY] = $delay * 1000;
        }

        try {
            $promise = $this->client->sendAsync($request, $options);
        } catch (\Throwable $e) {
            return Create::rejectionFor(self::map($e, $timeout));
        }

        return $promise->then(
            static function (ResponseInterface $response) use (&$stats, &$transfers, $request, $timeout): ResponseInterface {
                self::check($request, $stats, $transfers, $timeout);

                return $response;
            },
            static function (mixed $reason) use ($timeout): never {
                throw self::map($reason, $timeout);
            },
        );
    }

    /**
     * Refuses a response that came from following a redirect (another URL,
     * or more than one transfer in one attempt, as a middleware that re-sends
     * would make), or that took longer than the timeout (a handler other than
     * curl may only time out between reads).
     */
    private static function check(RequestInterface $request, ?TransferStats $stats, int $transfers, float $timeout): void
    {
        if ($transfers > 1) {
            throw new DecisionNodeError(
                'The HTTP client sent the request more than once in one attempt. Redirects are never followed, so the API key is not sent to another URL.',
            );
        }
        if ($stats === null) {
            return;
        }
        $redirects = $stats->getHandlerStat('redirect_count');
        $sent = $request->getUri();
        $answered = $stats->getEffectiveUri();
        $moved = strtolower($answered->getScheme()) !== strtolower($sent->getScheme())
            || strtolower($answered->getHost()) !== strtolower($sent->getHost())
            || $answered->getPort() !== $sent->getPort();
        if ($moved || (is_int($redirects) && $redirects > 0)) {
            throw new DecisionNodeError(
                'The HTTP client followed a redirect. Redirects are never followed, so the API key is not sent to another URL.',
            );
        }
        $seconds = $stats->getTransferTime();
        if ($seconds !== null && $seconds > $timeout) {
            throw new TimeoutError(sprintf('The request timed out after %s s.', self::seconds($timeout)));
        }
    }

    private function config(string $option): mixed
    {
        try {
            return $this->client->getConfig($option);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function map(mixed $reason, float $timeout): \Throwable
    {
        if ($reason instanceof DecisionNodeError) {
            return $reason;
        }
        if ($reason instanceof TransferException) {
            $context = $reason instanceof RequestException ? $reason->getHandlerContext() : [];
            $timedOut = ($context['errno'] ?? null) === self::CURLE_OPERATION_TIMEDOUT
                || stripos($reason->getMessage(), 'timed out') !== false;
            if ($timedOut) {
                return new TimeoutError(sprintf('The request timed out after %s s.', self::seconds($timeout)), previous: $reason);
            }

            return new ConnectionError('Connection error: ' . $reason->getMessage(), previous: $reason);
        }
        if ($reason instanceof \Throwable) {
            return $reason;
        }

        return new ConnectionError('Connection error.');
    }

    private static function seconds(float $timeout): string
    {
        return rtrim(rtrim(sprintf('%.3f', $timeout), '0'), '.');
    }
}
