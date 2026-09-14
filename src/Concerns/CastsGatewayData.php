<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Concerns;

use AliYavari\IranPayment\Enums\InternalErrorCode;
use AliYavari\IranPayment\Exceptions\InvalidGatewayDataException;
use Illuminate\Support\Arr;

/**
 * @internal
 *
 * Casts values received from the gateway (API responses and callbacks).
 *
 * - Values that decide the payment status throw an exception when invalid.
 * - Values that only describe an error fall back to an internal error code or message.
 *
 * @phpstan-require-implements \AliYavari\IranPayment\Contracts\Payment
 */
trait CastsGatewayData
{
    /**
     * Message describing the last invalid error code received from the gateway.
     */
    private ?string $invalidErrorCodeMessage = null;

    /**
     * Get a field from the gateway data as an integer, or throw an exception if it is not numeric.
     *
     * @param  array<string,mixed>|string  $body
     * @param  array<string,mixed>|string|null  $rawBody  The raw body for the exception, when `$body` is built from it.
     *
     * @throws InvalidGatewayDataException
     */
    protected function asInt(array|string $body, string $field, array|string|null $rawBody = null): int
    {
        $value = $this->getFieldValue($body, $field);

        if (! is_numeric($value)) {
            throw InvalidGatewayDataException::make($this->getGateway(), $field, 'int', $value, $rawBody ?? $body);
        }

        return (int) $value;
    }

    /**
     * Get a field from the gateway data as a string, or throw an exception if it is not a non-empty string, integer or float.
     *
     * @param  array<string,mixed>|string  $body
     * @param  array<string,mixed>|string|null  $rawBody  The raw body for the exception, when `$body` is built from it.
     *
     * @throws InvalidGatewayDataException
     */
    protected function asString(array|string $body, string $field, array|string|null $rawBody = null): string
    {
        $value = $this->getFieldValue($body, $field);

        if (! is_scalar($value) || is_bool($value) || blank($value)) {
            throw InvalidGatewayDataException::make($this->getGateway(), $field, 'string', $value, $rawBody ?? $body);
        }

        return (string) $value;
    }

    /**
     * Get a field from the gateway data as a boolean, or throw an exception if it is not a boolean.
     *
     * @param  array<string,mixed>|string  $body
     *
     * @throws InvalidGatewayDataException
     */
    protected function asBool(array|string $body, string $field): bool
    {
        $value = $this->getFieldValue($body, $field);

        if (! is_bool($value)) {
            throw InvalidGatewayDataException::make($this->getGateway(), $field, 'bool', $value, $body);
        }

        return $value;
    }

    /**
     * Get a field from the gateway data as an error code, or fall back to the internal error code if it is not numeric.
     *
     * @param  array<string,mixed>|string  $body
     */
    protected function asErrorCode(array|string $body, string $field): int
    {
        $value = $this->getFieldValue($body, $field);

        if (is_numeric($value)) {
            return (int) $value;
        }

        $this->invalidErrorCodeMessage = InvalidGatewayDataException::message($this->getGateway(), $field, 'int', $value);

        return InternalErrorCode::InvalidErrorCode->value;
    }

    /**
     * Get a field from the gateway data as an error message, or fall back to a message describing the invalid value.
     *
     * @param  array<string,mixed>|string  $body
     */
    protected function asErrorMessage(array|string $body, string $field): string
    {
        $value = $this->getFieldValue($body, $field);

        if (is_scalar($value) && ! is_bool($value) && ! blank($value)) {
            return (string) $value;
        }

        return InvalidGatewayDataException::message($this->getGateway(), $field, 'string', $value);
    }

    /**
     * Get the message describing the last invalid error code received from the gateway,
     * or `null` if the last error code was valid.
     */
    protected function getInvalidErrorCodeMessage(): ?string
    {
        return $this->invalidErrorCodeMessage;
    }

    /**
     * @param  array<string,mixed>|string  $body
     */
    private function getFieldValue(array|string $body, string $field): mixed
    {
        return Arr::get($body, $field);
    }
}
