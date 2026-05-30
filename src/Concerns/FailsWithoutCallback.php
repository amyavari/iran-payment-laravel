<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Concerns;

use AliYavari\IranPayment\Enums\InternalErrorCode;
use LogicException;

/**
 * @internal
 *
 * Provides default status responses when gateway APIs cannot
 * be called because callback payload is not provided.
 */
trait FailsWithoutCallback
{
    /**
     * Indicates callback data is not provided
     */
    private bool $withoutCallback = false;

    /**
     * Enable no-callback mode
     */
    private function enableWithoutCallback(): void
    {
        $this->withoutCallback = true;
    }

    /**
     * Check if no-callback mode is enabled
     */
    private function isWithoutCallback(): bool
    {
        return $this->withoutCallback;
    }

    /**
     * Get status code for no-callback mode
     */
    private function withoutCallbackStatusCode(string $method): int
    {
        $statusCode = match ($method) {
            'verify' => InternalErrorCode::withoutCallbackVerify,
            'reverse' => InternalErrorCode::withoutCallbackReverse,

            default => throw new LogicException('Wrong method name.'),
        };

        return $statusCode->value;
    }

    /**
     * Raw response for no-callback mode
     */
    private function withoutCallbackRawResponse(): string
    {
        return 'No API is called.';
    }

    /**
     * Determine if no-callback result should considered as successful
     */
    private function isWithoutCallbackSuccessful(int $statusCode): bool
    {
        return $statusCode === InternalErrorCode::withoutCallbackReverse->value;
    }
}
