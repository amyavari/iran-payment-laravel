<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Exceptions;

use LogicException;

/**
 * @internal
 */
final class ApiIsNotCalledException extends LogicException
{
    public static function make(): self
    {
        return new self('You must call an API method before checking its status.');
    }
}
