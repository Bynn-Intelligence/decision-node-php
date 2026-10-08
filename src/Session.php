<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\ConnectionError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\NotFoundError;
use Bynn\DecisionNode\Internal\Api;
use Bynn\DecisionNode\Internal\Images;
use Bynn\DecisionNode\Internal\Json;
use Bynn\DecisionNode\Internal\LocalChecks;
use Bynn\DecisionNode\Internal\SessionSocket;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * An open session: one state and question set, answered again for every
 * frame sent on its socket. Made by {@see DecisionNode::openSession()}.
 *
 *     $session = $dn->openSession(questions: [...], state: [...]);
 *     $session->connect();
 *     $session->send(frame: ['battery' => 0.31]);
 *     foreach ($session->replies() as $event) {
 *         // a Reply, a FrameError, and last a SessionClosed
 *     }
 *     $session->end();
 *
 * Frames are sent without waiting for replies; when frames arrive faster than
 * they are answered, the latest wins and its Reply lists the superseded
 * frames in `dropped`. Pings are answered while replies are read.
 */
final class Session
{
    public readonly ?string $id;

    public readonly ?string $model;

    public readonly ?string $streamUrl;

    public readonly ?int $window;

    public readonly ?int $ttlSeconds;

    public readonly ?string $expiresAt;

    /** @var array<string, mixed> */
    public readonly array $limits;

    /** @var list<string> the question ids, in the order every reply answers them */
    public readonly array $questions;

    public readonly ?int $prefixTokens;

    public readonly Usage $usage;

    /** @var array<array-key, Answer> the answers to the opening state, by question id */
    public readonly array $answers;

    /** @var array<string, mixed>|null */
    public readonly ?array $safety;

    private ?SessionSocket $socket = null;

    private int $seq = 0;

    private ?SessionClosed $closed = null;

    /** @var list<Reply|FrameError> events read while ending, returned by next() first */
    private array $buffered = [];

    /**
     * @internal
     *
     * @param array<array-key, mixed> $raw    the open response exactly as the API sent it
     * @param array<string, mixed>    $held   the models list entry the client holds for the session's model, or []
     */
    public function __construct(
        public readonly array $raw,
        private readonly Api $api,
        private readonly Images $images,
        mixed $shape = null,
        private readonly array $held = [],
    ) {
        $int = static fn (string $key): ?int => is_int($raw[$key] ?? null) ? $raw[$key] : null;
        $string = static fn (string $key): ?string => is_string($raw[$key] ?? null) ? $raw[$key] : null;
        $this->id = $string('session_id');
        $this->model = $string('model');
        $this->streamUrl = $string('stream_url');
        $this->window = $int('window');
        $this->ttlSeconds = $int('ttl_seconds');
        $this->expiresAt = $string('expires_at');
        $this->limits = is_array($raw['limits'] ?? null) ? $raw['limits'] : [];
        $this->questions = array_values(array_filter(is_array($raw['questions'] ?? null) ? $raw['questions'] : [], 'is_string'));
        $this->prefixTokens = $int('prefix_tokens');
        $usage = is_array($raw['usage'] ?? null) ? $raw['usage'] : [];
        $this->usage = new Usage(
            is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : null,
            is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null,
        );
        $this->answers = Reply::fromArray(['answers' => $raw['answers'] ?? []], $shape)->answers;
        $this->safety = is_array($raw['safety'] ?? null) ? $raw['safety'] : null;
    }

    /**
     * Opens the session's socket. The key travels in the handshake's
     * Authorization header, never in the URL, with the client's extra
     * headers. A socket the server refuses after the handshake (an ended
     * session, another socket attached) shows as the SessionClosed that
     * `next()` and `replies()` return.
     *
     * @throws \InvalidArgumentException when the stream URL is not wss (ws only on this machine)
     * @throws DecisionNodeError         when the handshake fails
     */
    public function connect(): self
    {
        if ($this->streamUrl === null) {
            throw new \InvalidArgumentException('The session has no stream URL.');
        }
        $this->close();
        $this->closed = null;
        $this->socket = SessionSocket::open($this->streamUrl, $this->api->handshakeHeaders(), $this->api->timeout, $this->api->socketSettings());

        return $this;
    }

    /**
     * Runs every local check `send()` runs on this frame and throws the same
     * error, without sending or uploading anything. Returns when the frame
     * would be sent.
     *
     * @param Image|array<string, mixed>|\stdClass|null $image
     * @param list<string>|null                        $questions
     *
     * @throws BadRequestError for a frame the API would refuse
     */
    public function checkFrame(mixed $frame = null, Image|array|\stdClass|null $image = null, ?array $questions = null, ?bool $reset = null, ?int $seq = null): void
    {
        $known = is_array($this->raw['questions'] ?? null) ? $this->questions : null;
        LocalChecks::frame($frame, $image, $questions, $reset, $seq, $known);
        if ($image instanceof Image) {
            LocalChecks::image($image, $this->held, frame: true);
        }
    }

