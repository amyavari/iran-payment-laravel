<?php

declare(strict_types=1);

use AliYavari\IranPayment\Drivers\SadadDriver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\MissingCallbackDataException;
use AliYavari\IranPayment\Exceptions\SandboxNotSupportedException;
use AliYavari\IranPayment\Tests\Helpers\SadadHelper as Helper;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Helper::setDriverConfigs();
});

it('generates and returns transaction ID on payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse());
    mockUniqueNumberGenerator('123456789012345');

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->getTransactionId()->toBe('123456789012345');
});

it('calls payment creation API with minimum passed data and config callback URL', function (): void {
    setTestNowIran('2025-12-10 18:30:10');

    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::driver()->create(1_000);

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://sadad.shaparak.ir/api/v0/Request/PaymentRequest')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST');

    expect($request->data())
        ->MerchantId->toBe('1234')
        ->TerminalId->toBe('123456')
        ->Amount->toBe(1_000)
        ->OrderId->toBe((int) $payment->getTransactionId())
        ->LocalDateTime->toBe('2025-12-10 18:30:10')
        ->ReturnUrl->toBe('http://callback.test') // Config's callback URL
        ->SignData->toBeString() // Has a dedicated test case
        ->not->toHaveKeys(['AdditionalData', 'CardHolderIdentity']);
});

it('calls payment creation API with full passed data', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    Helper::driver()->create(1_000, 'Description', '09123456789');

    $request = getRecordedHttpRequest();

    // Only what differs from the previous test
    expect($request->data())
        ->AdditionalData->toBe('Description')
        ->CardHolderIdentity->toBe('09123456789');
});

it('converts phone number to gateway format if needed', function (string|int $phone): void {
    fakeHttp(Helper::successfulCreationResponse());

    Helper::driver()->create(1_000, phone: $phone);

    $request = getRecordedHttpRequest();

    expect($request->data())
        ->CardHolderIdentity->toBe('09123456789');
})->with([
    'With country code' => 989123456789,
    'Without country code, with first zero' => '09123456789',
    'Without country code, and first zero' => 9123456789,
    'With country code, and first plus' => '+989123456789',
    'With country code and first zero' => 9809123456789,
    'With country code, first zero and first plus' => '+9809123456789',
]);

it('signs the necessary input data', function (): void {
    fakeHttp(Helper::successfulCreationResponse());
    mockUniqueNumberGenerator('123456789012345');

    Helper::driver()->create(1_000);

    $request = getRecordedHttpRequest();

    /**
     * Base64 encoded of TripleDes(ECB,PKCS7) encryption.
     *
     * Terminal Key and ID from config
     *
     * Final value to encrypt: 123456;123456789012345;1000
     */
    expect($request->data())
        ->SignData->toBe('s9/P4FHJFPsu+AL52T60XlPLd4TyJORmOpH31ZbXDPA=');
});

it('returns successful response on successful payment creation', function (): void {
    fakeHttp($response = Helper::successfulCreationResponse());

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment creation', function (): void {
    fakeHttp($response = Helper::failedResponse('create'));

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('61')->toContain('مبلغ تراکنش از حد مجاز بالاتر است')
        ->getRawResponse()->toBe($response);
});

it('returns gateway payload needed to verify payment on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->getGatewayPayload()->toBe([
            'orderId' => $payment->getTransactionId(),
            'token' => 'kjslflnvda13464sdv13a', // From fake creation response
            'amount' => 1_000,
        ]);
});

it('returns gateway redirect data on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::driver()->create(1_000);

    expect($payment->getRedirectData())
        ->toBeInstanceOf(PaymentRedirectDto::class)
        ->url->toBe('https://sadad.shaparak.ir/Purchase')
        ->method->toBe('GET')
        ->payload->toBe(['Token' => 'kjslflnvda13464sdv13a']) // From fake creation response
        ->headers->toBe([]);
});

it('throws an exception for payment creation when configured to use sandbox', function (): void {
    fakeHttp();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): SadadDriver => Helper::driver()->create(1_000))
        ->toThrow(SandboxNotSupportedException::class, 'Sadad gateway does not support the sandbox environment.');

    Http::assertNothingSent();
});

it('creates payment instance from callback data', function (): void {
    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

    expect($payment)
        ->toBeInstanceOf(SadadDriver::class)
        ->getTransactionId()->toBe('123456789012345');
});

