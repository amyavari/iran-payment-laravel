<?php

declare(strict_types=1);

use AliYavari\IranPayment\Drivers\IdpayDriver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Enums\ApiMethod;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\InvalidGatewayDataException;
use AliYavari\IranPayment\Exceptions\MissingCallbackDataException;
use AliYavari\IranPayment\Tests\Helpers\IdpayHelper as Helper;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Helper::setDriverConfigs();
});

it('generates and returns transaction ID on payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse(), 201);
    mockUniqueNumberGenerator('123456789012345');

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->getTransactionId()->toBe('123456789012345');
});

it('calls payment creation API with minimum passed data and config callback URL', function (): void {
    fakeHttp(Helper::successfulCreationResponse(), 201);

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://api.idpay.ir/v1.1/payment')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST')
        ->hasHeader('X-SANDBOX', '0')->toBeTrue()
        ->hasHeader('X-API-KEY', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')->toBeTrue();

    expect($request->data())
        ->order_id->toBe($payment->getTransactionId())
        ->amount->toBe(1_000)
        ->callback_url->toBe('http://callback.test') // Config's callback URL
        ->not->toHaveKeys(['phone', 'desc']);
});

it('calls payment creation API with full passed data', function (): void {
    fakeHttp(Helper::successfulCreationResponse(), 201);

    Helper::driver()->create(1_000, 'Description', '09123456789');

    $request = getRecordedHttpRequest();

    // Only what differs from the previous test
    expect($request->data())
        ->desc->toBe('Description')
        ->phone->toBe('09123456789');
});

it('converts phone number to gateway format if needed', function (string|int $phone): void {
    fakeHttp(Helper::successfulCreationResponse(), 201);

    Helper::driver()->create(1_000, phone: $phone);

    $request = getRecordedHttpRequest();

    expect($request->data())
        ->phone->toBe('09123456789');
})->with('gateway_phone_number_formats');

it('returns successful response on successful payment creation', function (): void {
    fakeHttp($response = Helper::successfulCreationResponse(), 201);

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->toBeSuccessfulPayment()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment creation', function (): void {
    fakeHttp($response = Helper::failedResponse(), 406);

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->toBeFailedPayment('32', 'شماره سفارش `order_id` نباید خالی باشد')
        ->getRawResponse()->toBe($response);
});

it('returns gateway payload needed to verify payment on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse(), 201);

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->getGatewayPayload()->toBe([
            'order_id' => $payment->getTransactionId(),
            'id' => 'd2e353189823079e1e4181772cff5292', // From fake creation response
            'amount' => 1_000,
        ]);
});

it('returns gateway redirect data on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse(), 201);

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment->getRedirectData())
        ->toBeInstanceOf(PaymentRedirectDto::class)
        ->url->toBe('https://idpay.ir/p/ws-sandbox/d2e353189823079e1e4181772cff5292') // From fake creation response
        ->method->toBe('GET')
        ->payload->toBe([])
        ->headers->toBe([]);
});

it('throws exception when the creation ID is invalid', function (mixed $value, string $given): void {
    $response = Helper::successfulCreationResponse();
    Arr::set($response, 'id', $value);

    fakeHttp($response, 201);

    expect(fn (): IdpayDriver => Helper::callGatewayFor(ApiMethod::Create))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    sprintf('Expected "id" to be of type "string" for the idpay gateway, "%s" given.', $given)
                ),
        );
})->with('invalid_string_field_values');

it('throws exception when the creation payment link is invalid', function (mixed $value, string $given): void {
    $response = Helper::successfulCreationResponse();
    Arr::set($response, 'link', $value);

    fakeHttp($response, 201);

    expect(fn (): IdpayDriver => Helper::callGatewayFor(ApiMethod::Create))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    sprintf('Expected "link" to be of type "string" for the idpay gateway, "%s" given.', $given)
                ),
        );
})->with('invalid_string_field_values');

it('communicates with sandbox environment for payment creation when configured', function (): void {
    fakeHttp(Helper::successfulCreationResponse(), 201);

    Config::set('iran-payment.use_sandbox', true);

    Helper::callGatewayFor(ApiMethod::Create);

    $request = getRecordedHttpRequest();

    expect($request)
        ->hasHeader('X-SANDBOX', '1')->toBeTrue();
});

it('creates payment instance from callback data', function (): void {
    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

    expect($payment)
        ->toBeInstanceOf(IdpayDriver::class)
        ->getTransactionId()->toBe('123456789012345');
});

it('throws exception when callback lacks required keys', function (string $key): void {
    $callbackPayload = Arr::except(Helper::successfulCallback(), $key);

    expect(fn (): IdpayDriver => Helper::driver()->fromCallback($callbackPayload))
        ->toThrow(
            MissingCallbackDataException::class,
            sprintf('To create idpay gateway instance from callback, "status, order_id" are required. "%s" is missing.', $key)
        );
})->with([
    'status' => ['key' => 'status'],
    'order_id' => ['key' => 'order_id'],
]);

