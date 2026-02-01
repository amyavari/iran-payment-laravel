<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Concerns;

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
     * Status code for verify in no-callback mode
     */
    private const No_CALLBACK_VERIFY_STATUS_CODE = '1001';

    /**
     * Status code for settle in no-callback mode
     */
    private const No_CALLBACK_SETTLE_STATUS_CODE = '1002';

    /**
     * Status code for reverse in no-callback mode
     */
    private const No_CALLBACK_REVERSE_STATUS_CODE = '1003';

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
    private function noCallbackStatusCode(string $method): string
    {
        return match ($method) {
            'verify' => self::No_CALLBACK_VERIFY_STATUS_CODE,
            'settle' => self::No_CALLBACK_SETTLE_STATUS_CODE,
            'reverse' => self::No_CALLBACK_REVERSE_STATUS_CODE,

            default => throw new LogicException('Wrong method name.'),
        };
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
    private function isNoCallbackSuccessful(string $statusCode): bool
    {
        return $statusCode === self::No_CALLBACK_REVERSE_STATUS_CODE;
    }

    /**
     * Get message for no-callback mode
     */
    private function noCallbackMessage(string $statusCode): string
    {
        return match ($statusCode) {
            self::No_CALLBACK_VERIFY_STATUS_CODE => 'درگاه از وریفای بدون callback پشتیبانی نمی کند.',
            self::No_CALLBACK_SETTLE_STATUS_CODE => 'درگاه از تسویه بدون callback پشتیبانی نمی کند.',
            self::No_CALLBACK_REVERSE_STATUS_CODE => 'تراکنش به صورت خودکار برگشت داده می شود.',

            default => throw new LogicException('Wrong status code.'),
        };
    }
}