it('throws exception when callback lacks required keys', function (string $key): void {
    // Failed callback has minimum required keys; only ResCode value differs.
    $callbackPayload = Arr::except(Helper::failedCallback(), $key);

    expect(fn (): SadadDriver => Helper::driver()->fromCallback($callbackPayload))
        ->toThrow(
            MissingCallbackDataException::class,
            sprintf('To create sadad gateway instance from callback, "OrderId, ResCode" are required. "%s" is missing.', $key)
        );
})->with([
    'OrderId',
    'ResCode',
]);

it('throws exception when stored payload and successful callback data do not match', function (string $payloadKey, string $callbackKey): void {
    fakeHttp();

    $payload = Helper::gatewayPayload();
    Arr::set($payload, $payloadKey, '123'); // Change payload value for the given key so it no longer matches

    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

    expect(fn (): SadadDriver => $payment->verify($payload))
        ->toThrow(
            InvalidCallbackDataException::class,
            sprintf('"%s" in the callback does not match with "%s" in the stored gateway payload.', $callbackKey, $payloadKey)
        );

    Http::assertNothingSent();
})->with([
    ['orderId', 'OrderId'],
]);

it('does not verify payment when callback status is not successful', function (): void {
    fakeHttp();

    $callbackPayload = Helper::failedCallback();

    $payment = Helper::driver()
        ->fromCallback($callbackPayload)
        ->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('-1')->toContain('تراکنش ناموفق')
        ->getRawResponse()->toBe($callbackPayload);

    Http::assertNothingSent();
});

it('verifies payment when callback is successful and matches stored payload', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://sadad.shaparak.ir/api/v0/Advice/Verify')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST');

    expect($request->data())
        ->Token->toBe('kjslflnvda13464sdv13a') // From fake callback
        ->SignData->toBe('HhzhoAicUcVBAkV5bONUFZ9Y2UlZ0e3I'); // Base64 encoded of TripleDes(ECB,PKCS7) encryption of the Token.
});

it('returns successful response on successful payment verification', function (): void {
    fakeHttp($response = Helper::successfulVerificationResponse());

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns successful response on subsequence successful payment verification', function (): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'ResCode', 100); // In the subsequence successful verifications it returns `100` instead of `0`

    fakeHttp($response);

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on successful payment verification with invalid amount', function (): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'Amount', 2_000);

    fakeHttp($response);

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('9300')->toContain('مبلغ پرداخت شده نامعتبر است')
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment verification', function (): void {
    fakeHttp($response = Helper::failedResponse('verify'));

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('-1')->toContain('تراکنش ناموفق')
        ->getRawResponse()->toBe($response);
});

it('throws an exception for payment verification when configured to use sandbox', function (): void {
    fakeHttp();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): SadadDriver => Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload()))
        ->toThrow(SandboxNotSupportedException::class, 'Sadad gateway does not support the sandbox environment.');

    Http::assertNothingSent();
});

it('returns card number and reference ID from successful verification', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::verifiedPayment();

    expect($payment)
        ->getRefNumber()->toBe('142514251425') // From fake verification response
        ->getCardNumber()->toBe('123456******1234'); // From fake callback
});

it('returns failed response on the payment reversal', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::verifiedPayment()->reverse();

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('9200')->toContain('درگاه از بازگشت وجه پشتیبانی نمی کند')
        ->getRawResponse()->toBe('No API is called. IPG does not support reversal.');

    Http::assertSentCount(1); // Only verification is sent.
});

it('creates payment instance with no callback data', function (): void {
    $payment = Helper::driver()->noCallback(transactionId: '123456789012345');

    expect($payment)
        ->toBeInstanceOf(SadadDriver::class)
        ->getTransactionId()->toBe('123456789012345');
});

it('verifies normally with no callback data', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::driver()->noCallback('123456789012345');

    $payment->verify(Helper::gatewayPayload());

    Http::assertSentCount(1);
});

it('returns failed response on the payment reversal with no callback data', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::driver()->noCallback('123456789012345')->verify(Helper::gatewayPayload());

    $payment->reverse();

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('9200')->toContain('درگاه از بازگشت وجه پشتیبانی نمی کند')
        ->getRawResponse()->toBe('No API is called. IPG does not support reversal.');

    Http::assertSentCount(1); // Only verification is sent.
});
