<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Feature\Drivers\SepDriverTest; // To avoid helper functions conflict.

use AliYavari\IranPayment\Drivers\SepDriver;
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

it('generates and returns transaction ID on payment creation', function (): void {
    fakeHttp(successfulCreationResponse());

    $payment = driver()->create(1_000);

    expect($payment)
        ->getTransactionId()->toBeString()->toBeNumeric()->toHaveLength(15);
});

it('calls payment creation API with minimum passed data and config callback URL', function (): void {
    fakeHttp(successfulCreationResponse());

    $payment = driver()->create(1_000);

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://sep.shaparak.ir/onlinepg/onlinepg')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST');

    expect($request->data())
        ->Action->toBe('token')
        ->TerminalId->toBe('1234')
        ->Amount->toBe(1_000)
        ->ResNum->toBe($payment->getTransactionId())
        ->RedirectUrl->toBe('http://callback.test') // config's callback URL
        ->not->toHaveKeys(['CellNumber']);
});

it('calls payment creation API with full passed data', function (): void {
    fakeHttp(successfulCreationResponse());

    driver()->create(1_000, 'Description', '9123456789');

    $request = getRecordedHttpRequest();

    // Only what differs from the previous test
    expect($request->data())
        ->CellNumber->toBe('9123456789');
});

it('converts phone number to gateway format if needed', function (string|int $phone): void {
    fakeHttp(successfulCreationResponse());

    driver()->create(1_000, phone: $phone);

    $request = getRecordedHttpRequest();

    expect($request->data())
        ->CellNumber->toBe('9123456789');
})->with([
    'With country code' => 989123456789,
    'Without country code, with first zero' => '09123456789',
    'Without country code, and first zero' => 9123456789,
    'With country code, and first plus' => '+989123456789',
    'With country code and first zero' => 9809123456789,
    'With country code, first zero and first plus' => '+9809123456789',
]);

it('returns successful response on successful payment creation', function (): void {
    fakeHttp($response = successfulCreationResponse());

    $payment = driver()->create(1_000);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment creation', function (): void {
    // Sample failed API response
    $response = [
        'status' => -1,
        'errorCode' => '11',
        'errorDesc' => 'شماره کارت نامعتبر است',
    ];

    fakeHttp($response);

    $payment = driver()->create(1_000);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 11- شماره کارت نامعتبر است')
        ->getRawResponse()->toBe($response);
});

it('returns gateway payload needed to verify payment on successful payment creation', function (): void {
    fakeHttp(successfulCreationResponse());

    $payment = driver()->create(1_000);

    expect($payment)
        ->getGatewayPayload()->toBe([
            'resNum' => $payment->getTransactionId(),
            'amount' => 1_000,
        ]);
});

it('returns gateway redirect data on successful payment creation', function (): void {
    fakeHttp(successfulCreationResponse());

    $payment = driver()->create(1_000);

    expect($payment->getRedirectData())
        ->toBeInstanceOf(PaymentRedirectDto::class)
        ->url->toBe('https://sep.shaparak.ir/OnlinePG/SendToken')
        ->method->toBe('GET')
        ->payload->toBe([
            'token' => '2c3c1fefac5a48geb9f9be7e445dd9b2', // From fake creation response
        ])
        ->headers->toBe([]);
});

it('throws an exception for payment creation when configured to use sandbox', function (): void {
    fakeHttp();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): SepDriver => driver()->create(1_000))
        ->toThrow(SandboxNotSupportedException::class, 'Sep gateway does not support the sandbox environment.');

    Http::assertNothingSent();
});

it('creates payment instance from callback data', function (): void {
    $callbackPayload = callbackFactory()->successful()->all();

    $payment = driver()->fromCallback($callbackPayload);

    expect($payment)
        ->toBeInstanceOf(SepDriver::class)
        ->getTransactionId()->toBe('123456789012345');
});

