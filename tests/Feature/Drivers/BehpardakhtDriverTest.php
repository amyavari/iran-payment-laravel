<?php

declare(strict_types=1);

use AliYavari\IranPayment\Drivers\BehpardakhtDriver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\MissingCallbackDataException;
use AliYavari\IranPayment\Facades\Soap;
use AliYavari\IranPayment\Tests\Helpers\BehpardakhtHelper as Helper;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    Helper::setDriverConfigs();
});

it('generates and returns transaction ID on payment creation', function (): void {
    Helper::fakeSoap(Helper::successfulCreationResponse());
    mockUniqueNumberGenerator('123456789012345');

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->getTransactionId()->toBe('123456789012345');
});

it('calls payment creation API with minimum passed data and config callback URL', function (): void {
    Helper::fakeSoap(Helper::successfulCreationResponse());
    setTestNowIran('2025-12-10 18:30:10');

    $payment = Helper::driver()->create(1_000);

    Soap::assertWsdl('https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl');
    Soap::assertMethodCalled('bpPayRequest');

    expect(Soap::getArguments(0))
        ->terminalId->toBe(1234)
        ->userName->toBe('username')
        ->userPassword->toBe('password')
        ->orderId->toBe((int) $payment->getTransactionId())
        ->amount->toBe(1_000)
        ->localDate->toBe('20251210')
        ->localTime->toBe('183010')
        ->additionalData->toBe('')
        ->callBackUrl->toBe('http://callback.test') // config's callback URL
        ->payerId->toBe(0)
        ->not->toHaveKeys(['mobileNo', 'cartItem']);
});

it('calls payment creation API with full passed data', function (): void {
    Helper::fakeSoap(Helper::successfulCreationResponse());

    Helper::driver()->create(1_000, 'Description', '989123456789');

    // Only what differs from the previous test
    expect(Soap::getArguments(0))
        ->additionalData->toBe('Description')
        ->mobileNo->toBe('989123456789')
        ->cartItem->toBe('Description');
});

it('converts phone number to gateway format if needed', function (string|int $phone): void {
    Helper::fakeSoap(Helper::successfulCreationResponse());

    Helper::driver()->create(1_000, phone: $phone);

    expect(Soap::getArguments(0))
        ->mobileNo->toBe('989123456789');
})->with([
    'With country code' => 989123456789,
    'Without country code, with first zero' => '09123456789',
    'Without country code, and first zero' => 9123456789,
    'With country code, and first plus' => '+989123456789',
    'With country code and first zero' => 9809123456789,
    'With country code, first zero and first plus' => '+9809123456789',
]);

it('returns successful response on successful payment creation', function (): void {
    Helper::fakeSoap($response = Helper::successfulCreationResponse());

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment creation', function (): void {
    Helper::fakeSoap($response = Helper::failedResponse());

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 11- شماره کارت نامعتبر است')
        ->getRawResponse()->toBe($response);
});

it('returns gateway payload needed to verify payment on successful payment creation', function (): void {
    Helper::fakeSoap(Helper::successfulCreationResponse());

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->getGatewayPayload()->toBe([
            'orderId' => $payment->getTransactionId(),
            'amount' => 1_000,
            'refId' => 'AF82041a2Bf6989c7fF9', // From fake creation response
        ]);
});

it('returns gateway redirect data on successful payment creation with full passed data', function (): void {
    Helper::fakeSoap(Helper::successfulCreationResponse());

    URL::useOrigin('http://myapp.com');

    $payment = Helper::driver()->create(1_000, 'Description', '9123456789');

    expect($payment->getRedirectData())
        ->toBeInstanceOf(PaymentRedirectDto::class)
        ->url->toBe('https://bpm.shaparak.ir/pgwchannel/startpay.mellat')
        ->method->toBe('POST')
        ->payload->toBe([
            'RefId' => 'AF82041a2Bf6989c7fF9', // From fake creation response
            'MobileNo' => '989123456789',
            'CartItem' => 'Description',
        ])
        ->headers->toBe([
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Referer' => 'http://myapp.com',
        ]);
});

