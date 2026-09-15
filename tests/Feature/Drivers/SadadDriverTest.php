<?php

declare(strict_types=1);

use AliYavari\IranPayment\Drivers\SadadDriver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Enums\ApiMethod;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\InvalidGatewayDataException;
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

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->getTransactionId()->toBe('123456789012345');
});

it('calls payment creation API with minimum passed data and config callback URL', function (): void {
    setTestNowIran('2025-12-10 18:30:10');

    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Create);

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
})->with('gateway_phone_number_formats');

it('signs the necessary input data', function (): void {
    fakeHttp(Helper::successfulCreationResponse());
    mockUniqueNumberGenerator('123456789012345');

    Helper::callGatewayFor(ApiMethod::Create);

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

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->toBeSuccessfulPayment()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment creation', function (): void {
    fakeHttp($response = Helper::failedResponse('create'));

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->toBeFailedPayment('61', 'مبلغ تراکنش از حد مجاز بالاتر است')
        ->getRawResponse()->toBe($response);
});

it('returns gateway payload needed to verify payment on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->getGatewayPayload()->toBe([
            'orderId' => $payment->getTransactionId(),
            'token' => 'kjslflnvda13464sdv13a', // From fake creation response
            'amount' => 1_000,
        ]);
});

it('returns gateway redirect data on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment->getRedirectData())
        ->toBeInstanceOf(PaymentRedirectDto::class)
        ->url->toBe('https://sadad.shaparak.ir/Purchase')
        ->method->toBe('GET')
        ->payload->toBe(['Token' => 'kjslflnvda13464sdv13a']) // From fake creation response
        ->headers->toBe([]);
});

it('throws exception when the creation token is invalid', function (mixed $value, string $given): void {
    $response = Helper::successfulCreationResponse();
    Arr::set($response, 'Token', $value);

    fakeHttp($response);

    expect(fn (): SadadDriver => Helper::callGatewayFor(ApiMethod::Create))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    sprintf('Expected "Token" to be of type "string" for the sadad gateway, "%s" given.', $given)
                ),
        );
})->with('invalid_string_field_values');

it('throws an exception for payment creation when configured to use sandbox', function (): void {
    fakeHttp();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): SadadDriver => Helper::callGatewayFor(ApiMethod::Create))
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
    'OrderId' => ['key' => 'OrderId'],
    'ResCode' => ['key' => 'ResCode'],
]);

it('throws exception when a required callback key is blank', function (string $key, mixed $value): void {
    // Failed callback has minimum required keys; only ResCode value differs.
    $callbackPayload = Helper::failedCallback();
    Arr::set($callbackPayload, $key, $value);

    expect(fn (): SadadDriver => Helper::driver()->fromCallback($callbackPayload))
        ->toThrow(
            MissingCallbackDataException::class,
            sprintf('To create sadad gateway instance from callback, "OrderId, ResCode" are required. "%s" is empty.', $key)
        );
})->with([
    'OrderId' => ['key' => 'OrderId'],
    'ResCode' => ['key' => 'ResCode'],
])->with([
    'null' => ['value' => null],
    'empty string' => ['value' => ''],
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
    'OrderId' => ['payloadKey' => 'orderId', 'callbackKey' => 'OrderId'],
]);

it('does not verify payment when callback status is not successful', function (): void {
    fakeHttp();

    $callbackPayload = Helper::failedCallback();

    $payment = Helper::driver()->fromCallback($callbackPayload);

    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    expect($payment)
        ->toBeFailedPayment('-1', 'تراکنش ناموفق')
        ->getRawResponse()->toBe($callbackPayload);

    Http::assertNothingSent();
});

it('throws exception when the callback status code is invalid', function (): void {
    fakeHttp();

    $callbackPayload = Helper::failedCallback();
    Arr::set($callbackPayload, 'ResCode', 'abc');

    expect(fn (): SadadDriver => Helper::driver()->fromCallback($callbackPayload))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $callbackPayload])
                ->getMessage()->toBe(
                    'Expected "ResCode" to be of type "int" for the sadad gateway, "abc" given.'
                ),
        );

    Http::assertNothingSent();
});

it('verifies payment when callback is successful and matches stored payload', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    Helper::callGatewayFor(ApiMethod::Verify);

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

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->toBeSuccessfulPayment()
        ->getRawResponse()->toBe($response);
});

