<?php

declare(strict_types=1);

namespace Bynn\DecisionNode\Internal;

use Bynn\DecisionNode\Exception\AuthenticationError;
use Bynn\DecisionNode\Exception\BadRequestError;
use Bynn\DecisionNode\Exception\ContextLimitError;
use Bynn\DecisionNode\Exception\DecisionNodeError;
use Bynn\DecisionNode\Exception\InsufficientCreditError;
use Bynn\DecisionNode\Exception\NotFoundError;
use Bynn\DecisionNode\Exception\OverloadedError;
use Bynn\DecisionNode\Exception\PermissionDeniedError;
use Bynn\DecisionNode\Exception\RateLimitError;
use Bynn\DecisionNode\Exception\RefusalError;
use Bynn\DecisionNode\Exception\ServerError;
use Bynn\DecisionNode\Exception\ValidationError;
use Bynn\DecisionNode\Safety;

/**
 * Maps an error response to its exception class.
 *
 * @internal
 */
final class Errors
{
    /**
     * @param array<string, string> $headers names in lower case
     */
    public static function fromResponse(int $status, array $headers, string $text): DecisionNodeError
    {
        try {
            $body = Json::decode($text);
        } catch (\JsonException) {
            $body = null;
        }

        $detail = is_array($body) && array_key_exists('detail', $body) ? $body['detail'] : null;
        $errorType = null;
        $message = null;
        $entries = null;
        $category = null;
        if (is_array($detail) && !array_is_list($detail)) {
            $errorType = is_string($detail['error_type'] ?? null) ? $detail['error_type'] : null;
            $message = is_string($detail['message'] ?? null) ? $detail['message'] : null;
            $category = is_string($detail['category'] ?? null) ? $detail['category'] : null;
        } elseif (is_string($detail)) {
            $message = $detail;
        } elseif (is_array($detail)) {
            $entries = $detail;
        }

        // The error's own message is the server's message, unchanged; the
        // explanation in words from x-decisionnode-error is kept in `reason`.
        $fields = [
            'message' => $message ?? '',
            'status' => $status,
            'errorType' => $errorType,
            'requestId' => $headers['x-request-id'] ?? null,
            'reason' => $headers['x-decisionnode-error'] ?? null,
            'body' => $body ?? $text,
            'headers' => $headers,
        ];

        if ($status === 400) {
            return $errorType === 'max_tokens_exceeded'
                ? new ContextLimitError(...$fields)
                : new BadRequestError(...$fields);
        }
        if ($status === 401) {
            return new AuthenticationError(...$fields);
        }
        if ($status === 402) {
            return new InsufficientCreditError(...$fields);
        }
        if ($status === 403) {
            if ($errorType === 'refusal_error') {
                return new RefusalError(...$fields + [
                    'category' => $category,
                    'safety' => Safety::fromHeader($headers['x-decisionnode-safety'] ?? null),
                ]);
            }

            return new PermissionDeniedError(...$fields);
        }
        if ($status === 404) {
            return new NotFoundError(...$fields);
        }
        if ($status === 422) {
            return new ValidationError(...$fields + ['entries' => $entries]);
        }
        if ($status === 429) {
            return new RateLimitError(...$fields + ['retryAfter' => Retry::after($headers)]);
        }
        if ($status === 529) {
            return new OverloadedError(...$fields + ['retryAfter' => Retry::after($headers)]);
        }
        if ($status >= 500 && $status <= 599) {
            return new ServerError(...$fields);
        }

        // Every other status, 3xx included (redirects are never followed): the
        // base class with the status.
        return new DecisionNodeError(...$fields);
    }
}
