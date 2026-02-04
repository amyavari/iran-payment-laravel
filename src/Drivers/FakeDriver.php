<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Drivers;

use AliYavari\IranPayment\Abstracts\Driver;
use AliYavari\IranPayment\Concerns\HasUniqueNumber;
use AliYavari\IranPayment\Dtos\DriverBehaviorDto;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\DriverBehaviorNotDefinedException;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use SoapFault;

/**
 * @internal
 *
 * Fake driver for testing purposes.
 */
final class FakeDriver extends Driver
{
    use HasUniqueNumber;

    /**
     * Create operation key.
     */
    private const string CREATE = 'create';

    /**
     * Verify operation key.
     */
    private const string VERIFY = 'verify';

    /**
     * Settle operation key.
     */
    private const string SETTLE = 'settle';

    /**
     * Reverse operation key.
     */
    private const string REVERSE = 'reverse';

    /**
     * Configured fake behaviors per operation
     *
     * @var array<string,DriverBehaviorDto>
     */
    private array $behaviors = [];

    /**
     * Success flag from applied behavior.
     */
    private bool $successful;

    /**
     * Error code set by applied behavior.
     */
    private string $errorCode;

    /**
     * Error message set by applied behavior.
     */
    private string $errorMessage;

    /**
     * Raw response set by applied behavior.
     */
    private mixed $rawResponse;

    /**
     * Gateway payload set by applied behavior.
     *
     * @var array<string,mixed>
     */
    private array $gatewayPayload;

    /**
     * Redirect data set by applied behavior.
     */
    private PaymentRedirectDto $redirectData;

    /**
     * Transaction ID set by applied behavior.
     */
    private string $transactionId;

    /**
     * Reference number set by applied behavior.
     */
    private string $refNumber;

    /**
     * Card number set by applied behavior.
     */
    private string $cardNumber;

    /**
     * Invalid callback message set by applied behavior.
     */
    private ?string $invalidCallbackMessage = null;

