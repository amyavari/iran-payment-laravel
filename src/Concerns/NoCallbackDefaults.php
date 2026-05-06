<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Concerns;

use AliYavari\IranPayment\Enums\InternalErrorCode;
use LogicException;

/**
 * @internal
 *
 * Provides default status responses when gateway APIs
 * cannot be called because callback payload is not provided.
 */
trait NoCallbackDefaults
{
    /**
     * Indicates callback data is not provided
     */
    private bool $noCallback = false;

    /**
     * Enable no-callback mode
     */
    private function enableNoCallback(): void
    {
        $this->noCallback = true;
    }

    /**
     * Check if no-callback mode is enabled
     */
    private function isNoCallback(): bool
    {
        return $this->noCallback;
    }

    /**
     * Get status code for no-callback mode
     */
    private function noCallbackStatusCode(string $method): int
    {
        $statusCode = match ($method) {
            'verify' => InternalErrorCode::VerifyNoCallback,
            'reverse' => InternalErrorCode::ReverseNoCallback,

            default => throw new LogicException('Wrong method name.'),
        };

        return $statusCode->value;
    }

    /**
     * Raw response for no-callback mode
     */
    private function noCallbackRawResponse(): string
    {
        return 'No API is called.';
    }

    /**
     * Determine if no-callback result should considered as successful
     */
    private function isNoCallbackSuccessful(int $statusCode): bool
    {
        return $statusCode === InternalErrorCode::ReverseNoCallback->value;
    }
}
