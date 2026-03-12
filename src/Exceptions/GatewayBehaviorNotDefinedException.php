<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Exceptions;

use LogicException;

/**
 * @internal
 */
final class GatewayBehaviorNotDefinedException extends LogicException
{
    public static function make(string $gateway, string $method): self
    {
        return new self(
            sprintf('No behavior has been defined for the "%s" method on the fake driver "%s".', $method, $gateway)
        );
    }
}
