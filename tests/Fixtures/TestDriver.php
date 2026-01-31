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
    private array $receivedParameters;

    private array $calledMethods = [];

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
     * Returns parameters received by the last invoked child method.
     */
    public function receivedParameters(?string $key = null): mixed
    {
        if (is_null($key)) {
            return $this->receivedParameters;
        }

        return $this->receivedParameters[$key];
    }

    /**
     * Test-only helper method.
     *
     * Returns the list of child class methods that were invoked.
     */
    public function calledMethods(): mixed
    {
        return $this->calledMethods;
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
        $this->throwExceptionIfNeeded();

        $this->storeMethodCall('create', [
            'callbackUrl' => $callbackUrl,
            'amount' => $amount,
            'description' => $description,
            'phone' => $phone,
        ]);
    }

    protected function verifyPayment(array $storedPayload): void
    {
        $this->throwExceptionIfNeeded();

        $this->storeMethodCall('verify', [
            'storedPayload' => $storedPayload,
        ]);
    }

    protected function settlePayment(): void
    {
        $this->throwExceptionIfNeeded();

        $this->storeMethodCall('settle', []);
    }

    protected function reversePayment(): void
    {
        $this->throwExceptionIfNeeded();

        $this->storeMethodCall('reverse', []);
    }

    protected function getGatewayStatusCode(): string
    {
        return (string) $this->errorCode;
    }

    protected function getGatewayStatusMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * Test-only helper method.
     */
    private function throwExceptionIfNeeded(): void
    {
        if (isset($this->exception)) {
            throw $this->exception;
        }
    }

    /**
     * Test-only helper method.
     */
    private function storeMethodCall(string $method, array $parameters): void
    {
        $this->calledMethods[] = $method;
        $this->receivedParameters = $parameters;
    }
}