    /**
     * Sends one frame and returns its `seq`, counted from 1 unless given.
     * Never waits for the reply.
     *
     * `$image` follows the client's image rules: small bytes inline, large or
     * already uploaded ones by upload id (uploaded first). A frame takes no
     * image URL.
     *
     * @param Image|array<string, mixed>|\stdClass|null $image
     * @param list<string>|null                        $questions the subset of question ids this frame asks
     *
     * @throws BadRequestError  for a frame the API would refuse (an image, a question id, an empty
     *                          frame), before anything is sent; the frame takes no seq
     * @throws ConnectionError  when the socket is not open
     */
    public function send(mixed $frame = null, Image|array|\stdClass|null $image = null, ?array $questions = null, ?bool $reset = null, ?int $seq = null): int
    {
        // A frame that breaks a fixed rule is refused here: nothing goes on the
        // socket and it takes no seq.
        $this->checkFrame($frame, $image, $questions, $reset, $seq);
        if ($this->socket === null) {
            throw new ConnectionError('The session is not connected: call connect() first.');
        }
        $wireImage = null;
        if ($image instanceof Image) {
            [$wires] = $this->images->wires([$image])->wait();
            $wireImage = $wires[0];
            unset($wireImage['id']);
        } elseif ($image !== null) {
            $wireImage = $image;
        }

        if ($seq === null) {
            $seq = ++$this->seq;
        } else {
            $this->seq = max($this->seq, $seq);
        }
        $message = ['seq' => $seq];
        if ($frame !== null) {
            $message['frame'] = $frame;
        }
        if ($wireImage !== null) {
            $message['image'] = $wireImage;
        }
        if ($questions !== null) {
            $message['questions'] = $questions;
        }
        if ($reset !== null) {
            $message['reset'] = $reset;
        }
        $this->socket->send(Json::encode($message));

        return $seq;
    }

    /**
     * The next event: a Reply, a FrameError, or the SessionClosed that ends
     * the socket (returned again on every later call). Null when nothing
     * arrived within `$timeout` seconds; with no timeout it waits.
     */
    public function next(?float $timeout = null): Reply|FrameError|SessionClosed|null
    {
        if ($this->buffered !== []) {
            return array_shift($this->buffered);
        }
        if ($this->closed !== null) {
            return $this->closed;
        }
        if ($this->socket === null) {
            return $this->closed = new SessionClosed(1006, 'not connected');
        }
        $event = $this->socket->next($timeout);
        if ($event === null) {
            return null;
        }
        if ($event instanceof SessionClosed) {
            $this->socket = null;

            return $this->closed = $event;
        }

        return $this->parse($event) ?? $this->next($timeout);
    }

    private function parse(string $text): Reply|FrameError|null
    {
        try {
            $raw = Json::decode($text);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($raw)) {
            return null;
        }
        if (array_key_exists('error', $raw)) {
            return FrameError::fromArray($raw);
        }

        return Reply::fromArray($raw, Json::decodeObjects($text));
    }

    /**
     * Replies and frame errors as they arrive, ending with the SessionClosed.
     * With `$timeout`, it also ends when nothing arrives for that many seconds.
     *
     * @return \Generator<int, Reply|FrameError|SessionClosed>
     */
    public function replies(?float $timeout = null): \Generator
    {
        while (true) {
            $event = $this->next($timeout);
            if ($event === null) {
                return;
            }
            yield $event;
            if ($event instanceof SessionClosed) {
                return;
            }
        }
    }

    /**
     * Ends the session (`DELETE /v1/sessions/{id}`). A session that is
     * already gone counts as ended. When the socket is open, the server then
     * closes it with 1000 and the close is answered here; replies that arrive
     * before it are still returned by `next()` and `replies()`, followed by
     * the SessionClosed.
     */
    public function end(): void
    {
        $this->endAsync()->wait();
    }

    /**
     * Reads until the server closes the socket, keeping what arrives.
     */
    private function awaitClose(float $timeout): void
    {
        $deadline = microtime(true) + $timeout;
        while ($this->socket !== null && $this->closed === null) {
            $event = $this->socket->next(max(0.0, $deadline - microtime(true)));
            if ($event === null) {
                return;
            }
            if ($event instanceof SessionClosed) {
                $this->socket = null;
                $this->closed = $event;

                return;
            }
            $parsed = $this->parse($event);
            if ($parsed !== null) {
                $this->buffered[] = $parsed;
            }
        }
    }

    /**
     * @return PromiseInterface resolves with null
     */
    public function endAsync(): PromiseInterface
    {
        return $this->api->request('DELETE', '/v1/sessions/' . rawurlencode((string) $this->id))->then(
            function (): mixed {
                $this->awaitClose(min(2.0, $this->api->timeout));

                return null;
            },
            static function (mixed $reason): mixed {
                if ($reason instanceof NotFoundError) {
                    return null;
                }
                throw $reason instanceof \Throwable ? $reason : new DecisionNodeError('The request failed.');
            },
        );
    }

    /**
     * Closes the socket from this side (1000) without ending the session.
     */
    public function close(): void
    {
        $this->socket?->close();
        $this->socket = null;
    }

    public function isConnected(): bool
    {
        return $this->socket !== null && $this->closed === null;
    }
}
