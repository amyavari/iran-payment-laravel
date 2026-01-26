<?php

declare(strict_types=1);

use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Facades\Payment;
use AliYavari\IranPayment\Facades\Soap;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    $this->gateway = 'behpardakht';

    fakeSoap();
    setDriverConfigs($this->gateway);
});

it('generates and returns transaction ID', function (): void {
    $payment = Payment::gateway($this->gateway)->create(1_000);

    expect($payment)
        ->getTransactionId()->toBeString()->toBeNumeric()->toHaveLength(15);
});

it('returns `null` as transaction ID if payment is not created', function (): void {
    $payment = Payment::gateway($this->gateway);

    expect($payment)
        ->getTransactionId()->toBeNull();
});

it("creates a new payment with minimum required data and config's callback URL", function (): void {
    setTestNowIran('2025-12-10 18:30:10');

    $payment = Payment::gateway($this->gateway)->create(1_000);

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
        ->callBackUrl->toBe('http://callback.test')
        ->payerId->toBe(0)
        ->not->toHaveKeys(['mobileNo', 'cartItem']);
});

it('creates new payment with full data', function (): void {
    Payment::gateway($this->gateway)->create(1_000, 'Description', '989123456789');

    expect(Soap::getArguments(0))
        ->additionalData->toBe('Description')
        ->mobileNo->toBe('989123456789')
        ->cartItem->toBe('Description');
});

