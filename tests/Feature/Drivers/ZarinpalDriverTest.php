<?php

declare(strict_types=1);

use AliYavari\IranPayment\Drivers\ZarinpalDriver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\MissingCallbackDataException;
use AliYavari\IranPayment\Tests\Helpers\ZarinpalHelper as Helper;
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
        ->url()->toBe('https://payment.zarinpal.com/pg/v4/payment/request.json')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST');

    expect($request->data())
        ->merchant_id->toBe('xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')
        ->amount->toBe('1000')
        ->currency->toBe('IRR')
        ->description->toBe('')
        ->callback_url->toBe('http://callback.test') // config's callback URL
        ->not->toHaveKeys(['metadata']);
});

it('calls payment creation API with full passed data', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    Helper::driver()->create(1_000, 'Description', '09123456789');

    $request = getRecordedHttpRequest();

    // Only what differs from the previous test
    expect($request->data())
        ->description->toBe('Description')
        ->metadata->mobile->toBe('09123456789');
});

it('converts phone number to gateway format if needed', function (string|int $phone): void {
    fakeHttp(Helper::successfulCreationResponse());

    Helper::driver()->create(1_000, phone: $phone);

    $request = getRecordedHttpRequest();

    expect($request->data())
        ->metadata->mobile->toBe('09123456789');
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
        ->error()->toContain('-10')->toContain('ای پی یا مرچنت كد پذیرنده صحیح نیست')
        ->getRawResponse()->toBe($response);
});

it('returns authority in the gateway response as transaction ID', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->getTransactionId()->toBe('A0000000000000000000000000000wwOGYpd'); // From fake creation response
});

it('returns gateway payload needed to verify payment on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->getGatewayPayload()->toBe([
            'authority' => 'A0000000000000000000000000000wwOGYpd', // From fake creation response
            'amount' => '1000',
        ]);
});

it('returns gateway redirect data on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::driver()->create(1_000);

    expect($payment->getRedirectData())
        ->toBeInstanceOf(PaymentRedirectDto::class)
        ->url->toBe('https://payment.zarinpal.com/pg/StartPay/A0000000000000000000000000000wwOGYpd') // From fake creation response
        ->method->toBe('GET')
        ->payload->toBe([])
        ->headers->toBe([]);
});

it('communicates with sandbox environment for payment creation when configured', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    Config::set('iran-payment.use_sandbox', true);

    $payment = Helper::driver()->create(1_000);

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://sandbox.zarinpal.com/pg/v4/payment/request.json');

    expect($payment)
        ->getRedirectData()->url->toBe('https://sandbox.zarinpal.com/pg/StartPay/A0000000000000000000000000000wwOGYpd'); // From fake creation response
});

it('creates payment instance from callback data', function (): void {
    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

    expect($payment)
        ->toBeInstanceOf(ZarinpalDriver::class)
        ->getTransactionId()->toBe('A0000000000000000000000000000wwOGYpd');
});

it('throws exception when callback lacks required keys', function (string $key): void {
    $callbackPayload = Arr::except(Helper::successfulCallback(), $key);

    expect(fn (): ZarinpalDriver => Helper::driver()->fromCallback($callbackPayload))
        ->toThrow(
            MissingCallbackDataException::class,
            sprintf('To create zarinpal gateway instance from callback, "Authority, Status" are required. "%s" is missing.', $key)
        );
})->with([
    'Authority',
    'Status',
]);

it('throws exception when stored payload and successful callback data do not match', function (string $payloadKey, string $callbackKey): void {
    fakeHttp();

    $payload = Helper::gatewayPayload();
    Arr::set($payload, $payloadKey, '123'); // Change payload value for the given key so it no longer matches

    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

    expect(fn (): ZarinpalDriver => $payment->verify($payload))
        ->toThrow(
            InvalidCallbackDataException::class,
            sprintf('"%s" in the callback does not match with "%s" in the stored gateway payload.', $callbackKey, $payloadKey)
        );

    Http::assertNothingSent();
})->with([
    ['authority', 'Authority'],
]);

it('does not verify payment when callback status is not successful', function (): void {
    fakeHttp();

    $callbackPayload = Helper::failedCallback();

    $payment = Helper::driver()
        ->fromCallback($callbackPayload)
        ->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('-51')->toContain('پرداخت ناموفق') // The error code is set by fake failed callback.
        ->getRawResponse()->toBe($callbackPayload);

    Http::assertNothingSent();
});

it('verifies payment when callback is successful and matches stored payload', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://payment.zarinpal.com/pg/v4/payment/verify.json')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST');

    expect($request->data())
        ->merchant_id->toBe('xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')
        ->amount->toBe('1000') // From fake payload
        ->authority->toBe('A0000000000000000000000000000wwOGYpd'); // From fake callback
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
    Arr::set($response, 'data.code', 101); // In the subsequence successful verifications it returns `101` instead of `100`

    fakeHttp($response);

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment verification', function (): void {
    fakeHttp($response = Helper::failedResponse());

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('-10')->toContain('ای پی یا مرچنت كد پذیرنده صحیح نیست')
        ->getRawResponse()->toBe($response);
});

it('communicates with sandbox environment for payment verification when configured', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    Config::set('iran-payment.use_sandbox', true);

    Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://sandbox.zarinpal.com/pg/v4/payment/verify.json');
});

it('returns card number and reference ID from successful verification', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::verifiedPayment();

    expect($payment)
        ->getRefNumber()->toBe('201') // From fake verification response
        ->getCardNumber()->toBe('502229******5995'); // From fake verification response
});

it('reverses the payment', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulVerificationResponse(),
        secondResponse: Helper::successfulReversalResponse()
    );

    Helper::verifiedPayment()->reverse();

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://payment.zarinpal.com/pg/v4/payment/reverse.json')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST');

    expect($request->data())
        ->merchant_id->toBe('xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')
        ->authority->toBe('A0000000000000000000000000000wwOGYpd'); // From fake callback
});

it('returns successful response on successful payment reversal', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulVerificationResponse(),
        secondResponse: $response = Helper::successfulReversalResponse()
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
        secondResponse: $response = Helper::failedResponse()
    );

    $payment = Helper::verifiedPayment()->reverse();

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('-10')->toContain('ای پی یا مرچنت كد پذیرنده صحیح نیست')
        ->getRawResponse()->toBe($response);
});

it('communicates with sandbox environment for payment reversal when configured', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulVerificationResponse(),
        secondResponse: Helper::successfulReversalResponse()
    );

    Config::set('iran-payment.use_sandbox', true);

    Helper::verifiedPayment()->reverse();

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://sandbox.zarinpal.com/pg/v4/payment/reverse.json');
});

it('creates payment instance with no callback data', function (): void {
    $payment = Helper::driver()->noCallback(transactionId: 'A0000000000000000000000000000pdOGYww');

    expect($payment)
        ->toBeInstanceOf(ZarinpalDriver::class)
        ->getTransactionId()->toBe('A0000000000000000000000000000pdOGYww');
});

it('verifies normally with no callback data', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::driver()->noCallback('A0000000000000000000000000000wwOGYpd');

    $payment->verify(Helper::gatewayPayload());

    Http::assertSentCount(1);
});

it('reverses normally with no callback data', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulVerificationResponse(),
        secondResponse: Helper::successfulReversalResponse()
    );

    $payment = Helper::driver()->noCallback('A0000000000000000000000000000wwOGYpd')->verify(Helper::gatewayPayload());

    $payment->reverse();

    Http::assertSentCount(2); // Verification and reversal
});
