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
    case VerifyNoCallback = 9100;
    case ReverseNoCallback = 9110;

    case ReverseNotSupport = 9200;

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
            self::VerifyNoCallback => 'درگاه از وریفای بدون callback پشتیبانی نمی کند.',
            self::ReverseNoCallback => 'تراکنش به صورت خودکار برگشت داده می شود.',
            self::ReverseNotSupport => 'درگاه از بازگشت وجه پشتیبانی نمی کند',
            self::InvalidAmount => 'مبلغ پرداخت شده نامعتبر است',
        };
    }
}