it('throws exception when a required callback key is blank', function (string $key, mixed $value): void {
    $callbackPayload = Helper::successfulCallback();
    Arr::set($callbackPayload, $key, $value);

    expect(fn (): IdpayDriver => Helper::driver()->fromCallback($callbackPayload))
        ->toThrow(
            MissingCallbackDataException::class,
            sprintf('To create idpay gateway instance from callback, "status, order_id" are required. "%s" is empty.', $key)
        );
})->with([
    'status' => ['key' => 'status'],
    'order_id' => ['key' => 'order_id'],
])->with([
    'null' => ['value' => null],
    'empty string' => ['value' => ''],
]);

it('throws exception when stored payload and successful callback data do not match', function (string $payloadKey, string $callbackKey): void {
    fakeHttp();

    $payload = Helper::gatewayPayload();
    Arr::set($payload, $payloadKey, '123'); // Change payload value for the given key so it no longer matches

    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

    expect(fn (): IdpayDriver => $payment->verify($payload))
        ->toThrow(
            InvalidCallbackDataException::class,
            sprintf('"%s" in the callback does not match with "%s" in the stored gateway payload.', $callbackKey, $payloadKey)
        );

    Http::assertNothingSent();
})->with([
    'order_id' => ['payloadKey' => 'order_id', 'callbackKey' => 'order_id'],
]);

it('does not verify payment when callback status is not successful', function (): void {
    fakeHttp();

    $callbackPayload = Helper::failedCallback();

    $payment = Helper::driver()->fromCallback($callbackPayload);

    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    expect($payment)
        ->toBeFailedPayment('1', 'پرداخت انجام نشده است') // The error code is set by fake failed callback.
        ->getRawResponse()->toBe($callbackPayload);

    Http::assertNothingSent();
});

it('throws exception when the callback status is invalid', function (): void {
    fakeHttp();

    $callbackPayload = Helper::failedCallback();
    Arr::set($callbackPayload, 'status', 'abc');

    $payment = Helper::driver()->fromCallback($callbackPayload);

    expect(fn (): IdpayDriver => Helper::callGatewayFor(ApiMethod::Verify, $payment))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $callbackPayload])
                ->getMessage()->toBe(
                    'Expected "status" to be of type "int" for the idpay gateway, "abc" given.'
                ),
        );

    Http::assertNothingSent();
});

it('verifies payment when callback is successful and matches stored payload', function (): void {
    fakeHttp(Helper::successfulVerificationResponse(), 200);

    Helper::callGatewayFor(ApiMethod::Verify);

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://api.idpay.ir/v1.1/payment/verify')
        ->isJson()->toBeTrue()
        ->method()->toBe('POST')
        ->hasHeader('X-SANDBOX', '0')->toBeTrue()
        ->hasHeader('X-API-KEY', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')->toBeTrue();

    expect($request->data())
        ->id->toBe('d2e353189823079e1e4181772cff5292') // From fake payload
        ->order_id->toBe('123456789012345'); // From fake callback
});

it('returns successful response on successful payment verification', function (): void {
    fakeHttp($response = Helper::successfulVerificationResponse(), 200);

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->toBeSuccessfulPayment()
        ->getRawResponse()->toBe($response);
});

it('returns successful response on subsequence successful payment verification', function (string $status): void {
    $response = Helper::successfulVerificationResponse();
    // In the subsequence successful verifications it returns `101` and `200` instead of `100`
    Arr::set($response, 'status', $status);

    fakeHttp($response, 200);

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->toBeSuccessfulPayment()
        ->getRawResponse()->toBe($response);
})->with([
    '101' => ['status' => '101'],
    '200' => ['status' => '200'],
]);

it('returns successful response on payment verification when the stored and verified amounts have different types', function (mixed $verifiedAmount, mixed $storedAmount): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'amount', $verifiedAmount);

    $payload = Helper::gatewayPayload();
    Arr::set($payload, 'amount', $storedAmount);

    fakeHttp($response, 200);

    $payment = Helper::paymentReadyFor(ApiMethod::Verify)->verify($payload);

    expect($payment)
        ->toBeSuccessfulPayment();
})->with([
    'verified amount as string' => ['verifiedAmount' => '1000', 'storedAmount' => 1_000],
    'stored amount as string' => ['verifiedAmount' => 1_000, 'storedAmount' => '1000'],
]);

it('returns failed response on successful payment verification with invalid amount', function (): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'amount', '2000');

    fakeHttp($response, 200);

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->toBeFailedPayment('9300', 'مبلغ پرداخت شده نامعتبر است')
        ->getRawResponse()->toBe($response);
});

it('throws exception when the verified amount is not numeric', function (mixed $value, string $given): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'amount', $value);

    fakeHttp($response, 200);

    expect(fn (): IdpayDriver => Helper::callGatewayFor(ApiMethod::Verify))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    sprintf('Expected "amount" to be of type "int" for the idpay gateway, "%s" given.', $given)
                ),
        );
})->with('invalid_numeric_field_values');

