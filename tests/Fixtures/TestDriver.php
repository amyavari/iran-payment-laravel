<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Fixtures;

use AliYavari\IranPayment\Abstracts\Driver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use Exception;
use Illuminate\Support\Arr;
use LogicException;

/**
 * @internal
 *
 * Test class used for testing the abstract `Driver` class methods.
 */
final class TestDriver extends Driver
{
    private array $receivedParameters;

    private array $calledMethods = [];

    private array $isSuccessful = [];

    private string $driverCallbackUrl = '';

    private int $errorCode = 0;

    private string $errorMessage = '';

    private Exception $exception;

    private bool $callbackCalled = false;

    /**
     * Test-only helper method
     *
     * Driver should return a successful response for the given method call.
     */
    public function asSuccessful(string $method = 'all'): self
    {
        Arr::set($this->isSuccessful, $method, true);

        return $this;
    }

    /**
     * Test-only helper method
     *
     * Driver should return a failed response for the given method call.
     */
    public function asFailed(string $method = 'all'): self
    {
        Arr::set($this->isSuccessful, $method, false);

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
     * Calls fromCallback method.
     */
    public function callCallback(): self
    {
        $this->fromCallback([]);

        return $this;
    }

    /**
     * Test-only helper method
     *
     * Calls the `verify` method after executing all required steps.
     */
    public function performVerification(): self
    {
        $this->callCallback()->verify([]);

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

        $this->asSuccessful('create')->store($payable)->callCreate();

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

    protected function getDriverRefNumber(): string
    {
        $this->throwExceptionIfFailed();

        return '123456';
    }

    protected function getDriverCardNumber(): string
    {
        $this->throwExceptionIfFailed();

        return '1234-***-4567';
    }

    protected function getDriverTransactionId(): string
    {
        if (! $this->callbackCalled) {
            $this->throwExceptionIfFailed();
        }

        return '123456';
    }

    protected function getDriverRedirectData(): PaymentRedirectDto
    {
        $this->throwExceptionIfFailed();

        return new PaymentRedirectDto('url', 'method', ['payload'], ['headers']);
    }

    protected function getDriverPayload(): array
    {
        $this->throwExceptionIfFailed();

        return ['payload' => 'value'];
    }

    protected function prepareWithoutCallback(string $transactionId): static
    {
        $this->markCallbackAsCalled();

        return $this;
    }

    protected function prepareFromCallback(array $callbackData): static
    {
        $this->markCallbackAsCalled();

        return $this;
    }

    protected function getDriverRawResponse(): string
    {
        return Arr::last($this->calledMethods).' raw response';
    }

    protected function isSuccessful(): bool
    {
        $default = Arr::get($this->isSuccessful, 'all', true);

        return Arr::get($this->isSuccessful, Arr::last($this->calledMethods), $default);
    }

    protected function driverCallbackUrl(): string
    {
        return $this->driverCallbackUrl;
    }

    protected function createPayment(string $callbackUrl, int $amount, ?string $description = null, string|int|null $phone = null): void
    {
        $this->throwExceptionIfConfigured();

        $this->storeMethodCall('create', [
            'callbackUrl' => $callbackUrl,
            'amount' => $amount,
            'description' => $description,
            'phone' => $phone,
        ]);
    }

    protected function verifyPayment(array $storedPayload): void
    {
        $this->throwExceptionIfConfigured();

        $this->storeMethodCall('verify', [
            'storedPayload' => $storedPayload,
        ]);
    }

    protected function settlePayment(): void
    {
        $this->throwExceptionIfConfigured();

        $this->storeMethodCall('settle', []);
    }

    protected function reversePayment(): void
    {
        $this->throwExceptionIfConfigured();

        $this->storeMethodCall('reverse', []);
    }

    protected function getDriverStatusCode(): string
    {
        return (string) $this->errorCode;
    }

    protected function getDriverStatusMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * Test-only helper method.
     */
    private function throwExceptionIfConfigured(): void
    {
        if (isset($this->exception)) {
            throw $this->exception;
        }
    }

    /**
     * Test-only helper method.
     *
     * Ensures the driver method is not called on a failed response.
     */
    private function throwExceptionIfFailed(): void
    {
        if (! $this->isSuccessful) {
            throw new LogicException('TestDriver: This method should not be called.');
        }
    }

    /**
     * Test-only helper method.
     */
    private function markCallbackAsCalled(): void
    {
        $this->callbackCalled = true;
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
