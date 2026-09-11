<?php

declare(strict_types=1);

use AliYavari\IranPayment\Contracts\Payment as PaymentInterface;
use AliYavari\IranPayment\Drivers\FakeDriver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\GatewayBehaviorNotDefinedException;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\MissingCallbackDataException;
use AliYavari\IranPayment\Facades\Payment;
use AliYavari\IranPayment\Tests\Helpers\BehpardakhtHelper;
use AliYavari\IranPayment\Tests\Helpers\IdpayHelper;
use AliYavari\IranPayment\Tests\Helpers\NextpayHelper;
use AliYavari\IranPayment\Tests\Helpers\PaypingHelper;
use AliYavari\IranPayment\Tests\Helpers\PepHelper;
use AliYavari\IranPayment\Tests\Helpers\SadadHelper;
use AliYavari\IranPayment\Tests\Helpers\SepHelper;
use AliYavari\IranPayment\Tests\Helpers\ZarinpalHelper;
use AliYavari\IranPayment\Tests\Helpers\ZibalHelper;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

dataset(
    'gateway_connection_exceptions',
    gatewayFixtures()->map(
        fn (array $fixture, string $gateway): array => [$gateway, $fixture['connectionException']]
    )
);

dataset(
    'gateway_callbacks',
    gatewayFixtures()->map(
        fn (array $fixture, string $gateway): array => [$gateway, $fixture['callback'], $fixture['transactionId']]
    )
);

it('makes sure fixture data for every gateway is defined', function (): void {
    $allPackageGateways = collect(config()->array('iran-payment.gateways'))->keys();

    expect(gatewayFixtures()->keys())
        ->toEqualCanonicalizing($allPackageGateways);
});

it('returns a fake instance for the default gateway', function (): void {
    Config::set('iran-payment.default', 'sep');

    Payment::fake();

    $payment = Payment::gateway('sep');

    expect($payment)
        ->toBeInstanceOf(FakeDriver::class)
        ->getGateway()->toBe('sep');
});

it('returns a fake instance for a specified gateway', function (): void {
    Payment::fake('zibal');

    $payment = Payment::gateway('zibal');

    expect($payment)
        ->toBeInstanceOf(FakeDriver::class)
        ->getGateway()->toBe('zibal');
});

it('throws an exception when faking a gateway without a driver', function (): void {
    expect(fn (): FakeDriver => Payment::fake('test_gateway'))
        ->toThrow(BindingResolutionException::class);
});

it('throws an exception when the create behavior is not defined', function (): void {
    fakeTestGateway();

    expect(fn (): PaymentInterface => testGateway()->create(10))
        ->toThrow(GatewayBehaviorNotDefinedException::class, 'No behavior has been defined for the "create" method on the fake driver "zarinpal".');
});

it('fakes a successful create API response using default data', function (): void {
    mockUniqueNumberGenerator('123456789012345');

    fakeTestGateway()->successfulCreate();

    $payment = testGateway()->create(10);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe('Creation raw response')
        ->getTransactionId()->toBe('123456789012345')
        ->getGatewayPayload()->toBe(['payload' => 'test value'])
        ->getRedirectData()->scoped(
            fn ($redirectData) => $redirectData
                ->url->toBe('https://gateway.test')
                ->method->toBe('POST')
                ->payload->toBe(['status' => 'successful'])
                ->headers->toBe(['X-IranPayment-Fake' => 'true'])
        );
});

it('fakes a successful create API response using user-defined redirect data', function (): void {
    $customRedirectData = new PaymentRedirectDto(
        url: 'http://test.org',
        method: 'PUT',
        payload: ['key' => 'payload value'],
        headers: ['x-test-header' => 'header value'],
    );

    fakeTestGateway()->successfulCreate(redirectData: $customRedirectData);

    $payment = testGateway()->create(10);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRedirectData()->toBe($customRedirectData);
});

it('fakes a failed create API response', function (): void {
    fakeTestGateway()->failedCreate();

    $payment = testGateway()->create(10);

    expect($payment)
        ->failed()->toBeTrue()
        ->error()->toContain('0')->toContain('Creation failed')
        ->getRawResponse()->toBe('Creation raw response');
});

it('throws a connection exception on the create API', function (string $gateway, string $exceptionType): void {
    Payment::fake($gateway)->failedConnectionCreate();

    expect(fn () => Payment::gateway($gateway)->create(10))
        ->toThrow($exceptionType, 'Creation connection failed');
})->with('gateway_connection_exceptions');

it('creates payment instance with no callback data', function (): void {
    fakeTestGateway();

    $payment = testGateway()->noCallback('123');

    expect($payment)
        ->getTransactionId()->toBe('123');
});

it('creates payment instance from callback data and return the correct transaction ID', function (string $gateway, array $callbackPayload, string $transactionId): void {
    Payment::fake($gateway);

    $payment = Payment::gateway($gateway)->fromCallback($callbackPayload);

    expect($payment)
        ->getTransactionId()->toBe($transactionId);
})->with('gateway_callbacks');

it('validates callback data like the real driver', function (string $gateway): void {
    $realException = rescue(
        fn (): PaymentInterface => Payment::gateway($gateway)->fromCallback([]),
        fn (Throwable $exception): Throwable => $exception, // Return the caught exception
        report: false,
    );

    expect($realException)->toBeInstanceOf(MissingCallbackDataException::class);

    Payment::fake($gateway);

    expect(fn (): PaymentInterface => Payment::gateway($gateway)->fromCallback([]))
        ->toThrow($realException);
})->with(gatewayFixtures()->keys());