it('returns failed response on payment verification when HTTP status is not successful', function (): void {
    fakeHttp($response = Helper::failedResponse(), 406);

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->toBeFailedPayment('32', 'شماره سفارش `order_id` نباید خالی باشد')
        ->getRawResponse()->toBe($response);
});

it('returns failed response on payment verification when verification status is not successful', function (): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'status', '1');

    fakeHttp($response, 200);

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->toBeFailedPayment('1', 'پرداخت انجام نشده است')
        ->getRawResponse()->toBe($response);
});

it('throws exception when the verification status is invalid', function (mixed $value, string $given): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'status', $value);

    fakeHttp($response);

    expect(fn (): IdpayDriver => Helper::callGatewayFor(ApiMethod::Verify))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    sprintf('Expected "status" to be of type "int" for the idpay gateway, "%s" given.', $given)
                ),
        );
})->with('invalid_numeric_field_values');

it('communicates with sandbox environment for payment verification when configured', function (): void {
    fakeHttp(Helper::successfulVerificationResponse(), 200);

    Config::set('iran-payment.use_sandbox', true);

    Helper::callGatewayFor(ApiMethod::Verify);

    $request = getRecordedHttpRequest();

    expect($request)
        ->hasHeader('X-SANDBOX', '1')->toBeTrue();
});

it('returns card number and reference ID from successful verification', function (): void {
    fakeHttp(Helper::successfulVerificationResponse(), 200);

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->getRefNumber()->toBe('888001') // From fake verification response
        ->getCardNumber()->toBe('123456******1234'); // From fake verification response
});

it('returns empty string as card number and reference ID when not provided in the verification response', function (): void {
    $response = Arr::except(Helper::successfulVerificationResponse(), ['payment.track_id', 'payment.card_no']);

    fakeHttp($response, 200);

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->getRefNumber()->toBe('')
        ->getCardNumber()->toBe('');
});

it('returns failed response on the payment reversal', function (): void {
    fakeHttp(Helper::successfulVerificationResponse(), 200);

    $payment = Helper::callGatewayFor(ApiMethod::Reverse);

    expect($payment)
        ->toBeFailedPayment('9200', 'درگاه از بازگشت وجه پشتیبانی نمی کند')
        ->getRawResponse()->toBe('No API is called. IPG does not support reversal.');

    Http::assertSentCount(1); // Only verification is sent.
});

it('creates payment instance with no callback data', function (): void {
    $payment = Helper::driver()->noCallback(transactionId: '123456789');

    expect($payment)
        ->toBeInstanceOf(IdpayDriver::class)
        ->getTransactionId()->toBe('123456789');
});

it('verifies normally with no callback data', function (): void {
    fakeHttp(Helper::successfulVerificationResponse(), 200);

    $payment = Helper::driver()->noCallback('123456789012345');

    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    Http::assertSentCount(1);
});

it('returns failed response on the payment reversal with no callback data', function (): void {
    fakeHttp(Helper::successfulVerificationResponse(), 200);

    $payment = Helper::driver()->noCallback('123456789012345');
    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    Helper::callGatewayFor(ApiMethod::Reverse, $payment);

    expect($payment)
        ->toBeFailedPayment('9200', 'درگاه از بازگشت وجه پشتیبانی نمی کند')
        ->getRawResponse()->toBe('No API is called. IPG does not support reversal.');

    Http::assertSentCount(1); // Only verification is sent.
});

it('returns the internal error code when the API error code is invalid', function (ApiMethod $call, mixed $value, string $given): void {
    $response = Helper::failedResponse();
    Arr::set($response, 'error_code', $value);

    fakeHttp($response, 406);

    $payment = Helper::callGatewayFor($call);

    expect($payment)
        ->toBeFailedPayment('9400', sprintf('Expected "error_code" to be of type "int" for the idpay gateway, "%s" given.', $given))
        ->getRawResponse()->toBe($response);
})->with('gateway_creation_and_verification_methods')
    ->with('invalid_numeric_field_values');

it('returns the gateway error code with a fallback message when the API error message is invalid', function (ApiMethod $call): void {
    $response = Helper::failedResponse();
    Arr::set($response, 'error_message', null);

    fakeHttp($response, 406);

    $payment = Helper::callGatewayFor($call);

    expect($payment)
        ->toBeFailedPayment('32', 'Expected "error_message" to be of type "string" for the idpay gateway, "null" given.');
})->with('gateway_creation_and_verification_methods');

it('returns the internal error code when the API returns a non-JSON response', function (ApiMethod $call): void {
    fakeHttp('Service is not available', 406);

    $payment = Helper::callGatewayFor($call);

    expect($payment)
        ->toBeFailedPayment('9400', 'Expected "error_code" to be of type "int" for the idpay gateway, "null" given.')
        ->getRawResponse()->toBe('Service is not available');
})->with('gateway_creation_and_verification_methods');
