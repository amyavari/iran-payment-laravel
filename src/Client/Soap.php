<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Client;

use AliYavari\IranPayment\Exceptions\StrayRequestException;
use SoapClient;

/**
 * @internal
 *
 * Wrapper around SoapClient to simplify faking and testing.
 */
final class Soap
{
    private SoapClient $soapClient;

    public function __construct(
        private readonly bool $preventStrayRequests = false,
    ) {}

    /**
     * Indicate that an exception should be thrown if any request is not faked.
     */
    public function preventStrayRequests(bool $prevent): self
    {
        return new self($prevent);
    }

    /**
     * Initializes the SoapClient with the given WSDL URL.
     *
     * @throws StrayRequestException
     */
    public function to(string $wsdl): static
    {
        $this->throwIfStrayRequestsArePrevented($wsdl);

        $this->soapClient = new SoapClient($wsdl, [
            'trace' => true,
            'exceptions' => true,
            'encoding' => 'UTF-8',
        ]);

        return $this;
    }

    /**
     * Calls a SOAP method with the given data and returns the response.
     *
     * @param  array<mixed>  $args
     */
    public function call(string $method, array ...$args): mixed
    {
        return $this->soapClient->__soapCall($method, $args);
    }

    /**
     * @throws StrayRequestException
     */
    private function throwIfStrayRequestsArePrevented(string $wsdl): void
    {
        if ($this->preventStrayRequests) {
            throw new StrayRequestException(
                sprintf('Attempted request to "%s" without a matching fake.', $wsdl)
            );
        }
    }
}
