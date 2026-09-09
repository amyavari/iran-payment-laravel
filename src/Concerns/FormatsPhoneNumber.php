<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Concerns;

use Illuminate\Support\Str;

/**
 * @internal
 *
 * Provides the phone number formats expected by the gateways.
 */
trait FormatsPhoneNumber
{
    /**
     * Convert the phone number to the national format, like 9123456789.
     */
    protected function toNationalPhone(string|int $phone): string
    {
        return (string) Str::of((string) $phone)
            ->chopStart('+')
            ->chopStart('98')
            ->chopStart('0');
    }

    /**
     * Convert the phone number to the local format, like 09123456789.
     */
    protected function toLocalPhone(string|int $phone): string
    {
        return '0'.$this->toNationalPhone($phone);
    }

    /**
     * Convert the phone number to the international format, like 989123456789.
     */
    protected function toInternationalPhone(string|int $phone): string
    {
        return '98'.$this->toNationalPhone($phone);
    }
}
