<?php

declare(strict_types=1);

use AliYavari\IranPayment\Drivers\FakeDriver;
use AliYavari\IranPayment\PaymentManager;
use AliYavari\IranPayment\Tests\Fixtures\TestDriver;

it('it returns a new instance for the same gateway on each call', function (): void {
    paymentManager()->extend('test_gateway', fn (): TestDriver => new TestDriver);

    $paymentOne = paymentManager()->gateway('test_gateway');
    $paymentTwo = paymentManager()->gateway('test_gateway');

    expect($paymentOne)
        ->not->toBe($paymentTwo);
});

it('it returns the same instance when using a fake driver', function (): void {
    paymentManager()->extend('test_gateway', fn (): FakeDriver => new FakeDriver('test_gateway'));

    $paymentOne = paymentManager()->gateway('test_gateway');
    $paymentTwo = paymentManager()->gateway('test_gateway');

    expect($paymentOne)
        ->toBe($paymentTwo);
});

// ------------
// Helpers
// ------------

function paymentManager(): PaymentManager
{
    return app(PaymentManager::class);
}