it('throws invalid callback exception', function (): void {
    fakeTestGateway()->invalidCallback();

    expect(fn (): PaymentInterface => testGateway(runVerification: true))
        ->toThrow(InvalidCallbackDataException::class, 'Invalid callback data');
});

it('throws an exception when invalidCallback is configured without callback data', function (): void {
    fakeTestGateway()->invalidCallback();

    expect(fn (): PaymentInterface => testGateway()->noCallback('123')->verify([]))
        ->toThrow(LogicException::class, 'The "invalidCallback" behavior needs callback data. Use "fromCallback()" instead of "noCallback()".');
});

it('throws an exception when the verify behavior is not defined', function (): void {
    fakeTestGateway();

    expect(fn (): PaymentInterface => testGateway(runVerification: true))
        ->toThrow(GatewayBehaviorNotDefinedException::class, 'No behavior has been defined for the "verify" method on the fake driver "zarinpal".');
});

it('fakes a successful verify API response', function (): void {
    fakeTestGateway()->successfulVerify();

    $payment = testGateway(runVerification: true);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe('Verification raw response')
        ->getCardNumber()->toBe('1234-****-****-1234')
        ->getRefNumber()->toBe('123456789');
});

it('fakes a failed verify API response', function (): void {
    fakeTestGateway()->failedVerify();

    $payment = testGateway(runVerification: true);

    expect($payment)
        ->failed()->toBeTrue()
        ->error()->toContain('0')->toContain('Verification failed')
        ->getRawResponse()->toBe('Verification raw response');
});

it('throws a connection exception on the verify API', function (string $gateway, string $exceptionType): void {
    Payment::fake($gateway)->failedConnectionVerify();

    $payment = Payment::gateway($gateway)->noCallback('123');

    expect(fn () => $payment->verify([]))
        ->toThrow($exceptionType, 'Verification connection failed');
})->with('gateway_connection_exceptions');

it('throws an exception when the reverse behavior is not defined', function (): void {
    fakeTestGateway()->successfulVerify();

    $payment = testGateway(runVerification: true);

    expect(fn (): PaymentInterface => $payment->reverse())
        ->toThrow(GatewayBehaviorNotDefinedException::class, 'No behavior has been defined for the "reverse" method on the fake driver "zarinpal".');
});

it('fakes a successful reverse API response', function (): void {
    fakeTestGateway()->successfulVerify()->successfulReverse();

    $payment = testGateway(runVerification: true)->reverse();

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe('Reversal raw response');
});

it('fakes a failed reverse API response', function (): void {
    fakeTestGateway()->successfulVerify()->failedReverse();

    $payment = testGateway(runVerification: true)->reverse();

    expect($payment)
        ->failed()->toBeTrue()
        ->error()->toContain('0')->toContain('Reversal failed')
        ->getRawResponse()->toBe('Reversal raw response');
});

it('throws a connection exception on the reverse API', function (string $gateway, string $exceptionType): void {
    Payment::fake($gateway)->successfulVerify()->failedConnectionReverse();

    $payment = Payment::gateway($gateway)->noCallback('123')->verify([]);

    expect(fn () => $payment->reverse())
        ->toThrow($exceptionType, 'Reversal connection failed');
})->with('gateway_connection_exceptions');

// ------------
// Helpers
// ------------

function fakeTestGateway(): FakeDriver
{
    return Payment::fake('zarinpal');
}

function testGateway(bool $runVerification = false): PaymentInterface
{
    $payment = Payment::gateway('zarinpal');

    if ($runVerification) {
        $payment->fromCallback(ZarinpalHelper::successfulCallback())->verify([]);
    }

    return $payment;
}

/**
 * Test fixtures for every gateway. Keys must match the `iran-payment.gateways` config.
 *
 * @return Collection<string,array{callback: array<string,mixed>, transactionId: string, connectionException: class-string<Throwable>}>
 */
function gatewayFixtures(): Collection
{
    return collect([
        'behpardakht' => [
            'callback' => BehpardakhtHelper::successfulCallback(),
            'transactionId' => '123456789012345',
            'connectionException' => SoapFault::class,
        ],
        'sep' => [
            'callback' => SepHelper::successfulCallback(),
            'transactionId' => '123456789012345',
            'connectionException' => ConnectionException::class,
        ],
        'zarinpal' => [
            'callback' => ZarinpalHelper::successfulCallback(),
            'transactionId' => 'A0000000000000000000000000000wwOGYpd',
            'connectionException' => ConnectionException::class,
        ],
        'idpay' => [
            'callback' => IdpayHelper::successfulCallback(),
            'transactionId' => '123456789012345',
            'connectionException' => ConnectionException::class,
        ],
        'pep' => [
            'callback' => PepHelper::successfulCallback(),
            'transactionId' => '123456789012345',
            'connectionException' => ConnectionException::class,
        ],
        'sadad' => [
            'callback' => SadadHelper::successfulCallback(),
            'transactionId' => '123456789012345',
            'connectionException' => ConnectionException::class,
        ],
        'zibal' => [
            'callback' => ZibalHelper::successfulCallback(),
            'transactionId' => '15966442233311',
            'connectionException' => ConnectionException::class,
        ],
        'payping' => [
            'callback' => PaypingHelper::successfulCallback(),
            'transactionId' => 'd2e353189823079e1e4181772cff5292',
            'connectionException' => ConnectionException::class,
        ],
        'nextpay' => [
            'callback' => NextpayHelper::successfulCallback(),
            'transactionId' => 'f7c07568-c6d1-4bee-87b1-4a9e5ed2e4c1',
            'connectionException' => ConnectionException::class,
        ],
    ]);
}
