<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Exceptions;

use Exception;

/**
 * @internal
 */
final class PaymentAlreadyVerifiedException extends Exception
{
    public static function make(string $transactionId): self
    {
        return new self(
            sprintf('Payment with transaction ID "%s" has already been verified.', $transactionId)
        );
    }
}
