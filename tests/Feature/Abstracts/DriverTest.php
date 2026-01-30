<?php

declare(strict_types=1);

use AliYavari\IranPayment\Enums\PaymentStatus;
use AliYavari\IranPayment\Exceptions\ApiIsNotCalledException;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\MissingVerificationPayloadException;
use AliYavari\IranPayment\Exceptions\PaymentAlreadyVerifiedException;
use AliYavari\IranPayment\Models\Payment;
use AliYavari\IranPayment\Tests\Fixtures\TestDriver;
use AliYavari\IranPayment\Tests\Fixtures\TestModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

/**
 * To keep test cases simple and readable, the concrete `TestDriver` provides
 * several helper methods:
 *
 * - withCallbackUrl()  Sets the driver’s default callback URL.
 * - asSuccessful()     Forces the next API call to be successful.
 * - asFailed()         Forces the next API call to fail with a default error code and message.
 * - throwing()         Makes the next API call throw the given exception.
 * - receivedData()     Returns the data sent from the abstract driver to the concrete driver.
 * - apiCalled()        Calls a dummy API to mark the API as called, so its status can be asserted.
 * - callCreate()       Calls the `create` method with required arguments.
 * - storeTestPayment() Stores a test payment record in the database.
 *
 * @see TestDriver
 */
it('calls the concrete driver’s creation method with all passed data', function (): void {
    $driver = testDriver()->withCallbackUrl('http://test.com');

    $driver->create(1_000, 'Description', 9123456789);

    expect($driver)
        ->receivedData('method')->toBe('create')
        ->receivedData('amount')->toBe(1_000)
        ->receivedData('callback_url')->toBe('http://test.com')
        ->receivedData('description')->toBe('Description')
        ->receivedData('phone')->toBe(9123456789);
});

it('creates a new payment with a runtime-defined callback URL', function (): void {
    $driver = testDriver()->withCallbackUrl('http://test.com');

    $driver->callbackUrl('http://newCallback.test');

    $driver->callCreate();

    expect($driver)
        ->receivedData('callback_url')->toBe('http://newCallback.test');
});

it('creates a new payment with the driver callback URL when not set at runtime', function (): void {
    $driver = testDriver()->withCallbackUrl('http://test.com');

    $driver->callCreate();

    expect($driver)
        ->receivedData('callback_url')->toBe('http://test.com');
});

it('converts a relative callback URL to an absolute one', function (): void {
    URL::useOrigin('http://myapp.com');

    $driver = testDriver()->withCallbackUrl('/callback/endpoint');

    // Config's callbackUrl
    $driver->callCreate();

    expect($driver)
        ->receivedData('callback_url')->toBe('https://myapp.com/callback/endpoint');

    // Runtime's callback URL
    $driver->callbackUrl('/runtime/callback/endpoint');

    $driver->callCreate();

    expect($driver)
        ->receivedData('callback_url')->toBe('https://myapp.com/runtime/callback/endpoint');
});

it('does not change an absolute callback URL', function (): void {
    $driver = testDriver()->withCallbackUrl('http://test.com/callback/endpoint');

    // Config's callbackUrl
    $driver->callCreate();

    expect($driver)
        ->receivedData('callback_url')->toBe('http://test.com/callback/endpoint');

    // Runtime's callback URL
    $driver->callbackUrl('http://test.com/runtime/callback/endpoint');

    $driver->callCreate();

    expect($driver)
        ->receivedData('callback_url')->toBe('http://test.com/runtime/callback/endpoint');
});

it('converts currency to Rial if the app currency is Toman', function (string $currency, int $result): void {
    Config::set('iran-payment.currency', $currency);

    $driver = testDriver()->create(amount: 1_000);

    expect($driver)
        ->receivedData('amount')->toBe($result);
})->with([
    'Toman' => ['Toman', 10_000],
    'Rial' => ['Rial', 1_000],
]);

