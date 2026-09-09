<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Exceptions;

use Illuminate\Support\Arr;
use LogicException;

/**
 * @internal
 */
final class MissingCallbackDataException extends LogicException
{
    /**
     * @param  array<string>  $requiredKeys
     */
    public static function make(string $gateway, array $requiredKeys, string $missingKey): self
    {
        return new self(
            sprintf('To create %s gateway instance from callback, "%s" are required. "%s" is missing.', $gateway, Arr::join($requiredKeys, ', '), $missingKey)
        );
    }
}
