<?php

declare(strict_types=1);

use AliYavari\IranPayment\Enums\PaymentStatus;
use AliYavari\IranPayment\Exceptions\ApiIsNotCalledException;
use AliYavari\IranPayment\Exceptions\PaymentNotCreatedException;
use AliYavari\IranPayment\Models\Payment;
use AliYavari\IranPayment\Tests\Fixtures\TestDriver;
use AliYavari\IranPayment\Tests\Fixtures\TestModel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

it('generates 15-digit unique transaction ID', function (): void {
    $orderIds = collect([]);

    for ($i = 1; $i <= 50; $i++) {
        freezeTimeUntilSeconds();

        $driver = new TestDriver();
        $driver->create(1_000);

        $orderIds->push($driver->callGenerateUniqueTimeBaseNumber());
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

it('throws an exception if we call status checking methods before an API call', function (string $method): void {
    $driver = new TestDriver();

    $driver->{$method}();
})->throws(ApiIsNotCalledException::class, 'You must call an API method before checking its status.')
    ->with([
        'successful',
        'failed',
        'error',
        'getRawResponse',
    ]);

it("doesn't throw an exception if we call status checking methods after an API call", function (string $method): void {
    $driver = new TestDriver();

    $driver->create(1_000)->{$method}(); // Create API call
})->throwsNoExceptions()
    ->with([
        'successful',
        'failed',
        'error',
        'getRawResponse',
    ]);

it('returns the opposite of successful as failed', function (): void {
    // Successful
    $driver = new TestDriver(isSuccessful: true);
    $driver->create(1_000);

    expect($driver)
        ->failed()->toBeFalse();

    // Failed
    $driver = new TestDriver(isSuccessful: false);
    $driver->create(1_000);

    expect($driver)
        ->failed()->toBeTrue();
});

it('returns `null` as error if API call was successful', function (): void {
    $driver = new TestDriver(isSuccessful: true);
    $driver->create(1_000);

    expect($driver)
        ->error()->toBeNull();
});

it('returns error message with code if API call was not successful', function (): void {
    $driver = new TestDriver(isSuccessful: false, errorCode: 12, errorMessage: 'خطایی رخ داد.');
    $driver->create(1_000);

    expect($driver)
        ->error()->toBe('کد 12- خطایی رخ داد.');
});

it('returns gateway name based on the naming convention', function (): void {
    $driver = Mockery::namedMock('\Class\Namespace\CustomNameDriver', TestDriver::class)->makePartial();

    expect($driver)
        ->getGateway()->toBe('custom_name'); // Snake case of gateway class name
});

it('stores payment data in the database if payment creation was successful', function (): void {
    setTestNow('2025-12-10 18:30:10');
    $driver = new TestDriver(isSuccessful: true);

    $payable = TestModel::query()->create();

    $driver->create(1_000);

    $driver->store($payable);

    $this->assertDatabaseHas(Payment::class, [
        'transaction_id' => $driver->getTransactionId(),
        'payable_id' => $payable->getKey(),
        'payable_type' => TestModel::class,
        'amount' => '1000', // Rial
        'gateway' => $driver->getGateway(),
        'gateway_payload' => json_encode($driver->getGatewayPayload()),
        'status' => PaymentStatus::Pending,
        'error' => null,
        'ref_number' => null,
        'card_number' => null,
        'verified_at' => null,
        'settled_at' => null,
        'reversed_at' => null,
        'raw_responses' => json_encode([
            'create_20251210183010' => $driver->getRawResponse(),
        ]),
    ]);
});

it("doesn't store payment data in the database if payment creation failed", function (): void {
    $driver = new TestDriver(isSuccessful: false);

    $payable = TestModel::query()->create();
    $driver->create(1_000);

    $driver->store($payable);

    $this->assertDatabaseEmpty(Payment::class);
});

it('throws an exception if we try to store payment without creating it', function (): void {
    $driver = new TestDriver(isSuccessful: true);

    $payable = TestModel::query()->create();

    $driver->store($payable);
})->throws(PaymentNotCreatedException::class, 'Payment must be created via the "create" method before storing.');

// ------------
// Helpers
// ------------

function freezeTimeUntilSeconds(): void
{
    $microSeconds = (int) (microtime(true) * 1000) % 1000;

    setTestNowIran("2025-12-10 18:30:10.{$microSeconds}");
}
