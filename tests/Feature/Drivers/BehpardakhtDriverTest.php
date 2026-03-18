<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Feature\Drivers\BehpardakhtDriverTest; // To avoid helper functions conflict.

use AliYavari\IranPayment\Drivers\BehpardakhtDriver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\MissingCallbackDataException;
use AliYavari\IranPayment\Facades\Payment;
use AliYavari\IranPayment\Facades\Soap;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    setDriverConfigs();
});

it('generates and returns transaction ID on payment creation', function (): void {
    fakeSoap(successfulCreationResponse());

    $payment = driver()->create(1_000);

    expect($payment)
        ->getTransactionId()->toBeString()->toBeNumeric()->toHaveLength(15);
});

it('calls payment creation API with minimum passed data and config callback URL', function (): void {
    fakeSoap(successfulCreationResponse());
    setTestNowIran('2025-12-10 18:30:10');

    $payment = driver()->create(1_000);

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
    fakeSoap(successfulCreationResponse());

    driver()->create(1_000, 'Description', '989123456789');

    // Only what differs from the previous test
    expect(Soap::getArguments(0))
        ->additionalData->toBe('Description')
        ->mobileNo->toBe('989123456789')
        ->cartItem->toBe('Description');
});

it('converts phone number to gateway format if needed', function (string|int $phone): void {
    fakeSoap(successfulCreationResponse());

    driver()->create(1_000, phone: $phone);

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
    fakeSoap($response = successfulCreationResponse());

    $payment = driver()->create(1_000);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment creation', function (): void {
    // Sample failed API response
    fakeSoap($response = '11');

    $payment = driver()->create(1_000);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 11- شماره کارت نامعتبر است')
        ->getRawResponse()->toBe($response);
});

it('returns gateway payload needed to verify payment on successful payment creation', function (): void {
    fakeSoap(successfulCreationResponse());

    $payment = driver()->create(1_000);

    expect($payment)
        ->getGatewayPayload()->toBe([
            'orderId' => $payment->getTransactionId(),
            'amount' => 1_000,
            'refId' => 'AF82041a2Bf6989c7fF9', // From fake creation response
        ]);
});

it('returns gateway redirect data on successful payment creation with full passed data', function (): void {
    fakeSoap(successfulCreationResponse());

    URL::useOrigin('http://myapp.com');

    $payment = driver()->create(1_000, 'Description', '9123456789');

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
    fakeSoap(successfulCreationResponse());

    URL::useOrigin('http://myapp.com');

    $payment = driver()->create(1_000);

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
    fakeSoap(successfulCreationResponse());

    Config::set('iran-payment.use_sandbox', true);

    $payment = driver()->create(1_000);

    Soap::assertWsdl('https://pgw.dev.bpmellat.ir/pgwchannel/services/pgw?wsdl');

    expect($payment)
        ->getRedirectData()->url->toBe('https://pgw.dev.bpmellat.ir/pgwchannel/startpay.mellat');
});

it('creates payment instance from callback data', function (): void {
    $callbackPayload = callbackFactory()->successful()->all();

    $payment = driver()->fromCallback($callbackPayload);

    expect($payment)
        ->toBeInstanceOf(BehpardakhtDriver::class)
        ->getTransactionId()->toBe('123456789012345'); // From fake callback
});

it('throws exception when callback lacks required keys', function (string $key): void {
    // Failed callback has minimum required keys; only ResCode value differs.
    $callbackPayload = callbackFactory()->failed()->except([$key])->all();

    expect(fn (): BehpardakhtDriver => driver()->fromCallback($callbackPayload))
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
    fakeSoap(successfulFollowUpResponse());

    $callbackPayload = callbackFactory()->successful()->all();

    $payment = driver()->fromCallback($callbackPayload)->verify(gatewayPayload());

    expect($payment)
        ->getRefNumber()->toBe('227926981246') // From fake callback
        ->getCardNumber()->toBe('1234-*-*-1234'); // From fake callback
});

it('returns empty string as card number and reference ID when not provided in the callback', function (): void {
    fakeSoap(successfulFollowUpResponse());

    $callbackPayload = callbackFactory()->successful()->except(['SaleReferenceId', 'CardHolderInfo'])->all();

    $payment = driver()->fromCallback($callbackPayload)->verify(gatewayPayload());

    expect($payment)
        ->getRefNumber()->toBe('')
        ->getCardNumber()->toBe('');
});

