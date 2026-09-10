<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Exceptions;

use AliYavari\IranPayment\Enums\ApiMethod;
use LogicException;

/**
 * @internal
 */
final class GatewayBehaviorNotDefinedException extends LogicException
{
    public static function make(string $gateway, ApiMethod $method): self
    {
        return new self(
            sprintf('No behavior has been defined for the "%s" method on the fake driver "%s".', $method->value, $gateway)
        );
    }
}
