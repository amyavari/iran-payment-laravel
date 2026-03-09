<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Drivers;

use AliYavari\IranPayment\Abstracts\Driver;
use AliYavari\IranPayment\Concerns\HasUniqueNumber;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Pest\Support\Arr;

/**
 * @internal
 *
 * @see https://idpay.ir/web-service/v1.1/
 */
final class IdPayDriver extends Driver
{
    use HasUniqueNumber;

    /**
     * Base URL of the payment gateway.
     */
    private const string GATEWAY_BASE_URL = 'https://api.idpay.ir/v1.1';

    /**
     * Status code returned by the last API call.
     */
    private int $apiStatusCode;

    /**
     * Status message returned by the last API call.
     */
    private string $apiStatusMessage;

    /**
     * Determine whether te last API call was successful.
     */
    private bool $apiIsSuccessful;

    /**
     * Raw response from the last API call.
     *
     * @var array<string,mixed>|string
     */
    private string|array $rawResponse;

    /**
     * Transaction ID
     */
    private ?string $transactionId = null;

    /**
     * Amount of the payment in Rial.
     */
    private string $amount;

    /**
     * Payment unique ID returned by the gateway, required for verification
     */
    private string $id;

    public function __construct(
        private readonly string $callbackUrl,
        private readonly string $apiKey,
    ) {}

    /**
     * {@inheritdoc}
     */
    protected function driverCallbackUrl(): string
    {
        return $this->callbackUrl;
    }