it('throws an exception when status checks are called before any API call', function (string $method): void {
    testDriver()->{$method}();
})->throws(ApiIsNotCalledException::class, 'You must call an API method before checking its status.')
    ->with([
        'successful',
        'failed',
        'error',
        'getRawResponse',
    ]);

it('does not throw an exception when status checks are called after an API call', function (string $method): void {
    testDriver()->callCreate()->{$method}(); // Create API call
    testDriver()->verify([])->{$method}(); // Verify API call
})->throwsNoExceptions()
    ->with([
        'successful',
        'failed',
        'error',
        'getRawResponse',
    ]);

it('returns failed as the inverse of successful', function (): void {
    // Successful
    $driver = testDriver()->asSuccessful()->apiCalled();

    expect($driver)
        ->failed()->toBeFalse();

    // Failed
    $driver = testDriver()->asFailed()->apiCalled();

    expect($driver)
        ->failed()->toBeTrue();
});

it('returns `null` as error when the API call is successful', function (): void {
    $driver = testDriver()->asSuccessful()->apiCalled();

    expect($driver)
        ->error()->toBeNull();
});

it('returns a formatted error message with code when the API call is not successful', function (): void {
    $driver = testDriver()->asFailed()->apiCalled();

    expect($driver)
        ->error()->toBe('کد 12- خطایی رخ داد.'); // asFailed() sets this error code and message
});

it('returns the gateway name based on the naming convention', function (): void {
    $driver = Mockery::namedMock('\Class\Namespace\CustomNameDriver', TestDriver::class)->makePartial();

    expect($driver)
        ->getGateway()->toBe('custom_name'); // Snake case of the gateway class name
});

