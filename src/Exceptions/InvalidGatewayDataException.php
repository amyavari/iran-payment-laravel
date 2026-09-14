<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Exceptions;

use RuntimeException;

/**
 * @internal
 */
final class InvalidGatewayDataException extends RuntimeException
{
    /**
     * @param  array<string,mixed>|string  $body
     */
    private function __construct(
        string $message,
        private readonly array|string $body
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string,mixed>|string  $body
     */
    public static function make(string $gateway, string $field, string $expectedType, mixed $givenValue, array|string $body): self
    {
        return new self(self::message($gateway, $field, $expectedType, $givenValue), $body);
    }

    public static function message(string $gateway, string $field, string $expectedType, mixed $givenValue): string
    {
        return sprintf('Expected "%s" to be of type "%s" for the %s gateway, "%s" given.', $field, $expectedType, $gateway, self::stringify($givenValue));
    }

    /**
     * Get the exception's context information.
     *
     * @return array{body: array<string,mixed>|string}
     */
    public function context(): array
    {
        return ['body' => $this->body];
    }

    private static function stringify(mixed $value): string
    {
        return is_string($value) ? $value : (string) json_encode($value);
    }
}
