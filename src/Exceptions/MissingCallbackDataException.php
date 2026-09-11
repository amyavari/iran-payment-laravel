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
    public static function missingKey(string $gateway, array $requiredKeys, string $key): self
    {
        return new self(
            sprintf('To create %s gateway instance from callback, "%s" are required. "%s" is missing.', $gateway, Arr::join($requiredKeys, ', '), $key)
        );
    }

    /**
     * @param  array<string>  $requiredKeys
     */
    public static function emptyValue(string $gateway, array $requiredKeys, string $key): self
    {
        return new self(
            sprintf('To create %s gateway instance from callback, "%s" are required. "%s" is empty.', $gateway, Arr::join($requiredKeys, ', '), $key)
        );
    }
}
