<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Concerns;

use Illuminate\Support\Str;

/**
 * @internal
 *
 * Has methods to generate unique time based numbers
 */
trait HasUniqueNumber
{
    /**
     * Generate a random 15-digit, time-based transaction ID.
     */
    protected function generateUniqueTimeBaseNumber(): string
    {
        $randomNumber = random_int(1_000, 9_999);

        $currentTimeInMillisecond = now()->getTimestampMs();

        // The first `17` of timestamp doesn't add anything unique.
        // So, we remove it to have more space for random digits.
        return (string) Str::of((string) $currentTimeInMillisecond)
            ->after('17')
            ->append((string) $randomNumber);
    }
}
