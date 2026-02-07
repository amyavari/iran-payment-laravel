<?php

declare(strict_types=1);

use AliYavari\IranPayment\Concerns\HasUniqueNumber;

uses(HasUniqueNumber::class);

it('generates 15-digit unique transaction ID', function (): void {
    $orderIds = collect([]);

    for ($i = 1; $i <= 20; $i++) {
        freezeTimeUntilSeconds();

        $orderIds->push($this->generateUniqueTimeBaseNumber());
    }

    $uniqueIds = $orderIds->unique();

    expect($uniqueIds)
        ->toHaveLength(20)
        ->each->toHaveLength(15);
});

// ------------
// Helpers
// ------------

function freezeTimeUntilSeconds(): void
{
    $microSeconds = (int) (microtime(true) * 1000) % 1000;

    setTestNowIran("2025-12-10 18:30:10.{$microSeconds}");
}
