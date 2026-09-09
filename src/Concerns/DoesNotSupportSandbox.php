<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Concerns;

use AliYavari\IranPayment\Exceptions\SandboxNotSupportedException;

/**
 * @internal
 *
 * Provides the sandbox guard for gateways that do not support it.
 *
 * @phpstan-require-extends \AliYavari\IranPayment\Abstracts\Driver
 */
trait DoesNotSupportSandbox
{
    /**
     * Throws an exception if configured to use sandbox.
     *
     * @throws SandboxNotSupportedException
     */
    private function guardAgainstSandbox(): void
    {
        if ($this->useSandbox()) {
            throw SandboxNotSupportedException::make($this->getGateway());
        }
    }
}
