<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Exceptions;

use Illuminate\Support\Arr;
use LogicException;

/**
 * @internal
 */
final class InvalidCallOrderException extends LogicException
{
    /**
     * @param  array<string>  $requiredMethods
     */
    public static function make(string $attemptedMethod, array $requiredMethods): self
    {
        return new self(
            sprintf('Cannot call "%s()" before calling one of the following methods: "%s".', $attemptedMethod, Arr::join($requiredMethods, ', '))
        );
    }
}
