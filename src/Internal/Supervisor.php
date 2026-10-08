<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

use Bynn\DecisionNode\FrameError;
use Bynn\DecisionNode\Reply;
use Bynn\DecisionNode\Session;
use Bynn\DecisionNode\SessionClosed;

/**
 * Runs `supervise`: one feed, sessions opened again on the codes that ask
 * for it.
 *
 * @internal
 */
final class Supervisor
{
    /** Codes on which the next session opens at once, without a backoff. */
    private const AT_ONCE = [4408, 4410];

    private ?Session $session = null;

    private ?Session $previous = null;

    private ?SessionClosed $last = null;

    private int $failures = 0;

    private int $opened = 0;

    private int $answered = 0;

    private int $pending = 0;

    /**
     * @param \Closure(?float): Session              $open    opens and connects a session after the given delay (seconds)
     * @param \Closure(Reply|FrameError): void       $onReply
     * @param \Closure(float): void                  $sleep
     */
    public function __construct(
        private readonly \Closure $open,
        private readonly \Closure $onReply,
        private readonly \Closure $sleep,
        private readonly int $maxSessions,
        private readonly float $finalWait,
    ) {
    }

    /**
     * @param iterable<array<string, mixed>|object> $feed
     */
    public function run(iterable $feed): ?SessionClosed
    {
        foreach ($feed as $reading) {
            $this->drain(0.0);
            $session = $this->ensureSession();
            if ($session === null) {
                return $this->last;
            }
            $args = is_array($reading) ? $reading : get_object_vars($reading);
            $session->send(...$args);
            ++$this->pending;
        }
        $this->drain($this->finalWait);
        if ($this->session !== null) {
            $this->session->end();
            $this->session->close();
        }

        return $this->last;
    }

    /**
     * The session to send on: the current one, or a new one when the last
     * close asks for it. Null when supervising stops.
     */
    private function ensureSession(): ?Session
    {
        while ($this->session === null) {
            $last = $this->last;
            if ($last !== null && !$last->reopen && $last->closeCode !== 1013) {
                return null;
            }
            if ($this->opened >= $this->maxSessions) {
                return null;
            }
            $delay = null;
            if ($last !== null && !in_array($last->closeCode, self::AT_ONCE, true)) {
                if ($this->answered > 0) {
                    $this->failures = 0;
                }
                $delay = min(30.0, 2 ** $this->failures) * (0.5 + (mt_rand() / mt_getrandmax()) * 0.5);
                ++$this->failures;
            }
            $previous = $this->previous;
            if ($last !== null && $last->closeCode === 1013 && $previous !== null) {
                // The session is kept: connect to it again.
                ($this->sleep)($delay ?? 0.0);
                $this->session = $previous->connect();
            } else {
                $this->session = ($this->open)($delay);
                ++$this->opened;
            }
            $this->previous = $this->session;
            $this->answered = 0;
            $this->pending = 0;
            $this->last = null;
        }

        return $this->session;
    }

    /**
     * Hands every event that has arrived to onReply; with a wait, keeps
     * reading until the outstanding frames are answered or the wait is over.
     */
    private function drain(float $wait): void
    {
        $deadline = microtime(true) + $wait;
        while ($this->session !== null) {
            $event = $this->session->next(max(0.0, $deadline - microtime(true)));
            if ($event === null) {
                return;
            }
            if ($event instanceof SessionClosed) {
                $this->last = $event;
                $this->session = null;

                return;
            }
            $this->pending = max(0, $this->pending - 1 - count($event instanceof Reply ? ($event->dropped ?? []) : []));
            if ($event instanceof Reply) {
                ++$this->answered;
            }
            ($this->onReply)($event);
            if ($wait > 0.0 && $this->pending === 0) {
                return;
            }
        }
    }
}