it('stores payment data in the database when payment creation is successful and returns it', function (): void {
    setTestNow('2025-12-10 18:30:10');

    $payable = payable();

    $driver = testDriver()
        ->asSuccessful()
        ->store($payable)
        ->create(amount: 1_000);

    $this->assertDatabaseHas(Payment::class, [
        'transaction_id' => $driver->getTransactionId(),
        'payable_id' => $payable->getKey(),
        'payable_type' => $payable::class,
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

it('does not store payment data in the database when payment creation fails and returns null as the payment model', function (): void {
    $driver = testDriver()
        ->asFailed()
        ->store(payable())
        ->callCreate();

    $this->assertDatabaseEmpty(Payment::class);

    expect($driver)
        ->getModel()->toBeNull();
});

it('does not store payment data in the database when the store method is not called', function (): void {
    // Successful creation
    $driver = testDriver()
        ->asSuccessful()
        ->callCreate();

    expect($driver)
        ->getModel()->toBeNull();

    // Failed creation
    $driver = testDriver()
        ->asFailed()
        ->callCreate();

    expect($driver)
        ->getModel()->toBeNull();

    $this->assertDatabaseEmpty(Payment::class);
});

it('just verifies the payment when the gateway payload is provided', function (): void {
    $driver = testDriver()->verify(['key' => 'value']);

    expect($driver)
        ->receivedData('method')->toBe('verify')
        ->receivedData('passed_payload')->toBe(['key' => 'value']);
});

it('verifies the payment by fetching the gateway payload from the database when it is not provided', function (): void {
    testDriver()->storeTestPayment();

    $driver = testDriver()->verify();

    expect($driver)
        ->receivedData('method')->toBe('verify')
        ->receivedData('passed_payload')->toBe($driver->getGatewayPayload());
});

it('throws an exception when trying to fetch the gateway payload from the database and the table does not exist', function (): void {
    Schema::drop('payments');

    testDriver()->verify();
})->throws(MissingVerificationPayloadException::class, 'Verification payload was not provided and the "payments" table does not exist.');

it('throws an exception when trying to fetch the gateway payload from the database and payments table lacks the required columns', function (string $column): void {
    Schema::table('payments', function (Blueprint $table) use ($column): void {
        $table->dropUnique(['transaction_id']);
        $table->dropColumn($column);
    });

    testDriver()->verify();
})->throws(MissingVerificationPayloadException::class, 'Verification payload was not provided and the "payments" table does not exist.')
    ->with([
        'transaction_id',
        'owned_by_iran_payment',
    ]);

it('throws an exception when trying to fetch the gateway payload from the database and the record does not exist in the database', function (array $toUpdate): void {
    $model = testDriver()->storeTestPayment()->getModel();

    $model->update($toUpdate);

    testDriver()->verify();
})->throws(MissingVerificationPayloadException::class, 'Verification payload was not provided and no stored payment record was found.')
    ->with([
        'invalid transaction ID' => [['transaction_id' => '111']],
        'not package-owned' => [['owned_by_iran_payment' => false]],
    ]);

it('updates the successful payment in the database when the gateway payload is not provided', function (): void {
    setTestNow('2025-12-10 18:30:10');
    testDriver()->storeTestPayment();

    setTestNow('2025-12-10 18:30:20');

    $driver = testDriver()->asSuccessful()->verify();

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

it('updates the failed payment in the database when the gateway payload is not provided', function (): void {
    setTestNow('2025-12-10 18:30:10');
    testDriver()->storeTestPayment();

    setTestNow('2025-12-10 18:30:20');
    $driver = testDriver()->asFailed()->verify();

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

it('stores a failed payment status when the gateway throws an invalid callback data exception', function (): void {
    setTestNow('2025-12-10 18:30:10');
    testDriver()->storeTestPayment();

    setTestNow('2025-12-10 18:30:20');
    $driver = testDriver()->throwing(new InvalidCallbackDataException('Gateway exception error message'));

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
            'create_20251210183010' => $driver->getRawResponse(),
            'verify_20251210183020' => [
                'callback' => ['key' => 'value'],
                'payload' => $driver->getGatewayPayload(),
            ],
        ]),
    ]);
});

it('just throws the invalid callback data exception if it was not stored internally', function (): void {
    $driver = testDriver()->throwing(new InvalidCallbackDataException('Gateway exception error message'));

    expect(fn (): TestDriver => $driver->fromCallback(['key' => 'value'])->verify(['payload']))
        ->toThrow(InvalidCallbackDataException::class, 'Gateway exception error message');
});

it('returns the payment model after verification when it was stored internally', function (): void {
    testDriver()->storeTestPayment();

    $driver = testDriver()->verify();

    expect($driver)
        ->getModel()->toBeInstanceOf(Payment::class)
        ->getModel()->transaction_id->toBe($driver->getTransactionId());
});

it('returns `null` as the payment model after verification when it was not stored internally', function (): void {
    $driver = testDriver()->verify(['key' => 'value']);

    expect($driver)
        ->getModel()->toBeNull();
});

it('throws an exception when trying to verify an already verified and stored internally payment', function (): void {
    $model = testDriver()->storeTestPayment()->getModel();

    $model->update(['verified_at' => '2025-12-10 18:30:10']);

    $driver = testDriver();

    expect(fn () => $driver->verify())
        ->toThrow(
            PaymentAlreadyVerifiedException::class,
            sprintf('Payment with transaction ID "%s" has already been verified.', $driver->getTransactionId())
        );

    expect($driver)
        ->receivedData()->toBe([]); // Nothing is called

    $this->assertDatabaseHas(Payment::class, [
        'transaction_id' => $model->transaction_id,
        'verified_at' => '2025-12-10 18:30:10', // Nothing is changed
    ]);
});

// ------------
// Helpers
// ------------

function freezeTimeUntilSeconds(): void
{
    $microSeconds = (int) (microtime(true) * 1000) % 1000;

    setTestNowIran("2025-12-10 18:30:10.{$microSeconds}");
}

function testDriver(): TestDriver
{
    return new TestDriver();
}

function payable(): TestModel
{
    return TestModel::query()->create();
}