it('returns successful response on subsequence successful payment verification', function (): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'ResCode', 100); // In the subsequence successful verifications it returns `100` instead of `0`

    fakeHttp($response);

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->toBeSuccessfulPayment()
        ->getRawResponse()->toBe($response);
});

it('returns successful response on payment verification when the stored and verified amounts have different types', function (mixed $verifiedAmount, mixed $storedAmount): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'Amount', $verifiedAmount);

    $payload = Helper::gatewayPayload();
    Arr::set($payload, 'amount', $storedAmount);

    fakeHttp($response);

    $payment = Helper::paymentReadyFor(ApiMethod::Verify)->verify($payload);

    expect($payment)
        ->toBeSuccessfulPayment();
})->with([
    'verified amount as string' => ['verifiedAmount' => '1000', 'storedAmount' => 1_000],
    'stored amount as string' => ['verifiedAmount' => 1_000, 'storedAmount' => '1000'],
]);

it('returns failed response on successful payment verification with invalid amount', function (): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'Amount', 2_000);

    fakeHttp($response);

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->toBeFailedPayment('9300', 'مبلغ پرداخت شده نامعتبر است')
        ->getRawResponse()->toBe($response);
});

it('throws exception when the verified amount is not numeric', function (mixed $value, string $given): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'Amount', $value);

    fakeHttp($response);

    expect(fn (): SadadDriver => Helper::callGatewayFor(ApiMethod::Verify))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    sprintf('Expected "Amount" to be of type "int" for the sadad gateway, "%s" given.', $given)
                ),
        );
})->with('invalid_numeric_field_values');

it('returns failed response on failed payment verification', function (): void {
    fakeHttp($response = Helper::failedResponse('verify'));

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->toBeFailedPayment('-1', 'تراکنش ناموفق')
        ->getRawResponse()->toBe($response);
});

it('throws an exception for payment verification when configured to use sandbox', function (): void {
    fakeHttp();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): SadadDriver => Helper::callGatewayFor(ApiMethod::Verify))
        ->toThrow(SandboxNotSupportedException::class, 'Sadad gateway does not support the sandbox environment.');

    Http::assertNothingSent();
});

it('returns card number and reference ID from successful verification', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->getRefNumber()->toBe('142514251425') // From fake verification response
        ->getCardNumber()->toBe('123456******1234'); // From fake callback
});

it('returns empty string as card number and reference ID when not provided in the callback and verification response', function (): void {
    fakeHttp(Arr::except(Helper::successfulVerificationResponse(), 'RetrivalRefNo'));

    $callbackPayload = Arr::except(Helper::successfulCallback(), 'PrimaryAccNo');

    $payment = Helper::driver()->fromCallback($callbackPayload);

    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    expect($payment)
        ->getRefNumber()->toBe('')
        ->getCardNumber()->toBe('');
});

it('returns failed response on the payment reversal', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Reverse);

    expect($payment)
        ->toBeFailedPayment('9200', 'درگاه از بازگشت وجه پشتیبانی نمی کند')
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

    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    Http::assertSentCount(1);
});

it('returns failed response on the payment reversal with no callback data', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::driver()->noCallback('123456789012345');
    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    Helper::callGatewayFor(ApiMethod::Reverse, $payment);

    expect($payment)
        ->toBeFailedPayment('9200', 'درگاه از بازگشت وجه پشتیبانی نمی کند')
        ->getRawResponse()->toBe('No API is called. IPG does not support reversal.');

    Http::assertSentCount(1); // Only verification is sent.
});

it('throws exception when the API status code is invalid', function (ApiMethod $call, mixed $value, string $given): void {
    $response = Helper::successfulResponseFor($call);
    Arr::set($response, 'ResCode', $value);

    fakeHttp($response);

    expect(fn (): SadadDriver => Helper::callGatewayFor($call))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    sprintf('Expected "ResCode" to be of type "int" for the sadad gateway, "%s" given.', $given)
                ),
        );
})->with('gateway_creation_and_verification_methods')
    ->with('invalid_numeric_field_values');

it('throws exception when the API returns a non-JSON response', function (ApiMethod $call): void {
    $response = 'Service is not available';

    fakeHttp($response);

    expect(fn (): SadadDriver => Helper::callGatewayFor($call))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    'Expected "ResCode" to be of type "int" for the sadad gateway, "null" given.'
                ),
        );
})->with('gateway_creation_and_verification_methods');
