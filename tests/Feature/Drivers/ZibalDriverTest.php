<?php

declare(strict_types=1);

use AliYavari\IranPayment\Drivers\ZibalDriver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\MissingCallbackDataException;
use AliYavari\IranPayment\Tests\Helpers\ZibalHelper as Helper;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Helper::setDriverConfigs();
});

it('calls payment creation API with minimum passed data and config callback URL', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    Helper::driver()->create(1_000);

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://gateway.zibal.ir/v1/request')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST');

    expect($request->data())
        ->merchant->toBe('merchant')
        ->amount->toBe(1_000)
        ->callbackUrl->toBe('http://callback.test') // config's callback URL
        ->not->toHaveKeys(['description', 'mobile']);
});

it('calls payment creation API with full passed data', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    Helper::driver()->create(1_000, 'Description', '09123456789');

    $request = getRecordedHttpRequest();

    // Only what differs from the previous test
    expect($request->data())
        ->description->toBe('Description')
        ->mobile->toBe('09123456789');
});

it('converts phone number to gateway format if needed', function (string|int $phone): void {
    fakeHttp(Helper::successfulCreationResponse());

    Helper::driver()->create(1_000, phone: $phone);

    $request = getRecordedHttpRequest();

    expect($request->data())
        ->mobile->toBe('09123456789');
})->with([
    'With country code' => 989123456789,
    'Without country code, with first zero' => '09123456789',
    'Without country code, and first zero' => 9123456789,
    'With country code, and first plus' => '+989123456789',
    'With country code and first zero' => 9809123456789,
    'With country code, first zero and first plus' => '+9809123456789',
]);

it('returns successful response on successful payment creation', function (): void {
    fakeHttp($response = Helper::successfulCreationResponse());

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment creation', function (): void {
    fakeHttp($response = Helper::failedResponse());

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('102')->toContain('merchant یافت نشد')
        ->getRawResponse()->toBe($response);
});

it('returns authority in the gateway response as transaction ID', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->getTransactionId()->toBe('15966442233311'); // From fake creation response
});

it('returns gateway payload needed to verify payment on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->getGatewayPayload()->toBe([
            'trackId' => 15966442233311, // From fake creation response
            'amount' => 1_000,
        ]);
});

it('returns gateway redirect data on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::driver()->create(1_000);

    expect($payment->getRedirectData())
        ->toBeInstanceOf(PaymentRedirectDto::class)
        ->url->toBe('https://gateway.zibal.ir/start/15966442233311') // From fake creation response
        ->method->toBe('GET')
        ->payload->toBe([])
        ->headers->toBe([]);
});

it('communicates with sandbox environment for payment creation when configured', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    Config::set('iran-payment.use_sandbox', true);

    Helper::driver()->create(1_000);

    $request = getRecordedHttpRequest();

    expect($request->data())
        ->merchant->toBe('zibal');
});

it('creates payment instance from callback data', function (): void {
    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

    expect($payment)
        ->toBeInstanceOf(ZibalDriver::class)
        ->getTransactionId()->toBe('15966442233311');
});

it('throws exception when callback lacks required keys', function (string $key): void {
    $callbackPayload = Arr::except(Helper::successfulCallback(), $key);

    expect(fn (): ZibalDriver => Helper::driver()->fromCallback($callbackPayload))
        ->toThrow(
            MissingCallbackDataException::class,
            sprintf('To create zibal gateway instance from callback, "success, status, trackId" are required. "%s" is missing.', $key)
        );
})->with([
    'success',
    'status',
    'trackId',
]);

it('throws exception when stored payload and successful callback data do not match', function (string $payloadKey, string $callbackKey): void {
    fakeHttp();

    $payload = Helper::gatewayPayload();
    Arr::set($payload, $payloadKey, '123'); // Change payload value for the given key so it no longer matches

    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

    expect(fn (): ZibalDriver => $payment->verify($payload))
        ->toThrow(
            InvalidCallbackDataException::class,
            sprintf('"%s" in the callback does not match with "%s" in the stored gateway payload.', $callbackKey, $payloadKey)
        );

    Http::assertNothingSent();
})->with([
    ['trackId', 'trackId'],
]);

it('does not verify payment when callback status is not successful', function (): void {
    fakeHttp();

    $callbackPayload = Helper::failedCallback();

    $payment = Helper::driver()
        ->fromCallback($callbackPayload)
        ->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('3')->toContain('لغوشده توسط کاربر') // The error code is set by fake failed callback.
        ->getRawResponse()->toBe($callbackPayload);

    Http::assertNothingSent();
});

it('verifies payment when callback is successful and matches stored payload', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://gateway.zibal.ir/v1/verify')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST');

    expect($request->data())
        ->merchant->toBe('merchant')
        ->trackId->toBe(15966442233311); // From fake callback
});

it('returns successful response on successful payment verification', function (): void {
    fakeHttp($response = Helper::successfulVerificationResponse());

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on successful payment verification with invalid amount', function (): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'amount', 2_000);

    fakeHttp($response);

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('9300')->toContain('مبلغ پرداخت شده نامعتبر است')
        ->getRawResponse()->toBe($response);
});

it('returns failed response on payment verification when API call result is not successful', function (): void {
    fakeHttp($response = Helper::failedResponse());

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('102')->toContain('merchant یافت نشد')
        ->getRawResponse()->toBe($response);
});

it('returns failed response on payment verification when verification status is not successful', function (): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'status', 3);

    fakeHttp($response);

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('3')->toContain('لغوشده توسط کاربر')
        ->getRawResponse()->toBe($response);
});

it('communicates with sandbox environment for payment verification when configured', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    Config::set('iran-payment.use_sandbox', true);

    Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    $request = getRecordedHttpRequest();

    expect($request->data())
        ->merchant->toBe('zibal');
});

it('returns card number and reference ID from successful verification', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::verifiedPayment();

    expect($payment)
        ->getRefNumber()->toBe('12312') // From fake verification response
        ->getCardNumber()->toBe('62741****44'); // From fake verification response
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
    $payment = Helper::driver()->noCallback(transactionId: '12345');

    expect($payment)
        ->toBeInstanceOf(ZibalDriver::class)
        ->getTransactionId()->toBe('12345');
});

it('verifies normally with no callback data', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::driver()->noCallback('15966442233311');

    $payment->verify(Helper::gatewayPayload());

    Http::assertSentCount(1);
});

it('returns failed response on the payment reversal with no callback data', function (): void {
    fakeHttp(Helper::successfulVerificationResponse(), 200);

    $payment = Helper::driver()->noCallback('15966442233311')->verify(Helper::gatewayPayload());

    $payment->reverse();

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('9200')->toContain('درگاه از بازگشت وجه پشتیبانی نمی کند')
        ->getRawResponse()->toBe('No API is called. IPG does not support reversal.');

    Http::assertSentCount(1); // Only verification is sent.
});
