<?php

declare(strict_types=1);

use AliYavari\IranPayment\Drivers\PaypingDriver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\MissingCallbackDataException;
use AliYavari\IranPayment\Exceptions\SandboxNotSupportedException;
use AliYavari\IranPayment\Tests\Helpers\PaypingHelper as Helper;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Helper::setDriverConfigs();
});

it('calls payment creation API with minimum passed data and config callback URL', function (): void {
    fakeHttp(Helper::successfulCreationResponse(), 200);

    Helper::driver()->create(1_000);

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
    fakeHttp(Helper::successfulCreationResponse(), 200);

    Helper::driver()->create(1_000, 'Description', '09123456789');

    $request = getRecordedHttpRequest();

    // Only what differs from the previous test
    expect($request->data())
        ->description->toBe('Description')
        ->payerIdentity->toBe('09123456789');
});

it('converts phone number to gateway format if needed', function (string|int $phone): void {
    fakeHttp(Helper::successfulCreationResponse(), 200);

    Helper::driver()->create(1_000, phone: $phone);

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
    fakeHttp($response = Helper::successfulCreationResponse(), 200);

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment creation', function (): void {
    fakeHttp($response = Helper::failedResponse(), 400);

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 102- درگاه پرداخت فعال برای پذیرنده یافت نشد') // From fake failed response
        ->getRawResponse()->toBe($response);
});

it('returns paymentCode in the gateway response as transaction ID', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->getTransactionId()->toBe('d2e353189823079e1e4181772cff5292'); // From fake creation response
});

it('returns gateway payload needed to verify payment on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse(), 200);

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->getGatewayPayload()->toBe([
            'payment_code' => 'd2e353189823079e1e4181772cff5292', // From fake creation response
            'amount' => 1_000,
        ]);
});

it('returns gateway redirect data on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse(), 200);

    $payment = Helper::driver()->create(1_000);

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

    expect(fn (): PaypingDriver => Helper::driver()->create(1_000))
        ->toThrow(SandboxNotSupportedException::class, 'Payping gateway does not support the sandbox environment.');

    Http::assertNothingSent();
});

it('creates payment instance from callback data', function (): void {
    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

    expect($payment)
        ->toBeInstanceOf(PaypingDriver::class)
        ->getTransactionId()->toBe('d2e353189823079e1e4181772cff5292');
});

it('throws exception when callback lacks required keys', function (string $key): void {
    // Failed callback has minimum required keys.
    $callbackPayload = collect(Helper::failedCallback())->dot()->except([$key])->undot()->all();

    expect(fn (): PaypingDriver => Helper::driver()->fromCallback($callbackPayload))
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

    $payload = Helper::gatewayPayload();
    Arr::set($payload, $payloadKey, '123'); // Change payload value for the given key so it no longer matches

    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

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

    $callbackPayload = Helper::failedCallback();

    $payment = Helper::driver()
        ->fromCallback($callbackPayload)
        ->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 102- درگاه پرداخت فعال برای پذیرنده یافت نشد') // The error code is set by fake failed callback.
        ->getRawResponse()->toBe($callbackPayload);

    Http::assertNothingSent();
});

it('verifies payment when callback is successful and matches stored payload', function (): void {
    fakeHttp(Helper::successfulVerificationResponse(), 200);

    Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

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
    fakeHttp($response = Helper::successfulVerificationResponse(), 200);

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns successful response on subsequence successful payment verification', function (): void {
    $response = Helper::successfulVerificationResponse();
    // In the subsequence successful verifications it returns `409` instead of `200` by status `110`
    Arr::set($response, 'metaData.code', 110);

    fakeHttp($response, 409);

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment verification', function (): void {
    fakeHttp($response = Helper::failedResponse(), 400);

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 102- درگاه پرداخت فعال برای پذیرنده یافت نشد')  // From fake failed response
        ->getRawResponse()->toBe($response);
});

it('throws an exception for payment verification when configured to use sandbox', function (): void {
    fakeHttp();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): PaypingDriver => Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload()))
        ->toThrow(SandboxNotSupportedException::class, 'Payping gateway does not support the sandbox environment.');

    Http::assertNothingSent();
});

it('returns card number and reference ID from successful verification', function (): void {
    fakeHttp(Helper::successfulVerificationResponse(), 200);

    $payment = Helper::verifiedPayment();

    expect($payment)
        ->getRefNumber()->toBe('10012') // From fake verification response
        ->getCardNumber()->toBe('123456******1234'); // From fake verification response
});

it('reverses the payment', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulVerificationResponse(),
        secondResponse: Helper::successfulReversalResponse(),
    );

    Helper::verifiedPayment()->reverse();

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
        firstResponse: Helper::successfulVerificationResponse(),
        secondResponse: $response = Helper::successfulReversalResponse(),
    );

    $payment = Helper::verifiedPayment()->reverse();

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment reversal', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulVerificationResponse(),
        secondResponse: $response = Helper::failedResponse(),
        secondStatus: 400,
    );

    $payment = Helper::verifiedPayment()->reverse();

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 102- درگاه پرداخت فعال برای پذیرنده یافت نشد')  // From fake failed response
        ->getRawResponse()->toBe($response);
});

it('throws an exception for payment reversal when configured to use sandbox', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::verifiedPayment();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): PaypingDriver => $payment->reverse())
        ->toThrow(SandboxNotSupportedException::class, 'Payping gateway does not support the sandbox environment.');

    Http::assertSentCount(1); // Only verification, before set to sandbox
});

it('creates payment instance with no callback data', function (): void {
    $payment = Helper::driver()->noCallback(transactionId: '123456789');

    expect($payment)
        ->toBeInstanceOf(PaypingDriver::class)
        ->getTransactionId()->toBe('123456789');
});

it('returns failed verification with no callback data', function (): void {
    fakeHttp();

    $payment = Helper::driver()->noCallback('123');

    $payment->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 9100- درگاه از وریفای بدون callback پشتیبانی نمی کند.')
        ->getRawResponse()->toBe('No API is called.');

    Http::assertNothingSent();
});

it('returns successful reversal with no callback data', function (): void {
    fakeHttp();

    $payment = Helper::driver()->noCallback('123')->verify(Helper::gatewayPayload());

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
