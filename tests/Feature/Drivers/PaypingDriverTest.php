<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Feature\Drivers\PaypingDriverTest; // To avoid helper functions conflict.

use AliYavari\IranPayment\Drivers\PaypingDriver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\MissingCallbackDataException;
use AliYavari\IranPayment\Exceptions\SandboxNotSupportedException;
use AliYavari\IranPayment\Facades\Payment;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    setDriverConfigs();
});

it('calls payment creation API with minimum passed data and config callback URL', function (): void {
    fakeHttp(successfulCreationResponse(), 200);

    driver()->create(1_000);

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://api.payping.ir/v3/pay')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST')
        ->hasHeader('Authorization', 'Bearer token')->toBeTrue();

    expect($request->data())
        ->amount->toBe(1_000)
        ->returnUrl->toBe('http://callback.test') // Config's callback URL
        ->isReversible->toBeTrue()
        ->not->toHaveKeys(['payerIdentity', 'description']);
});

it('calls payment creation API with full passed data', function (): void {
    fakeHttp(successfulCreationResponse(), 200);

    driver()->create(1_000, 'Description', '09123456789');

    $request = getRecordedHttpRequest();

    // Only what differs from the previous test
    expect($request->data())
        ->description->toBe('Description')
        ->payerIdentity->toBe('09123456789');
});

it('converts phone number to gateway format if needed', function (string|int $phone): void {
    fakeHttp(successfulCreationResponse(), 200);

    driver()->create(1_000, phone: $phone);

    $request = getRecordedHttpRequest();

    expect($request->data())
        ->payerIdentity->toBe('09123456789');
})->with([
    'With country code' => 989123456789,
    'Without country code, with first zero' => '09123456789',
    'Without country code, and first zero' => 9123456789,
    'With country code, and first plus' => '+989123456789',
    'With country code and first zero' => 9809123456789,
    'With country code, first zero and first plus' => '+9809123456789',
]);

it('returns successful response on successful payment creation', function (): void {
    fakeHttp($response = successfulCreationResponse(), 200);

    $payment = driver()->create(1_000);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment creation', function (): void {
    fakeHttp($response = failedResponse(), 400);

    $payment = driver()->create(1_000);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 102- درگاه پرداخت فعال برای پذیرنده یافت نشد') // From fake failed response
        ->getRawResponse()->toBe($response);
});

it('returns paymentCode in the gateway response as transaction ID', function (): void {
    fakeHttp(successfulCreationResponse());

    $payment = driver()->create(1_000);

    expect($payment)
        ->getTransactionId()->toBe('d2e353189823079e1e4181772cff5292'); // From fake creation response
});

it('returns gateway payload needed to verify payment on successful payment creation', function (): void {
    fakeHttp(successfulCreationResponse(), 200);

    $payment = driver()->create(1_000);

    expect($payment)
        ->getGatewayPayload()->toBe([
            'payment_code' => 'd2e353189823079e1e4181772cff5292', // From fake creation response
            'amount' => 1_000,
        ]);
});

it('returns gateway redirect data on successful payment creation', function (): void {
    fakeHttp(successfulCreationResponse(), 200);

    $payment = driver()->create(1_000);

    expect($payment->getRedirectData())
        ->toBeInstanceOf(PaymentRedirectDto::class)
        ->url->toBe('https://api.payping.ir/v3/pay/start/d2e3531898') // From fake creation response
        ->method->toBe('GET')
        ->payload->toBe([])
        ->headers->toBe([]);
});

it('throws an exception for payment creation when configured to use sandbox', function (): void {
    fakeHttp();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): PaypingDriver => driver()->create(1_000))
        ->toThrow(SandboxNotSupportedException::class, 'Payping gateway does not support the sandbox environment.');

    Http::assertNothingSent();
});

it('creates payment instance from callback data', function (): void {
    $callbackPayload = callbackFactory()->successful()->all();

    $payment = driver()->fromCallback($callbackPayload);

    expect($payment)
        ->toBeInstanceOf(PaypingDriver::class)
        ->getTransactionId()->toBe('d2e353189823079e1e4181772cff5292');
});

it('throws exception when callback lacks required keys', function (string $key): void {
    // Failed callback has minimum required keys.
    $callbackPayload = callbackFactory()->failed()->dot()->except([$key])->undot()->all();

    expect(fn (): PaypingDriver => driver()->fromCallback($callbackPayload))
        ->toThrow(
            MissingCallbackDataException::class,
            sprintf('To create payping gateway instance from callback, "status, errorCode, data.paymentCode" are required. "%s" is missing.', $key)
        );

})->with([
    'status',
    'errorCode',
    'data.paymentCode',
]);

