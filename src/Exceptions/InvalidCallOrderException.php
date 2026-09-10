<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Exceptions;

use AliYavari\IranPayment\Enums\ApiMethod;
use LogicException;

/**
 * @internal
 */
final class InvalidCallOrderException extends LogicException
{
    /**
     * @param  array<ApiMethod>  $requiredMethods
     */
    public static function mustBeCalledAfter(string $attemptedMethod, array $requiredMethods): self
    {
        return new self(
            sprintf('Cannot call "%s()" before calling one of the following methods: "%s".', $attemptedMethod, self::joinMethods($requiredMethods))
        );
    }

    /**
     * @param  array<ApiMethod>  $blockingMethods
     */
    public static function mustBeCalledBefore(string $attemptedMethod, array $blockingMethods): self
    {
        return new self(
            sprintf('Cannot call "%s()" after calling one of the following methods: "%s".', $attemptedMethod, self::joinMethods($blockingMethods))
        );
    }

    /**
     * @param  array<ApiMethod>  $methods
     */
    private static function joinMethods(array $methods): string
    {
        return collect($methods)->pluck('value')->join(', ');
    }
}