    /**
     * {@inheritdoc}
     */
    protected function createPayment(string $callbackUrl, int $amount, ?string $description = null, string|int|null $phone = null): void
    {
        $this->amount = (string) $amount;

        $data = collect([
            'order_id' => $this->generateOrderId(),
            'amount' => $this->amount,
            'callback_url' => $callbackUrl,
        ])
            ->when($description, fn (Collection $data) => $data->merge(['desc' => (string) $description]))
            ->when($phone, fn (Collection $data) => $data->merge(['phone' => $this->toDriverPhone($phone)]));

        $this->execute('payment', $data);

        if ($this->apiIsSuccessful) {
            $this->id = Arr::get($this->rawResponse, 'id');
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverStatusCode(): string
    {
        return (string) $this->apiStatusCode;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverStatusMessage(): string
    {
        return $this->apiStatusMessage;
    }

    /**
     * {@inheritdoc}
     */
    protected function isSuccessful(): bool
    {
        return $this->apiIsSuccessful;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRawResponse(): string|array
    {
        return $this->rawResponse;
    }

    /**
     * {@inheritdoc}
     */
    protected function verifyPayment(array $storedPayload): void
    {
        if ($this->isFailedPaymentBasedOnCallback()) {
            $this->setPaymentStatusBasedOnCallback();

            return;
        }

        $keyMapper = [
            'order_id' => 'order_id',
        ];

        $this->ensureCallbackDataMatchesPayload($storedPayload, $keyMapper);

        $data = [
            'id' => Arr::get($storedPayload, 'id'),
            'order_id' => $this->transactionId,
        ];

        $this->execute('payment/verify', $data);

        /**
         * Three layers of the verification:
         * 1. HTTP status code
         * 2. Gateway verification status
         * 3. Amount equality
         */
        if ($this->apiIsSuccessful) {
            $this->setVerificationStatus();
        }

        if ($this->apiIsSuccessful) {
            $this->validateVerifiedAmount($storedPayload);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function settlePayment(): void
    {
        $this->apiIsSuccessful = true;
        $this->rawResponse = 'No API is called. IPG only has auto settlement.';
    }

    /**
     * {@inheritdoc}
     */
    protected function reversePayment(): void
    {
        $this->apiIsSuccessful = false;

        $this->apiStatusCode = 1011;
        $this->apiStatusMessage = 'درگاه از بازگشت وجه پشتیبانی نمی کند';

        $this->rawResponse = 'No API is called. IPG does not support reversal.';
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareFromCallback(): void
    {
        $this->transactionId = $this->callbackPayload->get('order_id');
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareWithoutCallback(string $transactionId): void
    {
        $this->transactionId = $transactionId;

        $this->callbackPayload = collect([
            'status' => '100',
            'order_id' => $this->transactionId,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverTransactionId(): string
    {
        return $this->transactionId;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverPayload(): array
    {
        return [
            'order_id' => $this->transactionId,
            'id' => $this->id,
            'amount' => $this->amount,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRedirectData(): PaymentRedirectDto
    {
        $url = Arr::get($this->rawResponse, 'link');

        return new PaymentRedirectDto($url, 'GET', payload: []);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRefNumber(): string
    {
        return Arr::get($this->rawResponse, 'payment.track_id');
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverCardNumber(): string
    {
        return Arr::get($this->rawResponse, 'payment.card_no');
    }

    /**
     * {@inheritdoc}
     */
    protected function getRequiredCallbackKeys(): array
    {
        return ['status', 'order_id'];
    }

    /**
     * Generate unique order ID
     */
    private function generateOrderId(): string
    {
        $this->transactionId = $this->generateUniqueTimeBaseNumber();

        return $this->transactionId;
    }

    /**
     * Call the gateway's API with the given method and data.
     *
     * @param  array<string,mixed>|Arrayable<string,mixed>  $data
     */
    private function execute(string $method, array|Arrayable $data): void
    {
        $headers = [
            'X-API-KEY' => $this->apiKey,
            'X-SANDBOX' => (int) $this->useSandbox(),
        ];

        $url = self::GATEWAY_BASE_URL."/{$method}";

        $response = Http::withHeaders($headers)->post($url, $data)->throwIfServerError();

        $this->parseResponse($response);
    }

    /**
     * Parse the API response.
     */
    private function parseResponse(Response $response): void
    {
        $this->rawResponse = $response->json();

        $this->apiIsSuccessful = $response->successful();

        if (! $this->apiIsSuccessful) {
            $this->apiStatusCode = Arr::get($this->rawResponse, 'error_code');
            $this->apiStatusMessage = Arr::get($this->rawResponse, 'error_message');
        }
    }

    /**
     * Convert the phone number to the format expected by the gateway.
     */
    private function toDriverPhone(string|int $phone): string
    {
        return (string) Str::of((string) $phone)
            ->chopStart('+')
            ->chopStart('98')
            ->replaceStart('9', '09');
    }

    /**
     * Determine whether the payment failed based on the callback.
     */
    private function isFailedPaymentBasedOnCallback(): bool
    {
        return ((string) $this->callbackPayload->get('status')) !== '100';
    }

    /**
     * Set payment status by callback.
     */
    private function setPaymentStatusBasedOnCallback(): void
    {
        $this->apiIsSuccessful = false;

        $this->apiStatusCode = (int) $this->callbackPayload->get('status');
        $this->apiStatusMessage = $this->getCallbackStatusMessage();

        $this->rawResponse = $this->callbackPayload->all();
    }

    private function getCallbackStatusMessage(): string
    {
        return match ($this->apiStatusCode) {
            1 => 'پرداخت انجام نشده است',
            2 => 'پرداخت ناموفق بوده است',
            3 => 'خطا رخ داده است',
            4 => 'بلوکه شده',
            5 => 'برگشت به پرداخت کننده',
            6 => 'برگشت خورده سیستمی',
            7 => 'انصراف از پرداخت',
            8 => 'به درگاه پرداخت منتقل شد',
            10 => 'در انتظار تایید پرداخت',
            100 => 'پرداخت تایید شده است',
            101 => 'پرداخت قبلا تایید شده است',
            200 => 'به دریافت کننده واریز شد',

            default => 'وضعیت نامشخص',
        };
    }

    /**
     * Set verification status based on verification API response.
     */
    private function setVerificationStatus(): void
    {
        $this->apiStatusCode = (int) Arr::get($this->rawResponse, 'status');
        $this->apiStatusMessage = $this->getCallbackStatusMessage();

        $this->apiIsSuccessful = $this->apiStatusCode >= 100;
    }

    /**
     * Validate if the paid amount matches the creation amount.
     *
     * @param  array<string,mixed>  $storedPayload
     */
    private function validateVerifiedAmount(array $storedPayload): void
    {
        $this->apiIsSuccessful = Arr::get($storedPayload, 'amount') === Arr::get($this->rawResponse, 'amount');

        if (! $this->apiIsSuccessful) {
            $this->apiStatusCode = 1010;
            $this->apiStatusMessage = 'مبلغ پرداخت شده نامعتبر است';
        }
    }
}
