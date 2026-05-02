<?php

declare(strict_types=1);

use AliYavari\IranPayment\Services\TimeBasedUniqueNumberGenerator;

it('generates 15-digit unique number', function (): void {
    $orderIds = collect([]);

    $uniqueNumber = new TimeBasedUniqueNumberGenerator();

    for ($i = 1; $i <= 50; $i++) {
        freezeTimeUntilSeconds();

        $orderIds->push($uniqueNumber->generate());
    }

    $uniqueIds = $orderIds->unique();

    /**
     * Why 49 unique numbers are acceptable: Since iteration is very fast, more IDs may be generated
     * in the same millisecond than in production, so one duplicate is acceptable.
     *
     * Why `all()` before `each`: Pest modifiers don't restore the original subject and break
     * higher-order expectations, So we called a safe collection method before it.
     */
    expect($uniqueIds)
        ->count()->toBeGreaterThanOrEqual(49)
        ->all()->each->toBeString()->toHaveLength(15);
});

// ------------
// Helpers
// ------------

function freezeTimeUntilSeconds(): void
{
    $milliseconds = (int) (microtime(true) * 1000) % 1000;

    setTestNowIran("2025-12-10 18:30:10.{$milliseconds}");
}
