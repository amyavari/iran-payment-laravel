<?php

declare(strict_types=1);

use AliYavari\IranPayment\Tests\Fixtures\TestDriver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

it('generates 15-digit unique transaction ID', function (): void {
    $orderIds = collect([]);

    for ($i = 1; $i <= 50; $i++) {
        freezeTimeUntilSeconds();

        $driver = new TestDriver();
        $driver->create(1_000);

        $orderIds->push($driver->getTransactionId());
    }

    $uniqueIds = $orderIds->unique();

    expect($uniqueIds)
        ->toHaveLength(50)
        ->each->toHaveLength(15);
});

it('creates new payment with runtime user defined callback URL', function (): void {
    $driver = new TestDriver(driverCallbackUrl: 'http://test.com');

    $driver->callbackUrl('http://newCallback.test')->create(1_000);

    expect($driver)
        ->payload('callback_url')->toBe('http://newCallback.test');
});

it("creates new payment with driver's callback URL if user didn't set it at runtime", function (): void {
    $driver = new TestDriver(driverCallbackUrl: 'http://test.com');

    $driver->create(1_000);

    expect($driver)
        ->payload('callback_url')->toBe('http://test.com');
});

it('converts relative callback URL to absolute one', function (): void {
    URL::useOrigin('http://myapp.com');

    $driver = new TestDriver(driverCallbackUrl: '/callback/endpoint');

    // Config's callbackUrl
    $driver->create(1_000);

    expect($driver)
        ->payload('callback_url')->toBe('https://myapp.com/callback/endpoint');

    // Runtime's callback URL
    $driver->callbackUrl('/runtime/callback/endpoint')->create(1_000);

    expect($driver)
        ->payload('callback_url')->toBe('https://myapp.com/runtime/callback/endpoint');
});

it('does not change absolute callback URL', function (): void {
    $driver = new TestDriver(driverCallbackUrl: 'http://test.com/callback/endpoint');

    // Config's callbackUrl
    $driver->create(1_000);

    expect($driver)
        ->payload('callback_url')->toBe('http://test.com/callback/endpoint');

    // Runtime's callback URL
    $driver->callbackUrl('http://test.com/runtime/callback/endpoint')->create(1_000);

    expect($driver)
        ->payload('callback_url')->toBe('http://test.com/runtime/callback/endpoint');
});

it('converts currency to Rial if the app currency is Toman', function (string $currency, int $result): void {
    Config::set('iran-payment.currency', $currency);

    $driver = new TestDriver();
    $driver->create(1_000);

    expect($driver)
        ->payload('amount')->toBe($result);
})->with([
    'Toman' => ['Toman', 10_000],
    'Rial' => ['Rial', 1_000],
]);

it('returns the opposite of successful as failed', function (): void {
    // Successful
    $driver = new TestDriver(isSuccessful: true);

    expect($driver)
        ->failed()->toBeFalse();

    // Failed
    $driver = new TestDriver(isSuccessful: false);

    expect($driver)
        ->failed()->toBeTrue();
});

it('returns `null` as error if API call was successful', function (): void {
    $driver = new TestDriver(isSuccessful: true);

    expect($driver)
        ->error()->toBeNull();
});

it('returns error message with code if API call was not successful', function (): void {
    $driver = new TestDriver(isSuccessful: false, errorCode: 12, errorMessage: 'خطایی رخ داد.');

    expect($driver)
        ->error()->toBe('کد 12- خطایی رخ داد.');
});

// ------------
// Helpers
// ------------

function freezeTimeUntilSeconds(): void
{
    $microSeconds = (int) (microtime(true) * 1000) % 1000;

    setTestNowIran("2025-12-10 18:30:10.{$microSeconds}");
}
