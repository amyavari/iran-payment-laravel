<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Exceptions;

use Exception;

/**
 * @internal
 */
final class InvalidCallbackDataException extends Exception
{
    public static function make(string $callbackKey, string $storedKey): self
    {
        return new self(
            sprintf('"%s" in the callback does not match with "%s" in the stored gateway payload.', $callbackKey, $storedKey)
        );
    }
}
