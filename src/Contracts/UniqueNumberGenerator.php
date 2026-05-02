<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Contracts;

/**
 * @internal
 */
interface UniqueNumberGenerator
{
    /**
     * Generates unique number
     */
    public function generate(): string;
}
