<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Facades;

use AliYavari\IranPayment\Client\Soap as SoapClientWrapper;
use Illuminate\Support\Facades\Facade;

/**
 * @internal
 *
 * @method static SoapClientWrapper to(string $wsdl) Initializes the SoapClient with the given WSDL URL
 *
 * @see SoapClientWrapper
 */
final class Soap extends Facade
{
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
