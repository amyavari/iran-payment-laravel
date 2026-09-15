<?php

declare(strict_types=1);

use AliYavari\IranPayment\Drivers\SepDriver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Enums\ApiMethod;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\InvalidGatewayDataException;
use AliYavari\IranPayment\Exceptions\MissingCallbackDataException;
use AliYavari\IranPayment\Exceptions\SandboxNotSupportedException;
use AliYavari\IranPayment\Tests\Helpers\SepHelper as Helper;
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
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Create);

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
    fakeHttp(Helper::successfulCreationResponse());

    Helper::driver()->create(1_000, 'Description', '9123456789');

    $request = getRecordedHttpRequest();

    // Only what differs from the previous test
    expect($request->data())
        ->CellNumber->toBe('9123456789');
});

it('converts phone number to gateway format if needed', function (string|int $phone): void {
    fakeHttp(Helper::successfulCreationResponse());

    Helper::driver()->create(1_000, phone: $phone);

    $request = getRecordedHttpRequest();

    expect($request->data())
        ->CellNumber->toBe('9123456789');
})->with('gateway_phone_number_formats');

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
        ->toBeFailedPayment('11', 'شماره کارت نامعتبر است')
        ->getRawResponse()->toBe($response);
});

it('returns gateway payload needed to verify payment on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->getGatewayPayload()->toBe([
            'resNum' => $payment->getTransactionId(),
            'amount' => 1_000,
        ]);
});

it('returns gateway redirect data on successful payment creation', function (): void {
    fakeHttp(Helper::successfulCreationResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment->getRedirectData())
        ->toBeInstanceOf(PaymentRedirectDto::class)
        ->url->toBe('https://sep.shaparak.ir/OnlinePG/SendToken')
        ->method->toBe('GET')
        ->payload->toBe([
            'token' => '2c3c1fefac5a48geb9f9be7e445dd9b2', // From fake creation response
        ])
        ->headers->toBe([]);
});

it('throws exception when the creation API status is invalid', function (mixed $value, string $given): void {
    $response = Helper::successfulCreationResponse();
    Arr::set($response, 'status', $value);

    fakeHttp($response);

    expect(fn (): SepDriver => Helper::callGatewayFor(ApiMethod::Create))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    sprintf('Expected "status" to be of type "int" for the sep gateway, "%s" given.', $given)
                ),
        );
})->with('invalid_numeric_field_values');

it('throws exception when the creation API returns a non-JSON response', function (): void {
    $response = 'Service is not available';

    fakeHttp($response);

    expect(fn (): SepDriver => Helper::callGatewayFor(ApiMethod::Create))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    'Expected "status" to be of type "int" for the sep gateway, "null" given.'
                ),
        );
});

it('throws exception when the creation token is invalid', function (mixed $value, string $given): void {
    $response = Helper::successfulCreationResponse();
    Arr::set($response, 'token', $value);

    fakeHttp($response);

    expect(fn (): SepDriver => Helper::callGatewayFor(ApiMethod::Create))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    sprintf('Expected "token" to be of type "string" for the sep gateway, "%s" given.', $given)
                ),
        );
})->with('invalid_string_field_values');

it('returns the internal error code when the creation API error code is invalid', function (mixed $value, string $given): void {
    $response = Helper::failedResponse('create');
    Arr::set($response, 'errorCode', $value);

    fakeHttp($response);

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->toBeFailedPayment('9400', sprintf('Expected "errorCode" to be of type "int" for the sep gateway, "%s" given.', $given));
})->with('invalid_numeric_field_values');

it('returns the gateway error code with a fallback message when the creation API error description is invalid', function (): void {
    $response = Helper::failedResponse('create');
    Arr::set($response, 'errorDesc', null);

    fakeHttp($response);

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->toBeFailedPayment('11', 'Expected "errorDesc" to be of type "string" for the sep gateway, "null" given.');
});

it('throws an exception for payment creation when configured to use sandbox', function (): void {
    fakeHttp();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): SepDriver => Helper::callGatewayFor(ApiMethod::Create))
        ->toThrow(SandboxNotSupportedException::class, 'Sep gateway does not support the sandbox environment.');

    Http::assertNothingSent();
});

it('creates payment instance from callback data', function (): void {
    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

    expect($payment)
        ->toBeInstanceOf(SepDriver::class)
        ->getTransactionId()->toBe('123456789012345');
});

it('throws exception when callback lacks required keys', function (string $key): void {
    // Failed callback has minimum required keys; only State, and Status values differ.
    $callbackPayload = collect(Helper::failedCallback())->except([$key])->all();

    expect(fn (): SepDriver => Helper::driver()->fromCallback($callbackPayload))
        ->toThrow(
            MissingCallbackDataException::class,
            sprintf('To create sep gateway instance from callback, "State, Status, ResNum" are required. "%s" is missing.', $key)
        );
})->with([
    'State' => ['key' => 'State'],
    'Status' => ['key' => 'Status'],
    'ResNum' => ['key' => 'ResNum'],
]);