it('throws exception when stored payload and successful callback data do not match', function (string $payloadKey, string $callbackKey): void {
    fakeHttp();

    $callbackPayload = callbackFactory()->successful()->all();

    $payload = gatewayPayload();
    Arr::set($payload, $payloadKey, '123'); // Change payload value for the given key so it no longer matches

    $payment = driver()->fromCallback($callbackPayload);

    expect(fn (): PaypingDriver => $payment->verify($payload))
        ->toThrow(
            InvalidCallbackDataException::class,
            sprintf('"%s" in the callback does not match with "%s" in the stored gateway payload.', $callbackKey, $payloadKey)
        );

    Http::assertNothingSent();
})->with([
    ['payment_code', 'data.paymentCode'],
]);

it('does not verify payment when callback status is not successful', function (): void {
    fakeHttp();

    $callbackPayload = callbackFactory()->failed()->all();

    $payment = driver()
        ->fromCallback($callbackPayload)
        ->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 102- درگاه پرداخت فعال برای پذیرنده یافت نشد') // The error code is set by fake failed callback.
        ->getRawResponse()->toBe($callbackPayload);

    Http::assertNothingSent();
});

it('verifies payment when callback is successful and matches stored payload', function (): void {
    fakeHttp(successfulVerificationResponse(), 200);

    driverFromSuccessfulCallback()->verify(gatewayPayload());

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://api.payping.ir/v3/pay/verify')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST')
        ->hasHeader('Authorization', 'Bearer token')->toBeTrue();

    expect($request->data())
        ->paymentRefId->toBe(123456) // From fake callback
        ->paymentCode->toBe('d2e353189823079e1e4181772cff5292') // From fake callback
        ->amount->toBe(1_000); // From fake payload
});

it('returns successful response on successful payment verification', function (): void {
    fakeHttp($response = successfulVerificationResponse(), 200);

    $payment = driverFromSuccessfulCallback()->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns successful response on subsequence successful payment verification', function (): void {
    $response = successfulVerificationResponse();
    // In the subsequence successful verifications it returns `409` instead of `200` by status `110`
    Arr::set($response, 'metaData.code', 110);

    fakeHttp($response, 409);

    $payment = driverFromSuccessfulCallback()->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment verification', function (): void {
    fakeHttp($response = failedResponse(), 400);

    $payment = driverFromSuccessfulCallback()->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 102- درگاه پرداخت فعال برای پذیرنده یافت نشد')  // From fake failed response
        ->getRawResponse()->toBe($response);
});

it('throws an exception for payment verification when configured to use sandbox', function (): void {
    fakeHttp();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): PaypingDriver => driverFromSuccessfulCallback()->verify(gatewayPayload()))
        ->toThrow(SandboxNotSupportedException::class, 'Payping gateway does not support the sandbox environment.');

    Http::assertNothingSent();
});

it('returns card number and reference ID from successful verification', function (): void {
    fakeHttp(successfulVerificationResponse(), 200);

    $payment = verifiedPayment();

    expect($payment)
        ->getRefNumber()->toBe('10012') // From fake verification response
        ->getCardNumber()->toBe('123456******1234'); // From fake verification response
});

it('reverses the payment', function (): void {
    fakeHttp(
        firstResponse: successfulVerificationResponse(),
        secondResponse: successfulReversalResponse(),
    );

    verifiedPayment()->reverse();

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://api.payping.ir/v3/pay/reverse')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST')
        ->hasHeader('Authorization', 'Bearer token')->toBeTrue();

    expect($request->data())
        ->paymentRefId->toBe(123456) // From fake callback
        ->paymentCode->toBe('d2e353189823079e1e4181772cff5292'); // From fake callback
});

it('returns successful response on successful payment reversal', function (): void {
    fakeHttp(
        firstResponse: successfulVerificationResponse(),
        secondResponse: $response = successfulReversalResponse(),
    );

    $payment = verifiedPayment()->reverse();

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment reversal', function (): void {
    fakeHttp(
        firstResponse: successfulVerificationResponse(),
        secondResponse: $response = failedResponse(),
        secondStatus: 400,
    );

    $payment = verifiedPayment()->reverse();

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 102- درگاه پرداخت فعال برای پذیرنده یافت نشد')  // From fake failed response
        ->getRawResponse()->toBe($response);
});