it('throws exception when callback lacks required keys', function (string $key): void {
    // Failed callback has minimum required keys; only State, and Status values differ.
    $callbackPayload = callbackFactory()->failed()->except([$key])->all();

    expect(fn (): SepDriver => driver()->fromCallback($callbackPayload))
        ->toThrow(
            MissingCallbackDataException::class,
            sprintf('To create sep gateway instance from callback, "State, Status, ResNum" are required. "%s" is missing.', $key)
        );
})->with([
    'State',
    'Status',
    'ResNum',
]);

it('throws exception when stored payload and successful callback data do not match', function (string $payloadKey, string $callbackKey): void {
    fakeHttp();

    $callbackPayload = callbackFactory()->successful()->all();

    $payload = gatewayPayload();
    Arr::set($payload, $payloadKey, '123'); // Change payload value for the given key so it no longer matches

    $payment = driver()->fromCallback($callbackPayload);

    expect(fn (): SepDriver => $payment->verify($payload))
        ->toThrow(
            InvalidCallbackDataException::class,
            sprintf('"%s" in the callback does not match with "%s" in the stored gateway payload.', $callbackKey, $payloadKey)
        );

    Http::assertNothingSent();
})->with([
    ['resNum', 'ResNum'],
    ['amount', 'Amount'],
]);

it('does not verify payment when callback status is not successful', function (): void {
    fakeHttp();

    $callbackPayload = callbackFactory()->failed()->all();

    $payment = driver()
        ->fromCallback($callbackPayload)
        ->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 1- کاربر انصراف داده است') // The error code is set by fake failed callback.
        ->getRawResponse()->toBe($callbackPayload);

    Http::assertNothingSent();
});

it('verifies payment when callback is successful and matches stored payload', function (): void {
    fakeHttp(successfulFollowUpResponse());

    driverFromSuccessfulCallback()->verify(gatewayPayload());

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST');

    expect($request->data())
        ->TerminalNumber->toBe(1234)
        ->RefNum->toBe('Aht+dgVAEUDZ++54+qyrGzncrgA1kySE+NbxBUcNfbJafVj3f5'); // From fake callback
});

it('returns successful response on successful payment verification', function (): void {
    fakeHttp($response = successfulFollowUpResponse());

    $payment = driverFromSuccessfulCallback()->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on successful payment verification with invalid amount', function (): void {
    $response = successfulFollowUpResponse();
    Arr::set($response, 'TransactionDetail.OrginalAmount', 2_000);

    fakeHttp($response);

    $payment = driverFromSuccessfulCallback()->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 1010- مبلغ پرداخت شده نامعتبر است')
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment verification', function (): void {
    // Sample failed API response
    $response = [
        'ResultCode' => -2,
        'ResultDescription' => 'تراکنش یافت نشد',
        'Success' => false,
    ];

    fakeHttp($response);

    $payment = driverFromSuccessfulCallback()->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد -2- تراکنش یافت نشد')
        ->getRawResponse()->toBe($response);
});

it('throws an exception for payment verification when configured to use sandbox', function (): void {
    fakeHttp();

    Config::set('iran-payment.use_sandbox', true);

    $payment = driverFromSuccessfulCallback();

    expect(fn (): SepDriver => $payment->verify(gatewayPayload()))
        ->toThrow(SandboxNotSupportedException::class, 'Sep gateway does not support the sandbox environment.');

    Http::assertNothingSent();
});

it('returns card number and reference ID from successful verification', function (): void {
    fakeHttp(successfulFollowUpResponse());

    $payment = driverFromSuccessfulCallback()->verify(gatewayPayload());

    expect($payment)
        ->getRefNumber()->toBe('227926981246') // From fake followup response
        ->getCardNumber()->toBe('123456****1234'); // From fake followup response
});

it('reverses the payment', function (): void {
    fakeHttp(
        firstResponse: successfulFollowUpResponse(), // Verification
        secondResponse: successfulFollowUpResponse(), // Reversal
    );

    verifiedPayment()->reverse();

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/ReverseTransaction')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST');

    expect($request->data())
        ->TerminalNumber->toBe(1234)
        ->RefNum->toBe('Aht+dgVAEUDZ++54+qyrGzncrgA1kySE+NbxBUcNfbJafVj3f5'); // From fake callback
});