it('throws exception when a required callback key is blank', function (string $key, mixed $value): void {
    // Failed callback has minimum required keys; only State, and Status values differ.
    $callbackPayload = Helper::failedCallback();
    Arr::set($callbackPayload, $key, $value);

    expect(fn (): SepDriver => Helper::driver()->fromCallback($callbackPayload))
        ->toThrow(
            MissingCallbackDataException::class,
            sprintf('To create sep gateway instance from callback, "State, Status, ResNum" are required. "%s" is empty.', $key)
        );
})->with([
    'State' => ['key' => 'State'],
    'Status' => ['key' => 'Status'],
    'ResNum' => ['key' => 'ResNum'],
])->with([
    'null' => ['value' => null],
    'empty string' => ['value' => ''],
]);

it('throws exception when stored payload and successful callback data do not match', function (string $payloadKey, string $callbackKey): void {
    fakeHttp();

    $payload = Helper::gatewayPayload();
    Arr::set($payload, $payloadKey, '123'); // Change payload value for the given key so it no longer matches

    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

    expect(fn (): SepDriver => $payment->verify($payload))
        ->toThrow(
            InvalidCallbackDataException::class,
            sprintf('"%s" in the callback does not match with "%s" in the stored gateway payload.', $callbackKey, $payloadKey)
        );

    Http::assertNothingSent();
})->with([
    'ResNum' => ['payloadKey' => 'resNum', 'callbackKey' => 'ResNum'],
    'Amount' => ['payloadKey' => 'amount', 'callbackKey' => 'Amount'],
]);

it('does not verify payment when callback status is not successful', function (): void {
    fakeHttp();

    $callbackPayload = Helper::failedCallback();

    $payment = Helper::driver()->fromCallback($callbackPayload);

    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    expect($payment)
        ->toBeFailedPayment('1', 'کاربر انصراف داده است') // The error code is set by fake failed callback.
        ->getRawResponse()->toBe($callbackPayload);

    Http::assertNothingSent();
});

it('does not verify payment when callback status is unknown', function (): void {
    $callbackPayload = Helper::successfulCallback();
    Arr::set($callbackPayload, 'State', 'Unknown');

    $payment = Helper::driver()->fromCallback($callbackPayload);

    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    expect($payment)
        ->toBeFailedPayment('2', 'کد پاسخ نامشخص');
});

it('returns the internal error code when the callback status is invalid', function (): void {
    fakeHttp();

    $callbackPayload = Helper::failedCallback();
    Arr::set($callbackPayload, 'Status', 'abc');

    $payment = Helper::driver()->fromCallback($callbackPayload);

    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    expect($payment)
        ->toBeFailedPayment('9400', 'Expected "Status" to be of type "int" for the sep gateway, "abc" given.');

    Http::assertNothingSent();
});

it('verifies payment when callback is successful and matches stored payload', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    Helper::callGatewayFor(ApiMethod::Verify);

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
    fakeHttp($response = Helper::successfulVerificationResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->toBeSuccessfulPayment()
        ->getRawResponse()->toBe($response);
});

it('returns successful response on payment verification when the stored and verified amounts have different types', function (mixed $verifiedAmount, mixed $storedAmount): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'TransactionDetail.OrginalAmount', $verifiedAmount);

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
    Arr::set($response, 'TransactionDetail.OrginalAmount', 2_000);

    fakeHttp($response);

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->toBeFailedPayment('9300', 'مبلغ پرداخت شده نامعتبر است')
        ->getRawResponse()->toBe($response);
});

it('throws exception when the verified amount is not numeric', function (mixed $value, string $given): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'TransactionDetail.OrginalAmount', $value);

    fakeHttp($response);

    expect(fn (): SepDriver => Helper::callGatewayFor(ApiMethod::Verify))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    sprintf('Expected "TransactionDetail.OrginalAmount" to be of type "int" for the sep gateway, "%s" given.', $given)
                ),
        );
})->with('invalid_numeric_field_values');

it('returns failed response on failed payment verification', function (): void {
    fakeHttp($response = Helper::failedResponse('verify'));

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->toBeFailedPayment('2', 'تراکنش یافت نشد')
        ->getRawResponse()->toBe($response);
});

it('throws an exception for payment verification when configured to use sandbox', function (): void {
    fakeHttp();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): SepDriver => Helper::callGatewayFor(ApiMethod::Verify))
        ->toThrow(SandboxNotSupportedException::class, 'Sep gateway does not support the sandbox environment.');

    Http::assertNothingSent();
});

it('returns card number and reference ID from successful verification', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->getRefNumber()->toBe('227926981246') // From fake followup response
        ->getCardNumber()->toBe('123456****1234'); // From fake followup response
});

it('returns empty string as card number and reference ID when not provided in the verification response', function (): void {
    $response = Arr::except(Helper::successfulVerificationResponse(), ['TransactionDetail.RRN', 'TransactionDetail.MaskedPan']);

    fakeHttp($response);

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->getRefNumber()->toBe('')
        ->getCardNumber()->toBe('');
});

