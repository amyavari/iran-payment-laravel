<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Fixtures;

use AliYavari\IranPayment\Abstracts\Driver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use Exception;
use Pest\Support\Arr;

/**
 * @internal
 *
 * Test class used for testing the abstract `Driver` class methods.
 */
final class TestDriver extends Driver
{
    public array $payload;

    public function __construct(
        private readonly bool $isSuccessful = true,
        private readonly string $driverCallbackUrl = '',
        private readonly int $errorCode = 0,
        private readonly string $errorMessage = '',
    ) {}

    /**
     * Test-only helper method.
     *
     * Allows assertions that child class methods are invoked correctly.
     */
    public function payload(?string $key = null): mixed
    {
        if (is_null($key)) {
            return $this->payload;
        }

        return $this->payload[$key];
    }

    /**
     * Test-only helper method.
     *
     * Exposes the protected generateUniqueTimeBaseNumber() method for testing.
     */
    public function callGenerateUniqueTimeBaseNumber(): string
    {
        return $this->generateUniqueTimeBaseNumber();
    }

    public function getTransactionId(): string
    {
        return '123456';
    }

    public function getGatewayPayload(): array
    {
        return ['payload' => 'value'];
    }

    public function getPaymentRedirectData(): ?PaymentRedirectDto
    {
        throw new Exception('Not implemented');
    }

    public function getCardNumber(): ?string
    {
        throw new Exception('Not implemented');
    }

    public function getRefNumber(): ?string
    {
        throw new Exception('Not implemented');
    }

    protected function newFromCallback(array $callbackData): static
    {
        return $this;
    }

    protected function getGatewayRawResponse(): mixed
    {
        return 'raw response';
    }

    protected function isSuccessful(): bool
    {
        return $this->isSuccessful;
    }

    protected function driverCallbackUrl(): string
    {
        return $this->driverCallbackUrl;
    }

    protected function createPayment(string $callbackUrl, int $amount, ?string $description = null, string|int|null $phone = null): void
    {
        $this->payload = [
            'method' => 'create',
            'amount' => $amount,
            'callback_url' => $callbackUrl,
            'description' => $description,
            'phone' => $phone,
        ];
    }

    protected function getGatewayStatusCode(): string
    {
        return (string) $this->errorCode;
    }

    protected function getGatewayStatusMessage(): string
    {
        return $this->errorMessage;
    }

    protected function verifyPayment(array $payload): void
    {
        if (Arr::get($payload, 'throw') === true) {
            throw new InvalidCallbackDataException(Arr::get($payload, 'error_message'));
        }

        $this->payload = [
            'method' => 'verify',
            'gateway_payload' => $payload,
        ];
    }
}
