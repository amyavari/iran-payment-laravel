<?php

declare(strict_types=1);

use AliYavari\IranPayment\Enums\PaymentStatus;
use AliYavari\IranPayment\Exceptions\ApiIsNotCalledException;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\MissingVerificationPayloadException;
use AliYavari\IranPayment\Exceptions\PaymentNotCreatedException;
use AliYavari\IranPayment\Models\Payment;
use AliYavari\IranPayment\Tests\Fixtures\TestDriver;
use AliYavari\IranPayment\Tests\Fixtures\TestModel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

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
    $driver->verify([])->{$method}(); // Verify API call
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

it('stores payment data in the database if payment creation was successful and returns it', function (): void {
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
        'owned_by_iran_payment' => true,
    ]);

    expect($driver)
        ->getModel()->toBeInstanceOf(Payment::class)
        ->getModel()->transaction_id->toBe($driver->getTransactionId());
});

it("doesn't store payment data in the database if payment creation failed and returns `null` as payment model", function (): void {
    $driver = new TestDriver(isSuccessful: false);

    $payable = TestModel::query()->create();
    $driver->create(1_000);

    $driver->store($payable);

    $this->assertDatabaseEmpty(Payment::class);

    expect($driver)
        ->getModel()->toBeNull();
});

it('throws an exception if we try to store payment without creating it', function (): void {
    $driver = new TestDriver(isSuccessful: true);

    $payable = TestModel::query()->create();

    $driver->store($payable);
})->throws(PaymentNotCreatedException::class, 'Payment must be created via the "create" method before storing.');

it('throws an exception if we try to store payment after a non-creation API call', function (string $method): void {
    $driver = new TestDriver(isSuccessful: true);

    $payable = TestModel::query()->create();

    $driver->{$method}([])->store($payable);
})->throws(PaymentNotCreatedException::class, 'Payment must be created via the "create" method before storing.')
    ->with([
        'verify',
    ]);

it("just verifies payment if we provide gateway's payload", function (): void {
    $driver = new TestDriver();
    $driver->verify(['key' => 'value']);

    expect($driver)
        ->payload('method')->toBe('verify')
        ->payload('gateway_payload')->toBe(['key' => 'value']);
});

it("verifies payment by fetching gateway payload from database if we don't provide gateway's payload", function (): void {
    $driver = new TestDriver();

    Payment::query()->create([
        'transaction_id' => $driver->getTransactionId(),
        'payable_id' => 1,
        'payable_type' => 'Model',
        'amount' => 1_000,
        'gateway' => 'test',
        'status' => PaymentStatus::Pending,
        'gateway_payload' => ['key' => 'value'],
        'raw_responses' => [],
        'owned_by_iran_payment' => true,
    ]);

    $driver->verify();

    expect($driver)
        ->payload('method')->toBe('verify')
        ->payload('gateway_payload')->toBe(['key' => 'value']);
});

it("throws an exception if we try to fetch gateway payload from database and the table doesn't exist", function (): void {
    Schema::drop('payments');

    $driver = new TestDriver();
    $driver->verify();
})->throws(MissingVerificationPayloadException::class, 'Verification payload was not provided and the "payments" table does not exist.');

it("throws an exception if we try to fetch gateway payload from database and the table doesn't belong to this package", function (): void {
    Schema::dropColumns('payments', 'owned_by_iran_payment');

    $driver = new TestDriver();
    $driver->verify();
})->throws(MissingVerificationPayloadException::class, 'Verification payload was not provided and the "payments" table does not exist.');

it("throws an exception if we try to fetch gateway payload from database and the record doesn't exist", function (): void {
    $driver = new TestDriver();

    Payment::query()->create([
        'transaction_id' => $driver->getTransactionId(),
        'payable_id' => 1,
        'payable_type' => 'Model',
        'amount' => 1_000,
        'gateway' => 'test',
        'status' => PaymentStatus::Pending,
        'gateway_payload' => ['key' => 'value'],
        'raw_responses' => [],
    ]);

    $driver->verify();
})->throws(MissingVerificationPayloadException::class, 'Verification payload was not provided and no stored payment record was found.');

it("throws an exception if we try to fetch gateway payload from database and the record didn't save by this package", function (): void {
    $driver = new TestDriver();
    $driver->verify();
})->throws(MissingVerificationPayloadException::class, 'Verification payload was not provided and no stored payment record was found.');

