<?php

declare(strict_types=1);

use AliYavari\IranPayment\Concerns\HasUniqueNumber;

uses(HasUniqueNumber::class);

it('generates 15-digit unique transaction ID', function (): void {
    $orderIds = collect([]);

    for ($i = 1; $i <= 50; $i++) {
        freezeTimeUntilSeconds();

        $orderIds->push($this->generateUniqueTimeBaseNumber());
    }

    $uniqueIds = $orderIds->unique();

    expect($uniqueIds)
        ->toHaveLength(50)
        ->each->toHaveLength(15);
});
