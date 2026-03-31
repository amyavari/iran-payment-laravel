<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Feature\Drivers\ZarinpalDriverTest;

use AliYavari\IranPayment\Drivers\ZarinpalDriver;
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

it('calls payment creation API with minimum passed data and config callback URL', function (): void {
    fakeHttp(successfulCreationResponse());

    driver()->create(1_000);

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
    fakeHttp(successfulCreationResponse());

    driver()->create(1_000, 'Description', '09123456789');

    $request = getRecordedHttpRequest();

    // Only what differs from the previous test
    expect($request->data())
        ->description->toBe('Description')
        ->metadata->mobile->toBe('09123456789');
});

it('converts phone number to gateway format if needed', function (string|int $phone): void {
    fakeHttp(successfulCreationResponse());

    driver()->create(1_000, phone: $phone);

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
    fakeHttp($response = successfulCreationResponse());

    $payment = driver()->create(1_000);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment creation', function (): void {
    fakeHttp($response = failedResponse());

    $payment = driver()->create(1_000);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد -10- ای پی یا مرچنت كد پذیرنده صحیح نیست') // From fake failed response
        ->getRawResponse()->toBe($response);
});

it('returns authority in the gateway response as transaction ID', function (): void {
    fakeHttp(successfulCreationResponse());

    $payment = driver()->create(1_000);

    expect($payment)
        ->getTransactionId()->toBe('A0000000000000000000000000000wwOGYpd'); // From fake creation response
});

it('returns gateway payload needed to verify payment on successful payment creation', function (): void {
    fakeHttp(successfulCreationResponse());

    $payment = driver()->create(1_000);

    expect($payment)
        ->getGatewayPayload()->toBe([
            'authority' => 'A0000000000000000000000000000wwOGYpd', // From fake creation response
            'amount' => '1000',
        ]);
});

it('returns gateway redirect data on successful payment creation', function (): void {
    fakeHttp(successfulCreationResponse());

    $payment = driver()->create(1_000);

    expect($payment->getRedirectData())
        ->toBeInstanceOf(PaymentRedirectDto::class)
        ->url->toBe('https://payment.zarinpal.com/pg/StartPay/A0000000000000000000000000000wwOGYpd') // From fake creation response
        ->method->toBe('GET')
        ->payload->toBe([])
        ->headers->toBe([]);
});

it('communicates with sandbox environment for payment creation when configured', function (): void {
    fakeHttp(successfulCreationResponse());

    Config::set('iran-payment.use_sandbox', true);

    $payment = driver()->create(1_000);

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://sandbox.zarinpal.com/pg/v4/payment/request.json');

    expect($payment)
        ->getRedirectData()->url->toBe('https://sandbox.zarinpal.com/pg/StartPay/A0000000000000000000000000000wwOGYpd'); // From fake creation response
});

it('creates payment instance from callback data', function (): void {
    $callbackPayload = callbackFactory()->successful()->all();

    $payment = driver()->fromCallback($callbackPayload);

    expect($payment)
        ->toBeInstanceOf(ZarinpalDriver::class)
        ->getTransactionId()->toBe('A0000000000000000000000000000wwOGYpd');
});

it('throws exception when callback lacks required keys', function (string $key): void {
    // Failed callback has minimum required keys; only Status value differs.
    $callbackPayload = callbackFactory()->failed()->except([$key])->all();

    expect(fn (): ZarinpalDriver => driver()->fromCallback($callbackPayload))
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

    $callbackPayload = callbackFactory()->successful()->all();

    $payload = gatewayPayload();
    Arr::set($payload, $payloadKey, '123'); // Change payload value for the given key so it no longer matches

    $payment = driver()->fromCallback($callbackPayload);

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

    $callbackPayload = callbackFactory()->failed()->all();

    $payment = driver()
        ->fromCallback($callbackPayload)
        ->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد -51- پرداخت ناموفق') // The error code is set by fake failed callback.
        ->getRawResponse()->toBe($callbackPayload);

    Http::assertNothingSent();
});

it('verifies payment when callback is successful and matches stored payload', function (): void {
    fakeHttp(successfulVerificationResponse());

    driverFromSuccessfulCallback()->verify(gatewayPayload());

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
    fakeHttp($response = successfulVerificationResponse());

    $payment = driverFromSuccessfulCallback()->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns successful response on subsequence successful payment verification', function (): void {
    $response = successfulVerificationResponse();
    Arr::set($response, 'data.code', 101); // In the subsequence successful verifications it returns `101` instead of `100`

    fakeHttp($response);

    $payment = driverFromSuccessfulCallback()->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment verification', function (): void {
    fakeHttp($response = failedResponse());

    $payment = driverFromSuccessfulCallback()->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد -10- ای پی یا مرچنت كد پذیرنده صحیح نیست') // From fake failed response
        ->getRawResponse()->toBe($response);
});

