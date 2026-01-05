<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Facades;

use AliYavari\IranPayment\PaymentManager;
use Illuminate\Support\Facades\Facade;

final class Payment extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return PaymentManager::class;
    }
}