    public function __construct(string $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * Set successful behavior for the create API call.
     *
     * @param  string|array<string,mixed>  $rawResponse
     * @param  array<string,mixed>  $gatewayPayload
     */
    public function successfulCreate(string|array $rawResponse = 'Creation raw response', array $gatewayPayload = ['payload' => 'test value'], ?PaymentRedirectDto $redirectData = null): self
    {
        $behavior = new DriverBehaviorDto(successful: true, rawResponse: $rawResponse);

        $this->setBehaviorFor(self::CREATE, $behavior);

        $this->gatewayPayload = $gatewayPayload;

        $this->redirectData = $redirectData ?? $this->getDefaultRedirectData();

        $this->transactionId = $this->generateUniqueTimeBaseNumber();

        return $this;
    }

    /**
     * Set failed behavior for the create API call.
     *
     * @param  string|array<string,mixed>  $rawResponse
     */
    public function failedCreate(string|array $rawResponse = 'Creation raw response', string|int $errorCode = 0, string $errorMessage = 'Creation failed'): self
    {
        $behavior = new DriverBehaviorDto(successful: false, errorCode: (string) $errorCode, errorMessage: $errorMessage, rawResponse: $rawResponse);

        $this->setBehaviorFor(self::CREATE, $behavior);

        return $this;
    }

    /**
     * Set connection failure exception for the create API call.
     */
    public function failedConnectionCreate(string $message = 'Creation connection failed'): self
    {
        $behavior = new DriverBehaviorDto(exceptionMessage: $message);

        $this->setBehaviorFor(self::CREATE, $behavior);

        return $this;
    }

    /**
     * Set successful behavior for the verify API call.
     *
     * @param  string|array<string,mixed>  $rawResponse
     */
    public function successfulVerify(string|array $rawResponse = 'Verification raw response', string $refNumber = '123456789', string $cardNumber = '1234-****-****-1234'): self
    {
        $behavior = new DriverBehaviorDto(successful: true, rawResponse: $rawResponse);

        $this->setBehaviorFor(self::VERIFY, $behavior);

        $this->cardNumber = $cardNumber;

        $this->refNumber = $refNumber;

        return $this;
    }

    /**
     * Set failed behavior for the verify API call.
     *
     * @param  string|array<string,mixed>  $rawResponse
     */
    public function failedVerify(string|array $rawResponse = 'Verification raw response', string|int $errorCode = 0, string $errorMessage = 'Verification failed'): self
    {
        $behavior = new DriverBehaviorDto(successful: false, errorCode: (string) $errorCode, errorMessage: $errorMessage, rawResponse: $rawResponse);

        $this->setBehaviorFor(self::VERIFY, $behavior);

        return $this;
    }

    /**
     * Set connection failure exception for the verify API call.
     */
    public function failedConnectionVerify(string $message = 'Verification connection failed'): self
    {
        $behavior = new DriverBehaviorDto(exceptionMessage: $message);

        $this->setBehaviorFor(self::VERIFY, $behavior);

        return $this;
    }

    /**
     * Set invalid callback data exception for the verify API call.
     */
    public function invalidCallback(string $message = 'Invalid callback data'): self
    {
        $this->invalidCallbackMessage = $message;

        return $this;
    }

    /**
     * Set successful behavior for the settle API call.
     *
     * @param  string|array<string,mixed>  $rawResponse
     */
    public function successfulSettle(string|array $rawResponse = 'Settlement raw response'): self
    {
        $behavior = new DriverBehaviorDto(successful: true, rawResponse: $rawResponse);

        $this->setBehaviorFor(self::SETTLE, $behavior);

        return $this;
    }

    /**
     * Set failed behavior for the settle API call.
     *
     * @param  string|array<string,mixed>  $rawResponse
     */
    public function failedSettle(string|array $rawResponse = 'Settlement raw response', string|int $errorCode = 0, string $errorMessage = 'Settlement failed'): self
    {
        $behavior = new DriverBehaviorDto(successful: false, errorCode: (string) $errorCode, errorMessage: $errorMessage, rawResponse: $rawResponse);

        $this->setBehaviorFor(self::SETTLE, $behavior);

        return $this;
    }

    /**
     * Set connection failure exception for the settle API call.
     */
    public function failedConnectionSettle(string $message = 'Settlement connection failed'): self
    {
        $behavior = new DriverBehaviorDto(exceptionMessage: $message);

        $this->setBehaviorFor(self::SETTLE, $behavior);

        return $this;
    }

    /**
     * Set successful behavior for the reverse API call.
     *
     * @param  string|array<string,mixed>  $rawResponse
     */
    public function successfulReverse(string|array $rawResponse = 'Reversal raw response'): self
    {
        $behavior = new DriverBehaviorDto(successful: true, rawResponse: $rawResponse);

        $this->setBehaviorFor(self::REVERSE, $behavior);

        return $this;
    }

    /**
     * Set failed behavior for the reverse API call.
     *
     * @param  string|array<string,mixed>  $rawResponse
     */
    public function failedReverse(string|array $rawResponse = 'Reversal raw response', string|int $errorCode = 0, string $errorMessage = 'Reversal failed'): self
    {
        $behavior = new DriverBehaviorDto(successful: false, errorCode: (string) $errorCode, errorMessage: $errorMessage, rawResponse: $rawResponse);

        $this->setBehaviorFor(self::REVERSE, $behavior);

        return $this;
    }

    /**
     * Set connection failure exception for the reverse API call.
     */
    public function failedConnectionReverse(string $message = 'Reversal connection failed'): self
    {
        $behavior = new DriverBehaviorDto(exceptionMessage: $message);

        $this->setBehaviorFor(self::REVERSE, $behavior);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    /**
     * {@inheritdoc}
     */
    public function getGatewayPayload(): array
    {
        return $this->gatewayPayload;
    }

    /**
     * {@inheritdoc}
     */
    public function getRedirectData(): PaymentRedirectDto
    {
        return $this->redirectData;
    }

    /**
     * {@inheritdoc}
     */
    public function getRefNumber(): string
    {
        return $this->refNumber;
    }

    /**
     * {@inheritdoc}
     */
    public function getCardNumber(): string
    {
        return $this->cardNumber;
    }

    /**
     * {@inheritdoc}
     */
    protected function driverCallbackUrl(): string
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    protected function createPayment(string $callbackUrl, int $amount, ?string $description = null, string|int|null $phone = null): void
    {
        $this->applyBehaviorFor(self::CREATE);
    }

    /**
     * {@inheritdoc}
     */
    protected function getGatewayStatusCode(): string
    {
        return $this->errorCode;
    }

    /**
     * {@inheritdoc}
     */
    protected function getGatewayStatusMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * {@inheritdoc}
     */
    protected function isSuccessful(): bool
    {
        return $this->successful;
    }

    /**
     * {@inheritdoc}
     */
    protected function getGatewayRawResponse(): mixed
    {
        return $this->rawResponse;
    }

    /**
     * {@inheritdoc}
     */
    protected function verifyPayment(array $storedPayload): void
    {
        if ($this->invalidCallbackMessage) {
            throw new InvalidCallbackDataException($this->invalidCallbackMessage);
        }

        $this->applyBehaviorFor(self::VERIFY);
    }

    /**
     * {@inheritdoc}
     */
    protected function settlePayment(): void
    {
        $this->applyBehaviorFor(self::SETTLE);
    }

    /**
     * {@inheritdoc}
     */
    protected function reversePayment(): void
    {
        $this->applyBehaviorFor(self::REVERSE);
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareFromCallback(array $callbackPayload): static
    {
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareWithoutCallback(string $transactionId): static
    {
        $this->transactionId = $transactionId;

        return $this;
    }

    /**
     * Returns the default redirect data for a fake create request.
     */
    private function getDefaultRedirectData(): PaymentRedirectDto
    {
        return new PaymentRedirectDto(
            url     : 'https://gateway.test',
            method  : 'POST',
            payload : ['status' => 'successful'],
            headers : ['X-IranPayment-Fake' => 'true'],
        );
    }

    /**
     * Registers a fake behavior for the given method.
     */
    private function setBehaviorFor(string $method, DriverBehaviorDto $behavior): void
    {
        Arr::set($this->behaviors, $method, $behavior);
    }

    /**
     * Applies the configured fake behavior for the given method.
     */
    private function applyBehaviorFor(string $method): void
    {
        $this->ensureBehaviorIsDefined($method);

        /** @var DriverBehaviorDto $behavior */
        $behavior = Arr::get($this->behaviors, $method);

        if ($behavior->exceptionMessage) {
            $this->throwException($behavior->exceptionMessage);
        }

        $this->successful = $behavior->successful;
        $this->errorCode = $behavior->errorCode;
        $this->errorMessage = $behavior->errorMessage;
        $this->rawResponse = $behavior->rawResponse;
    }

    /**
     * Throws an exception when fake behavior does not exist for the given method.
     *
     * @throws DriverBehaviorNotDefinedException
     */
    private function ensureBehaviorIsDefined(string $method): void
    {
        if (! Arr::has($this->behaviors, $method)) {
            throw new DriverBehaviorNotDefinedException(
                sprintf('No behavior has been defined for the "%s" method on the fake driver "%s".', $method, $this->gateway)
            );
        }
    }

    /**
     * Throws a gateway-specific exception with the given message.
     */
    private function throwException(string $message): void
    {
        match ($this->gateway) {
            'behpardakht' => throw new SoapFault('0', $message),

            default => Http::failedConnection($message),
        };
    }
}
