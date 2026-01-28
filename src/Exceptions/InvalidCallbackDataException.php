<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Exceptions;

use LogicException;

/**
 * @internal
 */
final class InvalidCallbackDataException extends LogicException
{
    public static function make(string $callbackKey, string $storedKey): self
    {
        return new self(
            sprintf('"%s" in the callback doesn\'t match with "%s" in the stored gateway payload.', $callbackKey, $storedKey)
        );
    }
}