it('communicates with sandbox environment for payment verification when configured', function (): void {
    fakeHttp(successfulVerificationResponse());

    Config::set('iran-payment.use_sandbox', true);

    driverFromSuccessfulCallback()->verify(gatewayPayload());

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://sandbox.zarinpal.com/pg/v4/payment/verify.json');
});

it('returns card number and reference ID from successful verification', function (): void {
    fakeHttp(successfulVerificationResponse());

    $payment = verifiedPayment();

    expect($payment)
        ->getRefNumber()->toBe('201') // From fake verification response
        ->getCardNumber()->toBe('502229******5995'); // From fake verification response
});

it('reverses the payment', function (): void {
    fakeHttp(
        firstResponse: successfulVerificationResponse(),
        secondResponse: successfulReversalResponse()
    );

    verifiedPayment()->reverse();

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
        firstResponse: successfulVerificationResponse(),
        secondResponse: $response = successfulReversalResponse()
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
        secondResponse: $response = failedResponse()
    );

    $payment = verifiedPayment()->reverse();

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد -10- ای پی یا مرچنت كد پذیرنده صحیح نیست') // From fake failed response
        ->getRawResponse()->toBe($response);
});

it('communicates with sandbox environment for payment reversal when configured', function (): void {
    fakeHttp(
        firstResponse: successfulVerificationResponse(),
        secondResponse: successfulReversalResponse()
    );

    Config::set('iran-payment.use_sandbox', true);

    verifiedPayment()->reverse();

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://sandbox.zarinpal.com/pg/v4/payment/reverse.json');
});

it('creates payment instance with no callback data', function (): void {
    $payment = driver()->noCallback(transactionId: 'A0000000000000000000000000000wwOGYpd');

    expect($payment)
        ->toBeInstanceOf(ZarinpalDriver::class)
        ->getTransactionId()->toBe('A0000000000000000000000000000wwOGYpd');
});

it('verifies normally with no callback data', function (): void {
    fakeHttp(successfulVerificationResponse());

    $payment = driver()->noCallback('A0000000000000000000000000000wwOGYpd');

    $payment->verify(gatewayPayload());

    Http::assertSentCount(1);
});

it('reverses normally with no callback data', function (): void {
    fakeHttp(
        firstResponse: successfulVerificationResponse(),
        secondResponse: successfulReversalResponse()
    );

    verifiedPayment()->reverse();

    Http::assertSentCount(2); // verification and reversal
});

// ------------
// Helpers
// ------------

function setDriverConfigs(): void
{
    Config::set('iran-payment.gateways.zarinpal.callback_url', 'http://callback.test');
    Config::set('iran-payment.gateways.zarinpal.merchant_id', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
}

function driver(): ZarinpalDriver
{
    return Payment::gateway('zarinpal');
}

function successfulCreationResponse(): array
{
    return [
        'data' => [
            'code' => 100,
            'message' => 'Success',
            'authority' => 'A0000000000000000000000000000wwOGYpd',
            'fee_type' => 'Merchant',
            'fee' => 100,
        ],
        'errors' => [],
    ];
}

function successfulVerificationResponse(): array
{
    return [
        'data' => [
            'code' => 100,
            'message' => 'Verified',
            'card_hash' => '1EBE3EBEBE35C7EC0F8D6EE4F2F859107A87822CA179BC9528767EA7B5489B69',
            'card_pan' => '502229******5995',
            'ref_id' => 201,
            'fee_type' => 'Merchant',
            'fee' => 0,
        ],
        'errors' => [],
    ];
}

function successfulReversalResponse(): array
{
    return [
        'data' => [
            'code' => 100,
            'message' => 'Reversed',
        ],
        'errors' => [],
    ];
}

function failedResponse(): array
{
    return [
        'data' => [],
        'errors' => [
            'message' => 'Terminal is not valid, please check merchant_id or ip address.',
            'code' => -10,
            'validations' => [],
        ],
    ];
}

function driverFromSuccessfulCallback(): ZarinpalDriver
{
    $callback = callbackFactory()->successful()->all();

    return driver()->fromCallback($callback);
}

function verifiedPayment(): ZarinpalDriver
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
                'Authority' => 'A0000000000000000000000000000wwOGYpd',
                'Status' => 'OK',
            ]);
        }

        public function failed(): Collection
        {
            return collect([
                'Authority' => 'A0000000000000000000000000000wwOGYpd',
                'Status' => 'NOK',
            ]);
        }
    };
}

function gatewayPayload(): array
{
    return [
        'authority' => 'A0000000000000000000000000000wwOGYpd',
        'amount' => '1000',
    ];
}