it('returns gateway redirect data on successful payment creation with minimum passed data', function (): void {
    Helper::fakeSoap(Helper::successfulCreationResponse());

    URL::useOrigin('http://myapp.com');

    $payment = Helper::driver()->create(1_000);

    expect($payment->getRedirectData())
        ->toBeInstanceOf(PaymentRedirectDto::class)
        ->url->toBe('https://bpm.shaparak.ir/pgwchannel/startpay.mellat')
        ->method->toBe('POST')
        ->payload->toBe([
            'RefId' => 'AF82041a2Bf6989c7fF9', // From fake creation response
        ])
        ->headers->toBe([
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Referer' => 'http://myapp.com',
        ]);
});

it('communicates with sandbox environment for payment creation when configured', function (): void {
    Helper::fakeSoap(Helper::successfulCreationResponse());

    Config::set('iran-payment.use_sandbox', true);

    $payment = Helper::driver()->create(1_000);

    Soap::assertWsdl('https://pgw.dev.bpmellat.ir/pgwchannel/services/pgw?wsdl');

    expect($payment)
        ->getRedirectData()->url->toBe('https://pgw.dev.bpmellat.ir/pgwchannel/startpay.mellat');
});

it('creates payment instance from callback data', function (): void {
    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

    expect($payment)
        ->toBeInstanceOf(BehpardakhtDriver::class)
        ->getTransactionId()->toBe('123456789012345'); // From fake callback
});

it('throws exception when callback lacks required keys', function (string $key): void {
    // Failed callback has minimum required keys; only ResCode value differs.
    $callbackPayload = Arr::except(Helper::failedCallback(), $key);

    expect(fn (): BehpardakhtDriver => Helper::driver()->fromCallback($callbackPayload))
        ->toThrow(
            MissingCallbackDataException::class,
            sprintf('To create behpardakht gateway instance from callback, "RefId, ResCode, SaleOrderId" are required. "%s" is missing.', $key)
        );
})->with([
    'RefId',
    'ResCode',
    'SaleOrderId',
]);

it('returns card number and reference ID from successful callback', function (): void {
    Helper::fakeSoap(Helper::successfulVerificationResponse());

    $payment = Helper::driver()->fromCallback(Helper::successfulCallback())->verify(Helper::gatewayPayload());

    expect($payment)
        ->getRefNumber()->toBe('227926981246') // From fake callback
        ->getCardNumber()->toBe('1234-*-*-1234'); // From fake callback
});

it('returns empty string as card number and reference ID when not provided in the callback', function (): void {
    Helper::fakeSoap(Helper::successfulVerificationResponse());

    $callbackPayload = Arr::except(Helper::successfulCallback(), ['SaleReferenceId', 'CardHolderInfo']);

    $payment = Helper::driver()->fromCallback($callbackPayload)->verify(Helper::gatewayPayload());

    expect($payment)
        ->getRefNumber()->toBe('')
        ->getCardNumber()->toBe('');
});

it('throws exception when stored payload and successful callback data do not match', function (string $payloadKey, string $callbackKey): void {
    Helper::fakeSoap();

    $payload = Helper::gatewayPayload();
    Arr::set($payload, $payloadKey, '123'); // Change payload value for the given key so it no longer matches

    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

    expect(fn (): BehpardakhtDriver => $payment->verify($payload))
        ->toThrow(
            InvalidCallbackDataException::class,
            sprintf('"%s" in the callback does not match with "%s" in the stored gateway payload.', $callbackKey, $payloadKey)
        );

    Soap::assertNothingSent();
})->with([
    ['orderId', 'SaleOrderId'],
    ['amount', 'FinalAmount'],
    ['refId', 'RefId'],
]);

