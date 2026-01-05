<?php

declare(strict_types=1);

use AliYavari\IranPayment\Facades\Payment;
use AliYavari\IranPayment\Facades\Soap;
use Illuminate\Support\Facades\Config;

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
