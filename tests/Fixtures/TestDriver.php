<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Fixtures;

use AliYavari\IranPayment\Abstracts\Driver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use Exception;

/**
 * @internal
 *
 * Test class used for testing the abstract `Driver` class methods.
 */
final class TestDriver extends Driver
{
    public array $receivedData = [];

    private bool $isSuccessful = true;

    private string $driverCallbackUrl = '';

    private int $errorCode = 0;

    private string $errorMessage = '';

    private Exception $exception;

    /**
     * Test-only helper method
     *
     * Driver should return successful response
     */
    public function asSuccessful(): self
    {
        $this->isSuccessful = true;

        return $this;
    }

    /**
     * Test-only helper method
     *
     * Driver should return failed response
     */
    public function asFailed(): self
    {
        $this->isSuccessful = false;
        $this->errorCode = 12;
        $this->errorMessage = 'خطایی رخ داد.';

        return $this;
    }

    /**
     * Test-only helper method
     *
     * Set driver's default callback URL
     */
    public function withCallbackUrl(string $callback): self
    {
        $this->driverCallbackUrl = $callback;

        return $this;
    }

    /**
     * Test-only helper method
     *
     * Driver should throw the exception
     */
    public function throwing(Exception $exception): self
    {
        $this->exception = $exception;

        return $this;
    }

    /**
     * Test-only helper method
     *
     * Driver should be treated as one API is called.
     */
    public function apiCalled(): self
    {
        $this->create(10); // any call just to mark as called

        return $this;
    }

    /**
     * Test-only helper method
     *
     * Calls create method.
     */
    public function callCreate(): self
    {
        $this->create(10);

        return $this;
    }

    /**
     * Test-only helper method
     *
     * Stores a test payment record.
     */
    public function storeTestPayment(): self
    {
        $payable = TestModel::query()->create();

        $this->asSuccessful()->store($payable)->callCreate();

        return $this;
    }

    /**
     * Test-only helper method.
     *
     * Allows assertions that child class methods are invoked correctly.
     */
    public function receivedData(?string $key = null): mixed
    {
        if (is_null($key)) {
            return $this->receivedData;
        }

        return $this->receivedData[$key];
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
        if (isset($this->exception)) {
            throw $this->exception;
        }

        $this->receivedData = [
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

    protected function verifyPayment(array $storedPayload): void
    {
        if (isset($this->exception)) {
            throw $this->exception;
        }

        $this->receivedData = [
            'method' => 'verify',
            'passed_payload' => $storedPayload,
        ];
    }

    protected function settlePayment(): void
    {
        if (isset($this->exception)) {
            throw $this->exception;
        }

        $this->receivedData = [
            'method' => 'settle',
        ];
    }

    protected function reversePayment(): void
    {
        if (isset($this->exception)) {
            throw $this->exception;
        }

        $this->receivedData = [
            'method' => 'reverse',
        ];
    }
}
