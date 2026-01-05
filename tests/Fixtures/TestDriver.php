<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Fixtures;

use AliYavari\IranPayment\Abstracts\Driver;

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
     * To assert and test this class
     */
    public function payload(?string $key = null): mixed
    {
        if (is_null($key)) {
            return $this->payload;
        }

        return $this->payload[$key];
    }

    public function getTransactionId(): string
    {
        return $this->generateUniqueTimeBaseNumber();
    }

    public function successful(): bool
    {
        return $this->isSuccessful;
    }

    public function getRawResponse(): mixed
    {
        return '';
    }

    protected function driverCallbackUrl(): string
    {
        return $this->driverCallbackUrl;
    }

    protected function createPayment(string $callbackUrl, int $amount, ?string $description = null, string|int|null $phone = null): void
    {
        $this->payload = [
            'method' => __METHOD__,
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
}
