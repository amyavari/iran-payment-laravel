<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Services;

use AliYavari\IranPayment\Contracts\UniqueNumberGenerator;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * @internal
 */
final class TimeBasedUniqueNumberGenerator implements UniqueNumberGenerator
{
    /**
     * Generate a random 15-digit, time-based number.
     */
    public function generate(): string
    {
        $randomNumber = random_int(1_000, 9_999);

        /**
         * Logic: Use a custom Epoch (starting from Jan 1, 2025) to reduce
         * the timestamp's digit count (from 13 to 11 digits). Safe until ~2029
         */
        $epoch = Carbon::make('2025-01-01 00:00:00.000')->getTimestampMs();
        $now = now()->getTimestampMs();

        $millisecondsSinceEpoch = $now - $epoch;

        return (string) Str::of((string) $millisecondsSinceEpoch)
            ->append((string) $randomNumber);
    }
}
