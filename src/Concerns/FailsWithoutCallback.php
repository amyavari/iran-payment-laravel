<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Concerns;

use AliYavari\IranPayment\Enums\ApiMethod;
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
    private function withoutCallbackStatusCode(ApiMethod $method): int
    {
        $statusCode = match ($method) {
            ApiMethod::Verify => InternalErrorCode::WithoutCallbackVerify,
            ApiMethod::Reverse => InternalErrorCode::WithoutCallbackReverse,

            default => throw new LogicException(sprintf('No no-callback status code for %s method.', $method->value)),
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
        return $statusCode === InternalErrorCode::WithoutCallbackReverse->value;
    }
}