it('does not verify payment when callback status is not successful', function (): void {
    Helper::fakeSoap();

    $callbackPayload = Helper::failedCallback();

    $payment = Helper::driver()
        ->fromCallback($callbackPayload)
        ->verify(Helper::gatewayPayload());

    Soap::assertNothingSent();

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 11- شماره کارت نامعتبر است') // The error code is set by fake failed callback.
        ->getRawResponse()->toBe($callbackPayload);
});

it('verifies payment when callback is successful and matches stored payload', function (): void {
    Helper::fakeSoap(Helper::successfulVerificationResponse());

    Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    Soap::assertWsdl('https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl');
    Soap::assertMethodCalled('bpVerifyRequest');

    expect(Soap::getArguments(0))
        ->terminalId->toBe(1234)
        ->userName->toBe('username')
        ->userPassword->toBe('password')
        ->orderId->toBe(123456789012345) // From fake callback
        ->saleOrderId->toBe(123456789012345) // From fake callback
        ->saleReferenceId->toBe(227926981246); // From fake callback
});

it('returns successful response on successful payment verification', function (): void {
    Helper::fakeSoap($response = Helper::successfulVerificationResponse());

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment verification', function (): void {
    Helper::fakeSoap($response = Helper::failedResponse());

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 11- شماره کارت نامعتبر است')
        ->getRawResponse()->toBe($response);
});

it('communicates with sandbox environment for payment verification when configured', function (): void {
    Helper::fakeSoap(Helper::successfulVerificationResponse());

    Config::set('iran-payment.use_sandbox', true);

    Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    Soap::assertWsdl('https://pgw.dev.bpmellat.ir/pgwchannel/services/pgw?wsdl');
});

it('reverses the payment', function (): void {
    Helper::fakeSoap(Helper::successfulReversalResponse());

    Helper::verifiedPayment()->reverse();

    Soap::assertWsdl('https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl');
    Soap::assertMethodCalled('bpReversalRequest');

    expect(Soap::getArguments(0))
        ->terminalId->toBe(1234)
        ->userName->toBe('username')
        ->userPassword->toBe('password')
        ->orderId->toBe(123456789012345) // From fake callback
        ->saleOrderId->toBe(123456789012345) // From fake callback
        ->saleReferenceId->toBe(227926981246); // From fake callback
});

it('returns successful response on successful payment reversal', function (): void {
    Helper::fakeSoap($response = Helper::successfulReversalResponse());

    $payment = Helper::verifiedPayment()->reverse();

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment reversal', function (): void {
    Helper::fakeSoap($response = Helper::failedResponse());

    $payment = Helper::verifiedPayment()->reverse();

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 11- شماره کارت نامعتبر است')
        ->getRawResponse()->toBe($response);
});

it('communicates with sandbox environment for payment reversal when configured', function (): void {
    Helper::fakeSoap(Helper::successfulReversalResponse());

    Config::set('iran-payment.use_sandbox', true);

    Helper::verifiedPayment()->reverse();

    Soap::assertWsdl('https://pgw.dev.bpmellat.ir/pgwchannel/services/pgw?wsdl');
});

it('creates payment instance with no callback data', function (): void {
    $payment = Helper::driver()->noCallback(transactionId: '123456789');

    expect($payment)
        ->toBeInstanceOf(BehpardakhtDriver::class)
        ->getTransactionId()->toBe('123456789');
});

it('returns failed verification with no callback data', function (): void {
    Helper::fakeSoap();

    $payment = Helper::driver()->noCallback('123');

    $payment->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 9100- درگاه از وریفای بدون callback پشتیبانی نمی کند.')
        ->getRawResponse()->toBe('No API is called.');

    Soap::assertNothingSent();
});

it('returns successful reversal with no callback data', function (): void {
    Helper::fakeSoap();

    $payment = Helper::driver()->noCallback('123')->verify(Helper::gatewayPayload());

    $payment->reverse();

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe('No API is called.');

    Soap::assertNothingSent();
});
