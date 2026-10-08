<?php

declare(strict_types=1);

namespace Bynn\DecisionNode;

/**
 * The end of a session's socket: the close code, the reason the server gave,
 * and whether a supervisor should open a new session (`reopen`).
 *
 * It is the last value `Session::replies()` yields. `reopen` is true for 1001
 * (server going away), 1006 (connection lost), 1011 (server error), 4404 (no
 * such session), 4408 (idle), 4409 (a socket is already attached) and 4410
 * (the session's time is up). On 1013 the session is kept and the client
 * connects to it again; every other code is final.
 */
final class SessionClosed extends \RuntimeException
{
    /** Codes on which a supervisor opens a new session. */
    public const REOPEN_CODES = [1001, 1006, 1011, 4404, 4408, 4409, 4410];

    public readonly bool $reopen;

    public function __construct(
        public readonly int $closeCode,
        public readonly string $reason = '',
    ) {
        parent::__construct(sprintf('The session closed with %d%s.', $closeCode, $reason !== '' ? ': ' . $reason : ''), $closeCode);
        $this->reopen = in_array($closeCode, self::REOPEN_CODES, true);
    }
}
