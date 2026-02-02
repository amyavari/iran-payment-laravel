<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Fakes;

use AliYavari\IranPayment\Contracts\Soap as SoapInterface;
use PHPUnit\Framework\Assert;

/**
 * @internal
 *
 * Fake class to assert SOAP calls.
 */
final class Soap implements SoapInterface
{
    private ?string $wsdl = null;

    private string $method;

    private array $args;

    public function __construct(
        private readonly mixed $response
    ) {}

    /**
     * {@inheritdoc}
     */
    public function to(string $wsdl): static
    {
        $this->wsdl = $wsdl;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function call(string $method, array ...$args): mixed
    {
        $this->method = $method;
        $this->args = $args;

        return $this->response;
    }

    /**
     * Assert nothing is called
     */
    public function assertNothingIsCalled(): void
    {
        Assert::assertNull($this->wsdl);
    }

    /**
     * Assert the given WSDL URL is called.
     */
    public function assertWsdl(string $wsdl): void
    {
        Assert::assertSame($wsdl, $this->wsdl);
    }

    /**
     * Assert the given method is called.
     */
    public function assertMethodCalled(string $method): void
    {
        Assert::assertSame($method, $this->method);
    }

    /**
     * Get the arguments passed to the `call()` method.
     */
    public function getArguments(?int $index = null): mixed
    {
        return is_null($index) ? $this->args : $this->args[$index];
    }
}
