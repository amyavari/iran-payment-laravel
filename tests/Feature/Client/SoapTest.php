<?php

declare(strict_types=1);

use AliYavari\IranPayment\Exceptions\StrayRequestException;
use AliYavari\IranPayment\Facades\Soap;

it('calls a WSDL service correctly', function (): void {
    Soap::preventStrayRequests(prevent: false);

    $response = Soap::to('http://www.dataaccess.com/webservicesserver/numberconversion.wso?WSDL')
        ->call('NumberToWords', ['ubiNum' => 100]);

    expect($response)
        ->toBeInstanceOf(stdClass::class)
        ->NumberToWordsResult->toBe('one hundred ');
})->skipLocally(); // This test calls real external service. To run it, make sure the php-soap extension is installed.

it('prevents real requests', function (): void {
    Soap::preventStrayRequests(prevent: true);

    Soap::to('http://wsdl');
})->throws(StrayRequestException::class, 'Attempted request to "http://wsdl" without a matching fake.');