it('reverses the payment', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulVerificationResponse(),
        secondResponse: Helper::successfulReversalResponse(),
    );

    Helper::callGatewayFor(ApiMethod::Reverse);

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
        firstResponse: Helper::successfulVerificationResponse(),
        secondResponse: $response = Helper::successfulReversalResponse(),
    );

    $payment = Helper::callGatewayFor(ApiMethod::Reverse);

    expect($payment)
        ->toBeSuccessfulPayment()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment reversal', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulVerificationResponse(),
        secondResponse: $response = Helper::failedResponse('verify'),
    );

    $payment = Helper::callGatewayFor(ApiMethod::Reverse);

    expect($payment)
        ->toBeFailedPayment('-2', 'تراکنش یافت نشد')
        ->getRawResponse()->toBe($response);
});

it('throws an exception for payment reversal when configured to use sandbox', function (): void {
    fakeHttp(Helper::successfulVerificationResponse());

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): SepDriver => Helper::callGatewayFor(ApiMethod::Reverse, $payment))
        ->toThrow(SandboxNotSupportedException::class, 'Sep gateway does not support the sandbox environment.');

    Http::assertSentCount(1); // Only verification, before set to sandbox
});

it('creates payment instance with no callback data', function (): void {
    $payment = Helper::driver()->noCallback(transactionId: '123456789');

    expect($payment)
        ->toBeInstanceOf(SepDriver::class)
        ->getTransactionId()->toBe('123456789');
});

it('returns failed verification with no callback data', function (): void {
    fakeHttp();

    $payment = Helper::driver()->noCallback('123');

    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    expect($payment)
        ->toBeFailedPayment('9100', 'درگاه از وریفای بدون callback پشتیبانی نمی کند.')
        ->getRawResponse()->toBe('No API is called.');

    Http::assertNothingSent();
});

it('returns successful reversal with no callback data', function (): void {
    fakeHttp();

    $payment = Helper::driver()->noCallback('123');
    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    Helper::callGatewayFor(ApiMethod::Reverse, $payment);

    expect($payment)
        ->toBeSuccessfulPayment()
        ->getRawResponse()->toBe('No API is called.');

    Http::assertNothingSent();
});

it('throws exception when the follow-up API success flag is invalid', function (ApiMethod $call, mixed $value, string $given): void {
    $response = match ($call) {
        ApiMethod::Verify => Helper::successfulVerificationResponse(),
        ApiMethod::Reverse => Helper::successfulReversalResponse(),
    };
    Arr::set($response, 'Success', $value);

    $call === ApiMethod::Reverse
        ? fakeHttp(Helper::successfulVerificationResponse(), secondResponse: $response)
        : fakeHttp($response);

    expect(fn (): SepDriver => Helper::callGatewayFor($call))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    sprintf('Expected "Success" to be of type "bool" for the sep gateway, "%s" given.', $given)
                ),
        );
})->with('gateway_verification_and_reversal_methods')
    ->with([
        'missing value' => ['value' => null, 'given' => 'null'],
        'non-boolean value' => ['value' => 'true', 'given' => 'true'],
    ]);

it('throws exception when the follow-up API returns a non-JSON response', function (ApiMethod $call): void {
    $response = 'Service is not available';

    $call === ApiMethod::Reverse
        ? fakeHttp(Helper::successfulVerificationResponse(), secondResponse: $response)
        : fakeHttp($response);

    expect(fn (): SepDriver => Helper::callGatewayFor($call))
        ->toThrow(
            fn (InvalidGatewayDataException $exception) => expect($exception)
                ->context()->toBe(['body' => $response])
                ->getMessage()->toBe(
                    'Expected "Success" to be of type "bool" for the sep gateway, "null" given.'
                ),
        );
})->with('gateway_verification_and_reversal_methods');

it('returns the internal error code when the follow-up API result code is invalid', function (ApiMethod $call, mixed $value, string $given): void {
    $response = Helper::failedResponse($call->value);
    Arr::set($response, 'ResultCode', $value);

    $call === ApiMethod::Reverse
        ? fakeHttp(Helper::successfulVerificationResponse(), secondResponse: $response)
        : fakeHttp($response);

    $payment = Helper::callGatewayFor($call);

    expect($payment)
        ->toBeFailedPayment('9400', sprintf('Expected "ResultCode" to be of type "int" for the sep gateway, "%s" given.', $given));
})->with('gateway_verification_and_reversal_methods')
    ->with('invalid_numeric_field_values');

it('returns the gateway error code with a fallback message when the follow-up API result description is invalid', function (ApiMethod $call): void {
    $response = Helper::failedResponse($call->value);
    Arr::set($response, 'ResultDescription', null);

    $call === ApiMethod::Reverse
        ? fakeHttp(Helper::successfulVerificationResponse(), secondResponse: $response)
        : fakeHttp($response);

    $payment = Helper::callGatewayFor($call);

    expect($payment)
        ->toBeFailedPayment('-2', 'Expected "ResultDescription" to be of type "string" for the sep gateway, "null" given.');
})->with('gateway_verification_and_reversal_methods');