it("updates successful payment in the database if we don't provide gateway's payload", function (): void {
    setTestNow('2025-12-10 18:30:10');
    $driver = new TestDriver(isSuccessful: true);

    $payable = TestModel::query()->create();

    $driver->create(1_000);

    $driver->store($payable);

    $this->assertDatabaseHas(Payment::class, [
        'transaction_id' => $driver->getTransactionId(),
        'status' => PaymentStatus::Pending,
        'verified_at' => null,
        'raw_responses' => json_encode([
            'create_20251210183010' => $driver->getRawResponse(),
        ]),
    ]);

    setTestNow('2025-12-10 18:30:20');
    $driver = new TestDriver(isSuccessful: true);
    $driver->verify();

    $this->assertDatabaseHas(Payment::class, [
        'transaction_id' => $driver->getTransactionId(),
        'status' => PaymentStatus::Successful,
        'error' => null,
        'verified_at' => '2025-12-10 18:30:20',
        'settled_at' => null,
        'reversed_at' => null,
        'raw_responses' => json_encode([
            'create_20251210183010' => $driver->getRawResponse(),
            'verify_20251210183020' => $driver->getRawResponse(), // In our testDriver they're the same.
        ]),
    ]);
});

it("updates failed payment in the database if we don't provide gateway's payload", function (): void {
    setTestNow('2025-12-10 18:30:10');
    $driver = new TestDriver(isSuccessful: true);

    $payable = TestModel::query()->create();

    $driver->create(1_000);

    $driver->store($payable);

    $this->assertDatabaseHas(Payment::class, [
        'transaction_id' => $driver->getTransactionId(),
        'status' => PaymentStatus::Pending,
        'verified_at' => null,
        'raw_responses' => json_encode([
            'create_20251210183010' => $driver->getRawResponse(),
        ]),
    ]);

    setTestNow('2025-12-10 18:30:20');

    $driver = new TestDriver(isSuccessful: false, errorCode: 12, errorMessage: 'خطایی رخ داد');
    $driver->verify();

    $this->assertDatabaseHas(Payment::class, [
        'transaction_id' => $driver->getTransactionId(),
        'status' => PaymentStatus::Failed,
        'error' => $driver->error(),
        'verified_at' => '2025-12-10 18:30:20',
        'settled_at' => null,
        'reversed_at' => null,
        'raw_responses' => json_encode([
            'create_20251210183010' => $driver->getRawResponse(),
            'verify_20251210183020' => $driver->getRawResponse(), // In our testDriver they're the same.
        ]),
    ]);
});

it('stores failed payment status if gateway threw invalid callback data exception', function (): void {
    $driver = new TestDriver();

    Payment::query()->create([
        'transaction_id' => $driver->getTransactionId(),
        'payable_id' => 1,
        'payable_type' => 'Model',
        'amount' => 1_000,
        'gateway' => 'test',
        'status' => PaymentStatus::Pending,
        'gateway_payload' => [
            'throw' => true, // A signal to TestDriver to throw the exception
            'error_message' => 'Gateway exception error message',
        ],
        'raw_responses' => [],
        'owned_by_iran_payment' => true,
    ]);

    setTestNow('2025-12-10 18:30:20');

    expect(fn (): TestDriver => $driver->fromCallback(['key' => 'value'])->verify())
        ->toThrow(InvalidCallbackDataException::class, 'Gateway exception error message');

    $this->assertDatabaseHas(Payment::class, [
        'transaction_id' => $driver->getTransactionId(),
        'status' => PaymentStatus::Failed,
        'error' => 'Gateway exception error message',
        'verified_at' => '2025-12-10 18:30:20',
        'settled_at' => null,
        'reversed_at' => null,
        'raw_responses' => json_encode([
            'verify_20251210183020' => [
                'callback' => ['key' => 'value'],
                'payload' => [
                    'throw' => true,
                    'error_message' => 'Gateway exception error message',
                ],
            ],
        ]),
    ]);
});

it("just throws gateway invalid callback data exception if we didn't store it internally", function (): void {
    $driver = new TestDriver();

    $payload = [
        'throw' => true, // A signal to TestDriver to throw the exception
        'error_message' => 'Gateway exception error message',
    ];

    expect(fn (): TestDriver => $driver->fromCallback(['key' => 'value'])->verify($payload))
        ->toThrow(InvalidCallbackDataException::class, 'Gateway exception error message');
});

it('returns payment model after verification if we stored it internally', function (): void {
    $driver = new TestDriver(isSuccessful: true);
    $payable = TestModel::query()->create();
    $driver->create(1_000);
    $driver->store($payable);

    $driver = new TestDriver();
    $driver->verify();

    expect($driver)
        ->getModel()->toBeInstanceOf(Payment::class)
        ->getModel()->transaction_id->toBe($driver->getTransactionId());
});

it("returns `null` as payment model after verification if we didn't store it internally", function (): void {
    $driver = new TestDriver();
    $driver->verify(['key' => 'value']);

    expect($driver)
        ->getModel()->toBeNull();
});

// ------------
// Helpers
// ------------

function freezeTimeUntilSeconds(): void
{
    $microSeconds = (int) (microtime(true) * 1000) % 1000;

    setTestNowIran("2025-12-10 18:30:10.{$microSeconds}");
}
