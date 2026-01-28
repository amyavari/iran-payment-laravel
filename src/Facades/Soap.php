<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Facades;

use AliYavari\IranPayment\Client\Soap as SoapClientWrapper;
use AliYavari\IranPayment\Tests\Fakes\Soap as FakeSoap;
use Illuminate\Support\Facades\Facade;

/**
 * @internal
 *
 * @method static SoapClientWrapper to(string $wsdl) Initializes the SoapClient with the given WSDL URL
 * @method static void assertWsdl(string $wsdl) Assert the given WSDL URL is called.
 * @method static void assertNothingIsCalled() Assert nothing is called.
 * @method static void assertMethodCalled(string $method) Assert the given method is called.
 * @method static mixed getArguments(?int $index = null) Get the arguments passed to the `call()` method.
 *
 * @see SoapClientWrapper
 * @see FakeSoap
 */
final class Soap extends Facade
{
    public static function fake(mixed $response): void
    {
        self::swap(new FakeSoap($response));
    }

    /**
     * Indicate that an exception should be thrown if any request is not faked
     */
    public static function preventStrayRequests(bool $prevent = true): SoapClientWrapper
    {
        return tap(self::getFacadeRoot(), fn (SoapClientWrapper $soapWrapper) => self::swap($soapWrapper->preventStrayRequests($prevent)));
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return SoapClientWrapper::class;
    }
}
