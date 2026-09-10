<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Exceptions;

use Illuminate\Support\Str;
use LogicException;

/**
 * @internal
 */
final class CannotConvertToTomanException extends LogicException
{
    public static function make(string $gateway, int $amount): self
    {
        return new self(
            sprintf('%s gateway only supports Toman, so the Rial amount must be a multiple of 10. "%s" given.', Str::ucfirst($gateway), $amount)
        );
    }
}
