<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Exception;

/**
 * HTTP 400 with `max_tokens_exceeded`: the request is larger than the model's input limit (see `models()`).
 */
class ContextLimitError extends BadRequestError
{
}
