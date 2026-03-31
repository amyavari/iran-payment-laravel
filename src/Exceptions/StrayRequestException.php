<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Exceptions;

use LogicException;

/**
 * @internal
 */
final class StrayRequestException extends LogicException
{
    public static function make(string $url): self
    {
        return new self(
            sprintf('Attempted request to "%s" without a matching fake.', $url)
        );
    }
}
