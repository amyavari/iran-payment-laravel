<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Contracts;

/**
 * @internal
 */
interface Soap
{
    /**
     * Initializes the SoapClient with the given WSDL URL.
     *
     * @throws \AliYavari\IranPayment\Exceptions\StrayRequestException
     */
    public function to(string $wsdl): static;

    /**
     * Calls a SOAP method with the given data and returns the response.
     *
     * @param  array<mixed>  $args
     */
    public function call(string $method, array ...$args): mixed;
}
