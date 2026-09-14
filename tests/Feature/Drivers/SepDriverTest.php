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
    fakeHttp($response = Helper::failedResponse('create'));

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('11')->toContain('شماره کارت نامعتبر است')
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
    'State',
    'Status',
    'ResNum',
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
    'State',
    'Status',
    'ResNum',
])->with([
    'null' => null,
    'empty string' => '',
]);

it('throws exception when stored payload and successful callback data do not match', function (string $payloadKey, string $callbackKey): void {
    fakeHttp();

    $callbackPayload = Helper::successfulCallback();

    $payload = Helper::gatewayPayload();
    Arr::set($payload, $payloadKey, '123'); // Change payload value for the given key so it no longer matches

    $payment = Helper::driver()->fromCallback($callbackPayload);

    expect(fn (): SepDriver => $payment->verify($payload))
        ->toThrow(
            InvalidCallbackDataException::class,
            sprintf('"%s" in the callback does not match with "%s" in the stored gateway payload.', $callbackKey, $payloadKey)
        );

    Http::assertNothingSent();
})->with([
    ['resNum', 'ResNum'],
    ['amount', 'Amount'],
]);

it('does not verify payment when callback status is not successful', function (): void {
    fakeHttp();

    $callbackPayload = Helper::failedCallback();

    $payment = Helper::driver()->fromCallback($callbackPayload);

    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('1')->toContain('کاربر انصراف داده است') // The error code is set by fake failed callback.
        ->getRawResponse()->toBe($callbackPayload);

    Http::assertNothingSent();
});

it('does not verify payment when callback status is unknown', function (): void {
    $callbackPayload = Helper::successfulCallback();
    Arr::set($callbackPayload, 'State', 'Unknown');

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
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on successful payment verification with invalid amount', function (): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'TransactionDetail.OrginalAmount', 2_000);

    fakeHttp($response);

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('9300')->toContain('مبلغ پرداخت شده نامعتبر است')
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
        ->successful()->toBeTrue()
        ->error()->toBeNull();
})->with([
    'verified amount as string' => ['1000', 1_000],
    'stored amount as string' => [1_000, '1000'],
]);

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
})->with([
    'missing value' => [null, 'null'],
    'non-numeric value' => ['abc', 'abc'],
]);

it('returns failed response on failed payment verification', function (): void {
    fakeHttp($response = Helper::failedResponse('verify'));

    $payment = Helper::callGatewayFor(ApiMethod::Verify);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('2')->toContain('تراکنش یافت نشد')
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
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment reversal', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulVerificationResponse(),
        secondResponse: $response = Helper::failedResponse('verify'),
    );

    $payment = Helper::callGatewayFor(ApiMethod::Reverse);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('-2')->toContain('تراکنش یافت نشد')
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
        ->successful()->toBeFalse()
        ->error()->toContain('9100')->toContain('درگاه از وریفای بدون callback پشتیبانی نمی کند.')
        ->getRawResponse()->toBe('No API is called.');

    Http::assertNothingSent();
});

it('returns successful reversal with no callback data', function (): void {
    fakeHttp();

    $payment = Helper::driver()->noCallback('123');
    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    Helper::callGatewayFor(ApiMethod::Reverse, $payment);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe('No API is called.');

    Http::assertNothingSent();
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
})->with([
    'missing value' => [null, 'null'],
    'non-numeric value' => ['abc', 'abc'],
]);

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
})->with([
    'verification' => ApiMethod::Verify,
    'reversal' => ApiMethod::Reverse,
])->with([
    'missing value' => [null, 'null'],
    'non-boolean value' => ['true', 'true'],
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
})->with([
    'verification' => ApiMethod::Verify,
    'reversal' => ApiMethod::Reverse,
]);

it('returns the internal error code when the creation API error code is invalid', function (mixed $value, string $given): void {
    $response = Helper::failedResponse('create');
    Arr::set($response, 'errorCode', $value);

    fakeHttp($response);

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('9400')
        ->error()->toContain(sprintf('Expected "errorCode" to be of type "int" for the sep gateway, "%s" given.', $given));
})->with([
    'missing value' => [null, 'null'],
    'non-numeric value' => ['abc', 'abc'],
]);

it('returns the gateway error code with a fallback message when the creation API error description is invalid', function (): void {
    $response = Helper::failedResponse('create');
    Arr::set($response, 'errorDesc', null);

    fakeHttp($response);

    $payment = Helper::callGatewayFor(ApiMethod::Create);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('11') // From fake failed response
        ->error()->toContain('Expected "errorDesc" to be of type "string" for the sep gateway, "null" given.');
});

it('returns the internal error code when the follow-up API result code is invalid', function (ApiMethod $call, mixed $value, string $given): void {
    $response = Helper::failedResponse($call->value);
    Arr::set($response, 'ResultCode', $value);

    $call === ApiMethod::Reverse
        ? fakeHttp(Helper::successfulVerificationResponse(), secondResponse: $response)
        : fakeHttp($response);

    $payment = Helper::callGatewayFor($call);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('9400')
        ->error()->toContain(sprintf('Expected "ResultCode" to be of type "int" for the sep gateway, "%s" given.', $given));
})->with([
    'verification' => ApiMethod::Verify,
    'reversal' => ApiMethod::Reverse,
])->with([
    'missing value' => [null, 'null'],
    'non-numeric value' => ['abc', 'abc'],
]);

it('returns the gateway error code with a fallback message when the follow-up API result description is invalid', function (ApiMethod $call): void {
    $response = Helper::failedResponse($call->value);
    Arr::set($response, 'ResultDescription', null);

    $call === ApiMethod::Reverse
        ? fakeHttp(Helper::successfulVerificationResponse(), secondResponse: $response)
        : fakeHttp($response);

    $payment = Helper::callGatewayFor($call);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('-2') // From fake failed response
        ->error()->toContain('Expected "ResultDescription" to be of type "string" for the sep gateway, "null" given.');
})->with([
    'verification' => ApiMethod::Verify,
    'reversal' => ApiMethod::Reverse,
]);

it('returns the internal error code when the callback status is invalid', function (): void {
    fakeHttp();

    $callbackPayload = Helper::failedCallback();
    Arr::set($callbackPayload, 'Status', 'abc');

    $payment = Helper::driver()->fromCallback($callbackPayload);

    Helper::callGatewayFor(ApiMethod::Verify, $payment);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('9400')
        ->error()->toContain('Expected "Status" to be of type "int" for the sep gateway, "abc" given.');

    Http::assertNothingSent();
});