it('throws an exception for payment reversal when configured to use sandbox', function (): void {
    fakeHttp(successfulVerificationResponse());

    $payment = verifiedPayment();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): PaypingDriver => $payment->reverse())
        ->toThrow(SandboxNotSupportedException::class, 'Payping gateway does not support the sandbox environment.');

    Http::assertSentCount(1); // Only verification, before set to sandbox
});

it('creates payment instance with no callback data', function (): void {
    $payment = driver()->noCallback(transactionId: '123456789');

    expect($payment)
        ->toBeInstanceOf(PaypingDriver::class)
        ->getTransactionId()->toBe('123456789');
});

it('returns failed verification with no callback data', function (): void {
    fakeHttp();

    $payment = driver()->noCallback('123');

    $payment->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 9100- درگاه از وریفای بدون callback پشتیبانی نمی کند.')
        ->getRawResponse()->toBe('No API is called.');

    Http::assertNothingSent();
});

it('returns successful reversal with no callback data', function (): void {
    fakeHttp();

    $payment = driver()->noCallback('123')->verify(gatewayPayload());

    $payment->reverse();

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe('No API is called.');

    Http::assertNothingSent();
});

// ------------
// Helpers
// ------------

function setDriverConfigs(): void
{
    Config::set('iran-payment.gateways.payping.callback_url', 'http://callback.test');
    Config::set('iran-payment.gateways.payping.token', 'token');
}

function driver(): PaypingDriver
{
    return Payment::gateway('payping');
}

function successfulCreationResponse(): array
{
    return [
        'paymentCode' => 'd2e353189823079e1e4181772cff5292',
        'url' => 'https://api.payping.ir/v3/pay/start/d2e3531898',
        'amount' => 1000,
        'payerWage' => 10,
        'businessWage' => 10,
        'gatewayAmount' => 1020,
    ];
}

function successfulVerificationResponse(): array
{
    return [
        'amount' => 1_000,
        'cardNumber' => '123456******1234',
        'cardHashPan' => 'E59FA6241C94B8836E3D03120DF33E80FD988888BBA0A122240C2E7D23B48295',
        'clientRefId' => null,
        'paymentRefId' => 10012,
        'code' => 'd2e353189823079e1e4181772cff5292',
        'payedDate' => '2025-12-10 12:10:08',
        'payerWage' => 10,
        'businessWage' => 10,
        'gatewayAmount' => 1_020,
    ];
}

function successfulReversalResponse(): array
{
    return [
        'amount' => 1_000,
        'clientRefId' => null,
        'paymentRefId' => 10012,
        'code' => 'd2e353189823079e1e4181772cff5292',
        'payerWage' => 10,
        'gatewayAmount' => 1_020,
        'reversedDate' => '2025-12-10 12:10:08',
    ];
}

function failedResponse(): array
{
    return [
        'type' => 'https://datatracker.ietf.org/doc/html/rfc7231#section-6.5.1',
        'title' => 'ValidationException',
        'status' => 400,
        'instance' => '/v3/pay',
        'paypingTraceId' => '0HN50ATIAS006:00000002',
        'metaData' => [
            'code' => 102,
            'errors' => [
                ['message' => "مقدار فیلد 'آدرس بازگشت پذیرنده' اجباری است"],
                ['message' => "مقدار فیلد 'شناسه ارجاع پذیرنده' اجباری است"],
            ],
        ],
    ];
}

function driverFromSuccessfulCallback(): PaypingDriver
{
    $callback = callbackFactory()->successful()->all();

    return driver()->fromCallback($callback);
}

function verifiedPayment(): PaypingDriver
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
                'status' => 1,
                'errorCode' => null,
                'data' => [
                    'paymentCode' => 'd2e353189823079e1e4181772cff5292',
                    'clientRefId' => '',
                    'paymentRefId' => 123456,
                    'amount' => 1000,
                    'gatewayAmount' => 1020,
                    'cardNumber' => '123456******4321',
                    'cardHashPan' => '13464dasgfasdvad',

                ],
            ]);
        }

        public function failed(): Collection
        {
            return collect([
                'status' => 0,
                'errorCode' => 102,
                'data' => [
                    'paymentCode' => 'd2e353189823079e1e4181772cff5292',
                    'clientRefId' => '',
                    'amount' => 1000,
                    'gatewayAmount' => 1020,
                ],
            ]);
        }
    };
}

function gatewayPayload(): array
{
    return [
        'payment_code' => 'd2e353189823079e1e4181772cff5292',
        'amount' => 1_000,
    ];
}
