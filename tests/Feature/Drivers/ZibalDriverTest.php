<?php

declare(strict_types=1);

use AliYavari\IranPayment\Drivers\ZibalDriver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Enums\ApiMethod;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\InvalidGatewayDataException;
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

    Helper::callGatewayFor(ApiMethod::Create);

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

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment creation', function (): void {
    fakeHttp($response = Helper::failedResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('102')->toContain('merchant یافت نشد')
        ->getRawResponse()->toBe($response);
});

it('returns authority in the gateway response as transaction ID', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->getTransactionId()->toBe('15966442233311'); // From fake creation response
});

it('returns gateway payload needed to verify payment on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->getGatewayPayload()->toBe([
            'trackId' => '15966442233311', // From fake creation response
            'amount' => 1_000,
        ]);
});

it('returns gateway redirect data on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Create);

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

    Helper::callGatewayFor(ApiMethod::Create);

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

it('throws exception when a required callback key is blank', function (string $key, mixed $value): void {
    $callbackPayload = Helper::successfulCallback();
    Arr::set($callbackPayload, $key, $value);

    expect(fn (): ZibalDriver => Helper::driver()->fromCallback($callbackPayload))
        ->toThrow(
            MissingCallbackDataException::class,
            sprintf('To create zibal gateway instance from callback, "success, status, trackId" are required. "%s" is empty.', $key)
        );
})->with([
    'success',
    'status',
    'trackId',
])->with([
    'null' => null,
    'empty string' => '',
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

    $payment = Helper::driver()->fromCallback($callbackPayload);

    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('3')->toContain('لغوشده توسط کاربر') // The error code is set by fake failed callback.
        ->getRawResponse()->toBe($callbackPayload);

    Http::assertNothingSent();
});

it('does not verify payment when callback status is unknown', function (): void {
    $callbackPayload = Helper::successfulCallback();
    Arr::set($callbackPayload, 'success', '2');

    $payment = Helper::driver()->fromCallback($callbackPayload);

    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    expect($payment)
        ->successful()->toBeFalse();
});

it('verifies payment when callback is successful and matches stored payload', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    Helper::callGatewayFor(ApiMethod::Verify);

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

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on successful payment verification with invalid amount', function (): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'amount', 2_000);

    fakeHttp($response);

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('9300')->toContain('مبلغ پرداخت شده نامعتبر است')
        ->getRawResponse()->toBe($response);
});

it('returns successful response on payment verification when the stored and verified amounts have different types', function (mixed $verifiedAmount, mixed $storedAmount): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'amount', $verifiedAmount);

    $payload = Helper::gatewayPayload();
    Arr::set($payload, 'amount', $storedAmount);

    fakeHttp($response);

    $payment = Helper::paymentReadyFor(ApiMethod::Verify)->verify($payload);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull();
})->with([
    'verified amount as string' => ['1000', 1_000],
    'stored amount as string' => [1_000, '1000'],
]);

it('throws exception when the verified amount is not numeric', function (mixed $value, string $given): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'amount', $value);

    fakeHttp($response);

    expect(fn (): ZibalDriver => Helper::callGatewayFor(ApiMethod::Verify))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    sprintf('Expected "amount" to be of type "int" for the zibal gateway, "%s" given.', $given)
                ),
        );
})->with([
    'missing value' => [null, 'null'],
    'non-numeric value' => ['abc', 'abc'],
]);

it('returns failed response on payment verification when API call result is not successful', function (): void {
    fakeHttp($response = Helper::failedResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('102')->toContain('merchant یافت نشد')
        ->getRawResponse()->toBe($response);
});

it('returns failed response on payment verification when verification status is not successful', function (): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'status', 3);

    fakeHttp($response);

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('3')->toContain('لغوشده توسط کاربر')
        ->getRawResponse()->toBe($response);
});

it('communicates with sandbox environment for payment verification when configured', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    Config::set('iran-payment.use_sandbox', true);

    Helper::callGatewayFor(ApiMethod::Verify);

    $request = getRecordedHttpRequest();

    expect($request->data())
        ->merchant->toBe('zibal');
});

it('returns card number and reference ID from successful verification', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->getRefNumber()->toBe('12312') // From fake verification response
        ->getCardNumber()->toBe('62741****44'); // From fake verification response
});

it('returns failed response on the payment reversal', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Reverse);

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

    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    Http::assertSentCount(1);
});

it('returns failed response on the payment reversal with no callback data', function (): void {
    fakeHttp(Helper::successfulVerificationResponse(), 200);

    $payment = Helper::driver()->noCallback('15966442233311');
    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    Helper::callGatewayFor(ApiMethod::Reverse, $payment);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('9200')->toContain('درگاه از بازگشت وجه پشتیبانی نمی کند')
        ->getRawResponse()->toBe('No API is called. IPG does not support reversal.');

    Http::assertSentCount(1); // Only verification is sent.
});

it('returns the internal error code when the callback status is invalid', function (): void {
    fakeHttp();

    $callbackPayload = Helper::failedCallback();
    Arr::set($callbackPayload, 'status', 'abc');

    $payment = Helper::driver()->fromCallback($callbackPayload);

    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('9400')
        ->error()->toContain('Expected "status" to be of type "int" for the zibal gateway, "abc" given.');

    Http::assertNothingSent();
});

it('throws exception when the API result code is invalid', function (ApiMethod $call, mixed $value, string $given): void {
    $response = match ($call) {
        ApiMethod::Create => Helper::successfulCreationResponse(),
        ApiMethod::Verify => Helper::successfulVerificationResponse(),
    };
    Arr::set($response, 'result', $value);

    fakeHttp($response);

    expect(fn (): ZibalDriver => Helper::callGatewayFor($call))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    sprintf('Expected "result" to be of type "int" for the zibal gateway, "%s" given.', $given)
                ),
        );
})->with([
    'creation' => ApiMethod::Create,
    'verification' => ApiMethod::Verify,
])->with([
    'missing value' => [null, 'null'],
    'non-numeric value' => ['abc', 'abc'],
]);

it('throws exception when the API returns a non-JSON response', function (ApiMethod $call): void {
    $response = 'Service is not available';

    fakeHttp($response);

    expect(fn (): ZibalDriver => Helper::callGatewayFor($call))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    'Expected "result" to be of type "int" for the zibal gateway, "null" given.'
                ),
        );
})->with([
    'creation' => ApiMethod::Create,
    'verification' => ApiMethod::Verify,
]);

it('throws exception when the verification status is invalid', function (mixed $value, string $given): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'status', $value);

    fakeHttp($response);

    expect(fn (): ZibalDriver => Helper::callGatewayFor(ApiMethod::Verify))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    sprintf('Expected "status" to be of type "int" for the zibal gateway, "%s" given.', $given)
                ),
        );
})->with([
    'missing value' => [null, 'null'],
    'non-numeric value' => ['abc', 'abc'],
]);

it('throws exception when the creation track ID is not numeric', function (mixed $value, string $given): void {
    $response = Helper::successfulCreationResponse();
    Arr::set($response, 'trackId', $value);

    fakeHttp($response);

    expect(fn (): ZibalDriver => Helper::callGatewayFor(ApiMethod::Create))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    sprintf('Expected "trackId" to be of type "int" for the zibal gateway, "%s" given.', $given)
                ),
        );
})->with([
    'missing value' => [null, 'null'],
    'non-numeric value' => ['abc', 'abc'],
]);
