<?php

declare(strict_types=1);

use AliYavari\IranPayment\Drivers\NextpayDriver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\MissingCallbackDataException;
use AliYavari\IranPayment\Exceptions\SandboxNotSupportedException;
use AliYavari\IranPayment\Tests\Helpers\NextpayHelper as Helper;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Helper::setDriverConfigs();
});

it('calls payment creation API with minimum passed data and config callback URL', function (): void {
    fakeHttp(Helper::successfulCreationResponse());
    mockUniqueNumberGenerator('123456789012345');

    Helper::driver()->create(10_000);

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://nextpay.org/nx/gateway/token')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST');

    expect($request->data())
        ->api_key->toBe('xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')
        ->order_id->toBe('123456789012345')
        ->amount->toBe(1_000) // Toman
        ->callback_uri->toBe('http://callback.test') // config's callback URL
        ->not->toHaveKeys(['customer_phone', 'payer_desc']);
});

it('calls payment creation API with full passed data', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    Helper::driver()->create(1_000, 'Description', '09123456789');

    $request = getRecordedHttpRequest();

    // Only what differs from the previous test
    expect($request->data())
        ->payer_desc->toBe('Description')
        ->customer_phone->toBe('09123456789');
});

it('converts phone number to gateway format if needed', function (string|int $phone): void {
    fakeHttp(Helper::successfulCreationResponse());

    Helper::driver()->create(1_000, phone: $phone);

    $request = getRecordedHttpRequest();

    expect($request->data())
        ->customer_phone->toBe('09123456789');
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

    $payment = Helper::driver()->create(10_000);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment creation', function (): void {
    fakeHttp($response = Helper::failedResponse());

    $payment = Helper::driver()->create(10_000);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('-2')->toContain('پرداخت رد شده توسط کاربر یا بانک')
        ->getRawResponse()->toBe($response);
});

it('returns trans_id in the gateway response as transaction ID', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->getTransactionId()->toBe('f7c07568-c6d1-4bee-87b1-4a9e5ed2e4c1'); // From fake creation response
});

it('returns gateway payload needed to verify payment on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse());
    mockUniqueNumberGenerator('123456789012345');

    $payment = Helper::driver()->create(10_000);

    expect($payment)
        ->getGatewayPayload()->toBe([
            'order_id' => '123456789012345',
            'transaction_id' => 'f7c07568-c6d1-4bee-87b1-4a9e5ed2e4c1', // From fake creation response
            'amount' => 1_000, // Toman
        ]);
});

it('returns gateway redirect data on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::driver()->create(10_000);

    expect($payment->getRedirectData())
        ->toBeInstanceOf(PaymentRedirectDto::class)
        ->url->toBe('https://nextpay.org/nx/gateway/payment/f7c07568-c6d1-4bee-87b1-4a9e5ed2e4c1')
        ->method->toBe('GET')
        ->payload->toBe([])
        ->headers->toBe([]);
});

it('throws an exception for payment creation when configured to use sandbox', function (): void {
    fakeHttp();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): NextpayDriver => Helper::driver()->create(10_000))
        ->toThrow(SandboxNotSupportedException::class, 'Nextpay gateway does not support the sandbox environment.');

    Http::assertNothingSent();
});

it('creates payment instance from callback data', function (): void {
    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

    expect($payment)
        ->toBeInstanceOf(NextpayDriver::class)
        ->getTransactionId()->toBe('f7c07568-c6d1-4bee-87b1-4a9e5ed2e4c1');
});

it('throws exception when callback lacks required keys', function (string $key): void {
    $callbackPayload = Arr::except(Helper::successfulCallback(), $key);

    expect(fn (): NextpayDriver => Helper::driver()->fromCallback($callbackPayload))
        ->toThrow(
            MissingCallbackDataException::class,
            sprintf('To create nextpay gateway instance from callback, "trans_id" are required. "%s" is missing.', $key)
        );
})->with([
    'trans_id',
]);

it('throws exception when stored payload and callback data do not match', function (string $payloadKey, string $callbackKey): void {
    fakeHttp();

    $payload = Helper::gatewayPayload();
    Arr::set($payload, $payloadKey, '123'); // Change payload value for the given key so it no longer matches

    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

    expect(fn (): NextpayDriver => $payment->verify($payload))
        ->toThrow(
            InvalidCallbackDataException::class,
            sprintf('"%s" in the callback does not match with "%s" in the stored gateway payload.', $callbackKey, $payloadKey)
        );

    Http::assertNothingSent();
})->with([
    ['transaction_id', 'trans_id'],
]);

it('verifies payment when callback matches stored payload', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://nextpay.org/nx/gateway/verify')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST');

    expect($request->data())
        ->api_key->toBe('xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')
        ->trans_id->toBe('f7c07568-c6d1-4bee-87b1-4a9e5ed2e4c1') // From fake payload
        ->amount->toBe(1_000); // From fake payload
});

it('returns successful response on successful payment verification', function (): void {
    fakeHttp($response = Helper::successfulVerificationResponse());

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
        ->error()->toContain('-2')->toContain('پرداخت رد شده توسط کاربر یا بانک')
        ->getRawResponse()->toBe($response);
});

it('throws an exception for payment verification when configured to use sandbox', function (): void {
    fakeHttp();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): NextpayDriver => Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload()))
        ->toThrow(SandboxNotSupportedException::class, 'Nextpay gateway does not support the sandbox environment.');

    Http::assertNothingSent();
});

it('returns card number and reference ID from successful verification', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::verifiedPayment();

    expect($payment)
        ->getRefNumber()->toBe('141196584609') // From fake verification response
        ->getCardNumber()->toBe('5022-29**-****-5020'); // From fake verification response
});

it('reverses the payment', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulVerificationResponse(),
        secondResponse: Helper::successfulReversalResponse()
    );

    Helper::verifiedPayment()->reverse();

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://nextpay.org/nx/gateway/verify')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST');

    expect($request->data())
        ->api_key->toBe('xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')
        ->trans_id->toBe('f7c07568-c6d1-4bee-87b1-4a9e5ed2e4c1') // From fake callback
        ->amount->toBe(1_000) // From fake payload
        ->refund_request->toBe('yes_money_back');
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
        ->error()->toContain('-2')->toContain('پرداخت رد شده توسط کاربر یا بانک')
        ->getRawResponse()->toBe($response);
});

it('throws an exception for payment reversal when configured to use sandbox', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::verifiedPayment();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): NextpayDriver => $payment->reverse())
        ->toThrow(SandboxNotSupportedException::class, 'Nextpay gateway does not support the sandbox environment.');

    Http::assertSentCount(1); // Only verification, before set to sandbox
});

it('creates payment instance with no callback data', function (): void {
    $payment = Helper::driver()->noCallback(transactionId: 'f7c07568-c6d1-4a9e5ed00022');

    expect($payment)
        ->toBeInstanceOf(NextpayDriver::class)
        ->getTransactionId()->toBe('f7c07568-c6d1-4a9e5ed00022');
});

it('verifies normally with no callback data', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::driver()->noCallback('f7c07568-c6d1-4bee-87b1-4a9e5ed2e4c1');

    $payment->verify(Helper::gatewayPayload());

    Http::assertSentCount(1);
});

it('reverses normally with no callback data', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulVerificationResponse(),
        secondResponse: Helper::successfulReversalResponse()
    );

    $payment = Helper::driver()->noCallback('f7c07568-c6d1-4bee-87b1-4a9e5ed2e4c1')->verify(Helper::gatewayPayload());

    $payment->reverse();

    Http::assertSentCount(2); // Verification and reversal
});