it('returns successful response on successful payment reversal', function (): void {
    fakeHttp(
        firstResponse: successfulFollowUpResponse(), // Verification
        secondResponse: $response = successfulFollowUpResponse(), // Reversal
    );

    $payment = verifiedPayment()->reverse();

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment reversal', function (): void {
    // Sample failed API response
    $response = [
        'ResultCode' => -2,
        'ResultDescription' => 'تراکنش یافت نشد',
        'Success' => false,
    ];

    fakeHttp(
        firstResponse: successfulFollowUpResponse(), // Verification
        secondResponse: $response, // Reversal
    );

    $payment = verifiedPayment()->reverse();

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد -2- تراکنش یافت نشد')
        ->getRawResponse()->toBe($response);
});

it('throws an exception for payment reversal when configured to use sandbox', function (): void {
    fakeHttp(successfulFollowUpResponse());

    $payment = verifiedPayment();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): SepDriver => $payment->reverse())
        ->toThrow(SandboxNotSupportedException::class, 'Sep gateway does not support the sandbox environment.');

    Http::assertSentCount(1); // Only verification, before set to sandbox
});

it('creates payment instance with no callback data', function (): void {
    $payment = driver()->noCallback(transactionId: '123');

    expect($payment)
        ->toBeInstanceOf(SepDriver::class)
        ->getTransactionId()->toBe('123');
});

it('returns failed verification with no callback data', function (): void {
    fakeHttp();

    $payment = driver()->noCallback('123');

    $payment->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 1001- درگاه از وریفای بدون callback پشتیبانی نمی کند.')
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
    Config::set('iran-payment.gateways.sep.callback_url', 'http://callback.test');
    Config::set('iran-payment.gateways.sep.terminal_id', '1234');
}

function driver(): SepDriver
{
    return Payment::gateway('sep');
}

function successfulCreationResponse(): array
{
    return [
        'status' => 1,
        'token' => '2c3c1fefac5a48geb9f9be7e445dd9b2',
    ];
}

function successfulFollowUpResponse(): array
{
    return [
        'TransactionDetail' => [
            'RRN' => '227926981246',
            'RefNum' => '50',
            'MaskedPan' => '123456****1234',
            'HashedPan' => 'b96a14400c3a59249e87c300ecc06e5920327e70220213b5bbb7d7b2410f7e0d',
            'TerminalNumber' => 1234,
            'OrginalAmount' => 1_000,
            'AffectiveAmount' => 1_000,
            'StraceDate' => '2019-09-16 18:11:06',
            'StraceNo' => '100428',
        ],
        'ResultCode' => 0,
        'ResultDescription' => 'عملیات با موفقیت انجام شد',
        'Success' => true,
    ];
}

function driverFromSuccessfulCallback(): SepDriver
{
    $callback = callbackFactory()->successful()->all();

    return driver()->fromCallback($callback);
}

function verifiedPayment(): SepDriver
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
                'MID' => '1234',
                'State' => 'OK',
                'Status' => 2,
                'RRN' => 123456789012,
                'RefNum' => 'Aht+dgVAEUDZ++54+qyrGzncrgA1kySE+NbxBUcNfbJafVj3f5',
                'ResNum' => '123456789012345',
                'TerminalId' => '1234',
                'TraceNo' => 123456,
                'Amount' => 1_000,
                'SecurePan' => '654321******4321',
                'HashedCardNumber' => '1234ABsab',
            ]);
        }

        public function failed(): Collection
        {
            return collect([
                'MID' => '1234',
                'State' => 'CanceledByUser',
                'Status' => 1,
                'ResNum' => '123456789012345',
            ]);
        }
    };
}

function gatewayPayload(): array
{
    return [
        'resNum' => '123456789012345',
        'amount' => 1_000,
    ];
}
