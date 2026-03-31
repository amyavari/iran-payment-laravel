<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Feature\Drivers\IdPayDriverTest; // To avoid helper functions conflict.

use AliYavari\IranPayment\Drivers\IdPayDriver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\MissingCallbackDataException;
use AliYavari\IranPayment\Facades\Payment;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    setDriverConfigs();
});

it('generates and returns transaction ID on payment creation', function (): void {
    fakeHttpWithStatus(successfulCreationResponse(), 201);

    $payment = driver()->create(1_000);

    expect($payment)
        ->getTransactionId()->toBeString()->toBeNumeric()->toHaveLength(15);
});

it('calls payment creation API with minimum passed data and config callback URL', function (): void {
    fakeHttpWithStatus(successfulCreationResponse(), 201);

    $payment = driver()->create(1_000);

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://api.idpay.ir/v1.1/payment')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST')
        ->hasHeader('X-SANDBOX', '0')->toBeTrue()
        ->hasHeader('X-API-KEY', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')->toBeTrue();

    expect($request->data())
        ->order_id->toBe($payment->getTransactionId())
        ->amount->toBe('1000')
        ->callback_url->toBe('http://callback.test')
        ->not->toHaveKeys(['phone', 'desc']);
});

it('calls payment creation API with full passed data', function (): void {
    fakeHttpWithStatus(successfulCreationResponse(), 201);

    driver()->create(1_000, 'Description', '09123456789');

    $request = getRecordedHttpRequest();

    expect($request->data())
        ->desc->toBe('Description')
        ->phone->toBe('09123456789');
});

it('converts phone number to gateway format if needed', function (string|int $phone): void {
    fakeHttpWithStatus(successfulCreationResponse(), 201);

    driver()->create(1_000, phone: $phone);

    $request = getRecordedHttpRequest();

    expect($request->data())
        ->phone->toBe('09123456789');
})->with([
    'With country code' => 989123456789,
    'Without country code, with first zero' => '09123456789',
    'Without country code, and first zero' => 9123456789,
    'With country code, and first plus' => '+989123456789',
    'With country code and first zero' => 9809123456789,
    'With country code, first zero and first plus' => '+9809123456789',
]);

it('returns successful response on successful payment creation', function (): void {
    // Sample successful API response
    $response = [
        'id' => 'd2e353189823079e1e4181772cff5292',
        'link' => 'https://idpay.ir/p/ws-sandbox/d2e353189823079e1e4181772cff5292',
    ];

    fakeHttpWithStatus($response, 201);

    $payment = driver()->create(1_000);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment creation', function (): void {
    $response = failedResponse();

    fakeHttpWithStatus($response, 406);

    $payment = driver()->create(1_000);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 32- شماره سفارش `order_id` نباید خالی باشد.')
        ->getRawResponse()->toBe($response);
});

it('returns gateway payload needed to verify payment on successful payment creation', function (): void {
    fakeHttpWithStatus(successfulCreationResponse(), 201);

    $payment = driver()->create(1_000);

    expect($payment)
        ->getGatewayPayload()->toBe([
            'order_id' => $payment->getTransactionId(),
            'id' => 'd2e353189823079e1e4181772cff5292',
            'amount' => '1000',
        ]);
});

it('returns gateway redirect data on successful payment creation', function (): void {
    fakeHttpWithStatus(successfulCreationResponse(), 201);

    $payment = driver()->create(1_000);

    expect($payment->getRedirectData())
        ->toBeInstanceOf(PaymentRedirectDto::class)
        ->url->toBe('https://idpay.ir/p/ws-sandbox/d2e353189823079e1e4181772cff5292') // Full URL is returned by the gateway creation API.
        ->method->toBe('GET')
        ->payload->toBe([])
        ->headers->toBe([]);
});

it('communicates with sandbox environment for payment creation when configured', function (): void {
    Config::set('iran-payment.use_sandbox', true);

    fakeHttpWithStatus(successfulCreationResponse(), 201);

    driver()->create(1_000);

    $request = getRecordedHttpRequest();

    expect($request)
        ->hasHeader('X-SANDBOX', '1')->toBeTrue();
});

it('creates payment instance from callback data', function (): void {
    $callbackPayload = callbackFactory()->successful()->all();

    $payment = driver()->fromCallback($callbackPayload);

    expect($payment)
        ->toBeInstanceOf(IdPayDriver::class)
        ->getTransactionId()->toBe('123456789012345');
});

it('throws exception when callback lacks required keys', function (string $key): void {
    // Failed callback has minimum required keys; only Status value differs.
    $callbackPayload = callbackFactory()->failed()->except([$key])->all();

    expect(fn (): IdPayDriver => driver()->fromCallback($callbackPayload))
        ->toThrow(
            MissingCallbackDataException::class,
            sprintf('To create id_pay gateway instance from callback, "status, order_id" are required. "%s" is missing.', $key)
        );
})->with([
    'status',
    'order_id',
]);

it('throws exception when stored payload and successful callback data do not match', function (string $payloadKey, string $callbackKey): void {
    fakeHttpWithStatus(successfulVerificationResponse(), 200);

    $callbackPayload = callbackFactory()->successful()->all();

    $payload = gatewayPayload();
    Arr::set($payload, $payloadKey, '123'); // Change payload value for the given key so it no longer matches

    $payment = driver()->fromCallback($callbackPayload);

    expect(fn (): IdPayDriver => $payment->verify($payload))
        ->toThrow(
            InvalidCallbackDataException::class,
            sprintf('"%s" in the callback does not match with "%s" in the stored gateway payload.', $callbackKey, $payloadKey)
        );

    Http::assertNothingSent();
})->with([
    ['order_id', 'order_id'],
]);

it('does not verify payment when callback status is not successful', function (): void {
    fakeHttpWithStatus(successfulVerificationResponse(), 200);

    $callbackPayload = callbackFactory()->failed()->all();

    $payment = driver()
        ->fromCallback($callbackPayload)
        ->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 1- پرداخت انجام نشده است')
        ->getRawResponse()->toBe($callbackPayload);

    Http::assertNothingSent();
});

it('verifies payment when callback is successful and matches stored payload', function (): void {
    fakeHttpWithStatus(successfulVerificationResponse(), 200);

    $callbackPayload = callbackFactory()->successful()->all();

    driver()
        ->fromCallback($callbackPayload)
        ->verify(gatewayPayload());

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://api.idpay.ir/v1.1/payment/verify')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST')
        ->hasHeader('X-SANDBOX', '0')->toBeTrue()
        ->hasHeader('X-API-KEY', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')->toBeTrue();

    expect($request->data())
        ->id->toBe('d2e353189823079e1e4181772cff5292')
        ->order_id->toBe('123456789012345');
});

it('returns successful response on successful payment verification', function (): void {
    $response = successfulVerificationResponse();

    fakeHttpWithStatus($response, 200);

    $payment = driverFromSuccessfulCallback()->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns successful response on subsequence successful payment verification', function (string $status): void {
    $response = successfulVerificationResponse();

    // In the subsequence successful verifications it returns 101 and 200 instead of 100
    Arr::set($response, 'status', $status);

    fakeHttpWithStatus($response, 200);

    $payment = driverFromSuccessfulCallback()->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
})->with([
    '101',
    '200',
]);

it('returns failed response on successful payment verification with invalid amount', function (): void {
    $response = successfulVerificationResponse();
    Arr::set($response, 'amount', '2000');

    fakeHttp($response);

    $payment = driverFromSuccessfulCallback()->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 1010- مبلغ پرداخت شده نامعتبر است')
        ->getRawResponse()->toBe($response);
});

it('returns failed response on payment verification when HTTP status is not successful', function (): void {
    $response = failedResponse();

    fakeHttpWithStatus($response, 406);

    $payment = driverFromSuccessfulCallback()->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 32- شماره سفارش `order_id` نباید خالی باشد.')
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment verification when verification status is not successful', function (): void {
    $response = successfulVerificationResponse();

    Arr::set($response, 'status', '1');

    fakeHttpWithStatus($response, 200);

    $payment = driverFromSuccessfulCallback()->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 1- پرداخت انجام نشده است')
        ->getRawResponse()->toBe($response);
});

it('communicates with sandbox environment for payment verification when configured', function (): void {
    Config::set('iran-payment.use_sandbox', true);

    fakeHttpWithStatus(successfulVerificationResponse(), 200);

    driverFromSuccessfulCallback()->verify(gatewayPayload());

    $request = getRecordedHttpRequest();

    expect($request)
        ->hasHeader('X-SANDBOX', '1')->toBeTrue();
});

it('returns card number and reference ID from successful verification', function (): void {
    fakeHttpWithStatus(successfulVerificationResponse(), 200);

    $payment = verifiedPayment();

    expect($payment)
        ->getRefNumber()->toBe('888001')
        ->getCardNumber()->toBe('123456******1234');
});

it('returns failed response on the payment reversal', function (): void {
    fakeHttpWithStatus(successfulVerificationResponse(), 200);

    $payment = verifiedPayment()->reverse();

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 1011- درگاه از بازگشت وجه پشتیبانی نمی کند')
        ->getRawResponse()->toBe('No API is called. IPG does not support reversal.');

    Http::assertSentCount(1); // Only verification is sent.
});

it('creates payment instance with no callback data', function (): void {
    $payment = driver()->noCallback(transactionId: '123456789012345');

    expect($payment)
        ->toBeInstanceOf(IdPayDriver::class)
        ->getTransactionId()->toBe('123456789012345');
});

it('verifies normally with no callback data', function (): void {
    fakeHttpWithStatus(successfulVerificationResponse(), 200);

    $payment = driver()->noCallback('123456789012345');

    $payment->verify(gatewayPayload());

    Http::assertSentCount(1);
});

it('returns failed response on the payment reversal with no callback data', function (): void {
    fakeHttpWithStatus(successfulVerificationResponse(), 200);

    $payment = verifiedPayment()->reverse();

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 1011- درگاه از بازگشت وجه پشتیبانی نمی کند')
        ->getRawResponse()->toBe('No API is called. IPG does not support reversal.');

    Http::assertSentCount(1); // Only verification is sent.
});

// ------------
// Helpers
// ------------

function setDriverConfigs(): void
{
    Config::set('iran-payment.gateways.id_pay.callback_url', 'http://callback.test');
    Config::set('iran-payment.gateways.id_pay.api_key', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
}

function driver(): IdPayDriver
{
    return Payment::gateway('id_pay');
}

function fakeHttpWithStatus(array $response, int $status): void
{
    fakeHttp([
        '*' => Http::response($response, $status),
    ], isSinglePattern: false);
}

function successfulCreationResponse(): array
{
    return [
        'id' => 'd2e353189823079e1e4181772cff5292',
        'link' => 'https://idpay.ir/p/ws-sandbox/d2e353189823079e1e4181772cff5292',
    ];
}

function successfulVerificationResponse(): array
{
    return [
        'status' => '100',
        'track_id' => '10012',
        'id' => 'd2e353189823079e1e4181772cff5292',
        'order_id' => '101',
        'amount' => '1000',
        'date' => '1546288200',
        'payment' => [
            'track_id' => '888001',
            'amount' => '1000',
            'card_no' => '123456******1234',
            'hashed_card_no' => 'E59FA6241C94B8836E3D03120DF33E80FD988888BBA0A122240C2E7D23B48295',
            'date' => '1546288500',
        ],
        'verify' => [
            'date' => '1546288800',
        ],
    ];
}

function failedResponse(): array
{
    return [
        'error_code' => 32,
        'error_message' => 'شماره سفارش `order_id` نباید خالی باشد.',
    ];
}

function driverFromSuccessfulCallback(): IdPayDriver
{
    $callback = callbackFactory()->successful()->all();

    return driver()->fromCallback($callback);
}

function verifiedPayment(): IdPayDriver
{
    return driverFromSuccessfulCallback()->verify(gatewayPayload());
}

function callbackFactory(): object
{
    return new class
    {
        public function successful(): Collection
        {
            return collect([
                'status' => 100,
                'track_id' => 123456,
                'id' => 'd2e353189823079e1e4181772cff5292',
                'order_id' => '123456789012345',
            ]);
        }

        public function failed(): Collection
        {
            return $this->successful()->merge([
                'status' => 1,
            ]);
        }
    };
}

function gatewayPayload(): array
{
    return [
        'order_id' => '123456789012345',
        'id' => 'd2e353189823079e1e4181772cff5292',
        'amount' => '1000',
    ];
}
