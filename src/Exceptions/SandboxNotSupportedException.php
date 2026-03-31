<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Exceptions;

use Illuminate\Support\Str;
use LogicException;

/**
 * @internal
 */
final class SandboxNotSupportedException extends LogicException
{
    public static function make(string $gateway): self
    {
        return new self(
            sprintf('%s gateway does not support the sandbox environment.', Str::ucfirst($gateway))
        );
    }
}