it('throws exception when stored payload and successful callback data do not match', function (string $payloadKey, string $callbackKey): void {
    fakeSoap();

    $callbackPayload = callbackFactory()->successful()->all();

    $payload = gatewayPayload();
    Arr::set($payload, $payloadKey, '123'); // Change payload value for the given key so it no longer matches

    $payment = driver()->fromCallback($callbackPayload);

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
    fakeSoap();

    $callbackPayload = callbackFactory()->failed()->all();

    $payment = driver()
        ->fromCallback($callbackPayload)
        ->verify(gatewayPayload());

    Soap::assertNothingSent();

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 11- شماره کارت نامعتبر است') // The error code is set by fake failed callback.
        ->getRawResponse()->toBe($callbackPayload);
});

it('verifies payment when callback is successful and matches stored payload', function (): void {
    fakeSoap(successfulFollowUpResponse());

    driverFromSuccessfulCallback()->verify(gatewayPayload());

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
    fakeSoap($response = successfulFollowUpResponse());

    $payment = driverFromSuccessfulCallback()->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment verification', function (): void {
    // Sample failed API response
    fakeSoap($response = '11');

    $payment = driverFromSuccessfulCallback()->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 11- شماره کارت نامعتبر است')
        ->getRawResponse()->toBe($response);
});

it('communicates with sandbox environment for payment verification when configured', function (): void {
    fakeSoap(successfulFollowUpResponse());

    Config::set('iran-payment.use_sandbox', true);

    driverFromSuccessfulCallback()->verify(gatewayPayload());

    Soap::assertWsdl('https://pgw.dev.bpmellat.ir/pgwchannel/services/pgw?wsdl');
});

it('reverses the payment', function (): void {
    fakeSoap(successfulFollowUpResponse());

    verifiedPayment()->reverse();

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
    fakeSoap($response = successfulFollowUpResponse());

    $payment = verifiedPayment()->reverse();

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment reversal', function (): void {
    // Sample failed API response
    fakeSoap($response = '11');

    $payment = verifiedPayment()->reverse();

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 11- شماره کارت نامعتبر است')
        ->getRawResponse()->toBe($response);
});

it('communicates with sandbox environment for payment reversal when configured', function (): void {
    fakeSoap(successfulFollowUpResponse());

    Config::set('iran-payment.use_sandbox', true);

    verifiedPayment()->reverse();

    Soap::assertWsdl('https://pgw.dev.bpmellat.ir/pgwchannel/services/pgw?wsdl');
});

it('creates payment instance with no callback data', function (): void {
    $payment = driver()->noCallback(transactionId: '123');

    expect($payment)
        ->toBeInstanceOf(BehpardakhtDriver::class)
        ->getTransactionId()->toBe('123');
});

it('returns failed verification with no callback data', function (): void {
    fakeSoap();

    $payment = driver()->noCallback('123');

    $payment->verify(gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 1001- درگاه از وریفای بدون callback پشتیبانی نمی کند.')
        ->getRawResponse()->toBe('No API is called.');

    Soap::assertNothingSent();
});

it('returns successful reversal with no callback data', function (): void {
    fakeSoap();

    $payment = driver()->noCallback('123')->verify(gatewayPayload());

    $payment->reverse();

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe('No API is called.');

    Soap::assertNothingSent();
});

// ------------
// Helpers
// ------------

function setDriverConfigs(): void
{
    Config::set('iran-payment.gateways.behpardakht.callback_url', 'http://callback.test');
    Config::set('iran-payment.gateways.behpardakht.terminal_id', '1234');
    Config::set('iran-payment.gateways.behpardakht.username', 'username');
    Config::set('iran-payment.gateways.behpardakht.password', 'password');
}

function fakeSoap(string $response = ''): void
{
    Soap::fake($response);
}

function driver(): BehpardakhtDriver
{
    return Payment::gateway('behpardakht');
}

function successfulCreationResponse(): string
{
    return '0,AF82041a2Bf6989c7fF9';
}

function successfulFollowUpResponse(): string
{
    return '0';
}

function driverFromSuccessfulCallback(): BehpardakhtDriver
{
    $callback = callbackFactory()->successful()->all();

    return driver()->fromCallback($callback);
}

function verifiedPayment(): BehpardakhtDriver
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
                'RefId' => 'AF82041a2Bf6989c7fF9',
                'ResCode' => 0,
                'SaleOrderId' => 123456789012345,
                'SaleReferenceId' => 227926981246,
                'CardHolderInfo' => '1234-*-*-1234',
                'CardHolderPan' => '1234ABsab',
                'FinalAmount' => '1000',
            ]);
        }

        public function failed(): Collection
        {
            return collect([
                'RefId' => 'AF82041a2Bf6989c7fF9',
                'ResCode' => 11,
                'SaleOrderId' => 123456789012345,
            ]);
        }
    };
}

function gatewayPayload(): array
{
    return [
        'orderId' => '123456789012345',
        'amount' => 1_000,
        'refId' => 'AF82041a2Bf6989c7fF9',
    ];
}