it("converts phone number to gateway accepted format if it's necessary", function (string|int $phone): void {
    Payment::gateway($this->gateway)->create(1_000, phone: $phone);

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

it('returns successful response', function (): void {
    fakeSoap(response: '0,AF82041a2Bf6989c7fF9'); // Sample successful API response

    $payment = Payment::gateway($this->gateway)->create(1_000);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe('0,AF82041a2Bf6989c7fF9');
});

it('returns failed response', function (): void {
    fakeSoap(response: '11'); // Sample failed API response

    $payment = Payment::gateway($this->gateway)->create(1_000);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toBe('کد 11- شماره کارت نامعتبر است')
        ->getRawResponse()->toBe('11');
});

it('returns gateway payload which is needed to verify payment', function (): void {
    fakeSoap(response: '0,AF82041a2Bf6989c7fF9');

    $payment = Payment::gateway($this->gateway)->create(1_000);

    expect($payment)
        ->getGatewayPayload()->toBe([
            'orderId' => $payment->getTransactionId(),
            'amount' => 1_000,
            'refId' => 'AF82041a2Bf6989c7fF9',
        ]);
});

it('returns `null` as gateway payload if payment creation failed', function (): void {
    fakeSoap(response: '12');

    $payment = Payment::gateway($this->gateway)->create(1_000);

    expect($payment)
        ->getGatewayPayload()->toBeNull();
});

it('returns gateway redirect data if payment creation was successful with full data', function (): void {
    fakeSoap(response: '0,AF82041a2Bf6989c7fF9');
    URL::useOrigin('http://myapp.com');

    $payment = Payment::gateway($this->gateway)->create(1_000, 'Description', '9123456789');

    expect($payment)
        ->getPaymentRedirectData()->scoped(fn ($paymentRedirectData) => $paymentRedirectData->toBeInstanceOf(PaymentRedirectDto::class)
        ->url->toBe('https://bpm.shaparak.ir/pgwchannel/startpay.mellat')
        ->method->toBe('POST')
        ->payload->toBe([
            'RefId' => 'AF82041a2Bf6989c7fF9',
            'MobileNo' => '989123456789',
            'CartItem' => 'Description',
        ])
        ->headers->toBe([
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Referer' => 'http://myapp.com',
        ])
        );
});

it('returns gateway redirect data if payment creation was successful minimum data', function (): void {
    fakeSoap(response: '0,AF82041a2Bf6989c7fF9');
    URL::useOrigin('http://myapp.com');

    $payment = Payment::gateway($this->gateway)->create(1_000);

    expect($payment)
        ->getPaymentRedirectData()->scoped(fn ($paymentRedirectData) => $paymentRedirectData->toBeInstanceOf(PaymentRedirectDto::class)
        ->url->toBe('https://bpm.shaparak.ir/pgwchannel/startpay.mellat')
        ->method->toBe('POST')
        ->payload->toBe([
            'RefId' => 'AF82041a2Bf6989c7fF9',
        ])
        ->headers->toBe([
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Referer' => 'http://myapp.com',
        ])
        );
});

it('returns `null` as gateway redirect data if payment creation failed', function (): void {
    fakeSoap(response: '12');

    $payment = Payment::gateway($this->gateway)->create(1_000, phone: '9136080724');

    expect($payment)
        ->getPaymentRedirectData()->toBeNull();
});

it('communicates with sandbox environment if user set it in the configuration', function (): void {
    Config::set('iran-payment.use_sandbox', true);

    fakeSoap(response: '0,AF82041a2Bf6989c7fF9');

    $payment = Payment::gateway($this->gateway)->create(1_000);

    Soap::assertWsdl('https://pgw.dev.bpmellat.ir/pgwchannel/services/pgw?wsdl');

    expect($payment)
        ->getPaymentRedirectData()->url->toBe('https://pgw.dev.bpmellat.ir/pgwchannel/startpay.mellat');
});

it('creates payment instance from callback', function (): void {
    $callbackData = [
        'RefId' => 'AF82041a2Bf6989c7fF9',
        'ResCode' => 0,
        'SaleOrderId' => 123456789012345,
    ];

    $payment = Payment::gateway($this->gateway)->fromCallback($callbackData);

    expect($payment)
        ->getGateway()->toBe($this->gateway)
        ->getTransactionId()->toBe('123456789012345');
});

it('returns payment details if it is a successful response callback', function (): void {
    $callbackData = [
        'RefId' => 'AF82041a2Bf6989c7fF9',
        'ResCode' => 0,
        'SaleOrderId' => 123456789012345,
        'SaleReferenceId' => 227926981246,
        'CardHolderInfo' => '1234-*-*-1234',
        'CardHolderPan' => '1234ABsab',
        'FinalAmount' => '1000',
    ];

    $payment = Payment::gateway($this->gateway)->fromCallback($callbackData);

    expect($payment)
        ->getRefNumber()->toBe('227926981246')
        ->getCardNumber()->toBe('1234-*-*-1234');
});

it('returns `null` as payment details if they are not provided in the callback data', function (): void {
    $callbackData = [
        'RefId' => 'AF82041a2Bf6989c7fF9',
        'ResCode' => 0,
        'SaleOrderId' => 123456789012345,
    ];

    $payment = Payment::gateway($this->gateway)->fromCallback($callbackData);

    expect($payment)
        ->getRefNumber()->toBeNull()
        ->getCardNumber()->toBeNull();
});

it('throws an exception if we try to create an instance from callback without necessary keys', function (string $key): void {
    $callbackData = Arr::except([
        'RefId' => 'AF82041a2Bf6989c7fF9',
        'ResCode' => 0,
        'SaleOrderId' => 123456789012345,
    ], $key);

    expect(fn () => Payment::gateway($this->gateway)->fromCallback($callbackData))
        ->toThrow(
            MissingCallbackDataException::class,
            sprintf('To create %s gateway instance from callback, "RefId, ResCode, SaleOrderId" are required. "%s" is missing.', $this->gateway, $key)
        );
})->with([
    'RefId',
    'ResCode',
    'SaleOrderId',
]);

// ------------
// Helpers
// ------------

function setDriverConfigs(string $gateway): void
{
    Config::set("iran-payment.gateways.{$gateway}.callback_url", 'http://callback.test');
    Config::set("iran-payment.gateways.{$gateway}.terminal_id", '1234');
    Config::set("iran-payment.gateways.{$gateway}.username", 'username');
    Config::set("iran-payment.gateways.{$gateway}.password", 'password');
}

function fakeSoap(string $response = ''): void
{
    Soap::fake($response);
}
