<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Helpers;

use AliYavari\IranPayment\Contracts\Payment;

/**
 * Provide a contract and shared functionality for driver test helpers.
 */
abstract class AbstractHelper
{
    /**
     * Set the configuration values for the gateway.
     */
    abstract public static function setDriverConfigs(): void;

    /**
     * Get an instance of the payment gateway driver.
     */
    abstract public static function driver(): Payment;

    /**
     * Get a mock successful payment creation response.
     *
     * @return array<string, mixed>|string
     */
    abstract public static function successfulCreationResponse(): array|string;

    /**
     * Get a mock successful payment verification response.
     *
     * @return array<string, mixed>|string
     */
    abstract public static function successfulVerificationResponse(): array|string;

    /**
     * Get a mock successful payment reversal response.
     *
     * @return array<string, mixed>|string
     */
    abstract public static function successfulReversalResponse(): array|string;

    /**
     * Get a mock failed response.
     *
     * @param  'create'|'verify'|'reverse'|null  $method
     * @return array<string, mixed>|string
     */
    abstract public static function failedResponse(?string $method = null): array|string;

    /**
     * Get a mock callback collection representing a successful payment.
     *
     * @return array<string, mixed>
     */
    abstract public static function successfulCallback(): array;

    /**
     * Get a mock callback collection representing a failed payment.
     *
     * @return array<string, mixed>
     */
    abstract public static function failedCallback(): array;

    /**
     * Get a mock gateway payload stored in the database.
     *
     * @return array<string, mixed>
     */
    abstract public static function gatewayPayload(): array;

    /**
     * Initialize the driver instance using data from a successful callback.
     */
    final public static function driverFromSuccessfulCallback(): Payment
    {
        return static::driver()->fromCallback(static::successfulCallback());
    }

    /**
     * Get a verified payment instance by executing the verification flow.
     */
    final public static function verifiedPayment(): Payment
    {
        return self::driverFromSuccessfulCallback()->verify(static::gatewayPayload());
    }
}
