<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Exception;

use Bynn\DecisionNode\Safety;

/**
 * HTTP 403 with `refusal_error`: the request was refused under the usage policy.
 * Never retried. `category` is `harm` or `self_harm`; `safety` holds the scores
 * of the safety check that refused it.
 */
class RefusalError extends DecisionNodeError
{
    public function __construct(
        string $message = '',
        public readonly ?string $category = null,
        public readonly ?Safety $safety = null,
        mixed ...$fields,
    ) {
        parent::__construct($message, ...$fields);
    }
}
