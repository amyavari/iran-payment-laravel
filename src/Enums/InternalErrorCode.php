<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Enums;

/**
 * @internal
 *
 * API error codes defined by this package.
 * Used when returning internal errors instead of calling the gateway.
 */
enum InternalErrorCode: int
{
    case WithoutCallbackVerify = 9100;
    case WithoutCallbackReverse = 9110;

    case ReverseNotSupported = 9200;

    case InvalidAmount = 9300;

    /**
     * Get the message for the given error code.
     */
    public static function getMessage(int $code): ?string
    {
        return self::tryFrom($code)?->message();
    }

    /**
     * Get message of this error code
     */
    private function message(): string
    {
        return match ($this) {
            self::WithoutCallbackVerify => 'درگاه از وریفای بدون callback پشتیبانی نمی کند.',
            self::WithoutCallbackReverse => 'تراکنش به صورت خودکار برگشت داده می شود.',
            self::ReverseNotSupported => 'درگاه از بازگشت وجه پشتیبانی نمی کند',
            self::InvalidAmount => 'مبلغ پرداخت شده نامعتبر است',
        };
    }
}
