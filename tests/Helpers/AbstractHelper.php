<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Helpers;

use AliYavari\IranPayment\Contracts\Payment;
use AliYavari\IranPayment\Enums\ApiMethod;
use LogicException;

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
     * Call the gateway API for the given method and return the payment instance.
     *
     * Note: Without a payment, this method follows the happy path. For other paths,
     * pass your own payment instance.
     */
    final public static function callGatewayFor(ApiMethod $call, ?Payment $payment = null): Payment
    {
        $payment ??= self::paymentReadyFor($call);

        return match ($call) {
            ApiMethod::Create => $payment->create(1_000),
            ApiMethod::Verify => $payment->verify(static::gatewayPayload()),
            ApiMethod::Reverse => $payment->reverse(),

            default => throw new LogicException(
                sprintf('The method "%s" is not a valid API call.', $call->value)
            ),
        };
    }

    /**
     * Get a payment instance that is ready for the given API call.
     *
     * It runs the calls that the given one requires first, so the driver's
     * call order is satisfied. It always follows the happy path.
     */
    final public static function paymentReadyFor(ApiMethod $call): Payment
    {
        return match ($call) {
            ApiMethod::Create => static::driver(),
            ApiMethod::Verify => static::driver()->fromCallback(static::successfulCallback()),
            ApiMethod::Reverse => static::driver()->fromCallback(static::successfulCallback())->verify(static::gatewayPayload()),

            default => static::driver(),
        };
    }

    /**
     * Get the successful fixture response for the given API call.
     *
     * @return array<string, mixed>|string
     */
    final public static function successfulResponseFor(ApiMethod $call): array|string
    {
        return match ($call) {
            ApiMethod::Create => static::successfulCreationResponse(),
            ApiMethod::Verify => static::successfulVerificationResponse(),
            ApiMethod::Reverse => static::successfulReversalResponse(),

            default => throw new LogicException(
                sprintf('The method "%s" is not a valid API call.', $call->value)
            ),
        };
    }
}
