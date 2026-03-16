<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Drivers;

use AliYavari\IranPayment\Abstracts\Driver;
use AliYavari\IranPayment\Concerns\HasUniqueNumber;
use AliYavari\IranPayment\Concerns\NoCallbackDefaults;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\SandboxNotSupportedException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Pest\Support\Arr;

/**
 * @internal
 *
 * Based on version 3.3 (March 2024) of IPG documentation.
 */
final class SepDriver extends Driver
{
    use HasUniqueNumber, NoCallbackDefaults;

    /**
     * URL of the payment gateway to create a new payment.
     */
    private const string GATEWAY_CREATE_URL = 'https://sep.shaparak.ir/onlinepg/onlinepg';

    /**
     * Base URL of the payment gateway to verify and reverse the payment.
     */
    private const string GATEWAY_FOLLOW_UP_BASE_URL = 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg';

    /**
     * URL of the payment page where the user should be redirected.
     */
    private const string PAYMENT_REDIRECT_URL = 'https://sep.shaparak.ir/OnlinePG/SendToken';

    /**
     * Status code returned by the last API call.
     */
    private string $apiStatusCode;

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
    private int $amount;

    /**
     * Token used to redirect the user to the payment page.
     */
    private string $token;

    public function __construct(
        private readonly string $terminalId,
        private readonly string $callbackUrl,
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
        $this->amount = $amount;

        $data = collect([
            'Action' => 'token',
            'TerminalId' => $this->terminalId,
            'Amount' => $this->amount,
            'ResNum' => $this->generateResNum(),
            'RedirectUrl' => $callbackUrl,
        ])
            ->when($phone, fn (Collection $data) => $data->merge(['CellNumber' => $this->toDriverPhone($phone)]));

        $this->execute(self::GATEWAY_CREATE_URL, $data);

        $this->parseCreationResponse();
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverStatusCode(): string
    {
        return $this->apiStatusCode;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverStatusMessage(): string
    {
        if ($this->isNoCallback()) {
            return $this->noCallbackMessage($this->apiStatusCode);
        }

        return $this->apiStatusMessage;
    }

    /**
     * {@inheritdoc}
     */
    protected function isSuccessful(): bool
    {
        if ($this->isNoCallback()) {
            return $this->isNoCallbackSuccessful($this->apiStatusCode);
        }

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
        if ($this->isNoCallback()) {
            $this->setPaymentStatusForNoCallback('verify');

            return;
        }

        if ($this->isFailedPaymentBasedOnCallback()) {
            $this->setFailedPaymentBasedOnCallback();

            return;
        }

        $keyMapper = [
            'ResNum' => 'resNum',
            'Amount' => 'amount',
        ];

        $this->ensureCallbackDataMatchesPayload($storedPayload, $keyMapper);

        $this->execute(self::GATEWAY_FOLLOW_UP_BASE_URL, $this->followUpPayload(), 'VerifyTransaction');

        $this->parseFollowUpResponse();

        if ($this->apiIsSuccessful) {
            $this->validateVerifiedAmount($storedPayload);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function reversePayment(): void
    {
        if ($this->isNoCallback()) {
            $this->setPaymentStatusForNoCallback('reverse');

            return;
        }

        $this->execute(self::GATEWAY_FOLLOW_UP_BASE_URL, $this->followUpPayload(), 'ReverseTransaction');

        $this->parseFollowUpResponse();
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareFromCallback(): void
    {
        $this->transactionId = (string) $this->callbackPayload->get('ResNum');
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareWithoutCallback(string $transactionId): void
    {
        $this->transactionId = $transactionId;

        $this->enableNoCallback();
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
            'resNum' => $this->transactionId,
            'amount' => $this->amount,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRedirectData(): PaymentRedirectDto
    {
        $payload = [
            'token' => $this->token,
        ];

        return new PaymentRedirectDto(self::PAYMENT_REDIRECT_URL, 'GET', $payload);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRefNumber(): string
    {
        return (string) $this->callbackPayload->get('RRN', '');
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverCardNumber(): string
    {
        return $this->callbackPayload->get('SecurePan', '');
    }

    /**
     * {@inheritdoc}
     */
    protected function getRequiredCallbackKeys(): array
    {
        return ['State', 'Status', 'ResNum'];
    }

    /**
     * Generate unique Res Number
     */
    private function generateResNum(): string
    {
        $this->transactionId = $this->generateUniqueTimeBaseNumber();

        return $this->transactionId;
    }

    /**
     * Parse the creation API response.
     */
    private function parseCreationResponse(): void
    {
        $response = collect($this->rawResponse);

        $this->apiIsSuccessful = $response->get('status') === 1;

        if ($this->apiIsSuccessful) {
            $this->token = $response->get('token');

            return;
        }

        $this->apiStatusCode = $response->get('errorCode');
        $this->apiStatusMessage = $response->get('errorDesc');
    }

    /**
     * Convert the phone number to the format expected by the gateway.
     */
    private function toDriverPhone(string|int $phone): string
    {
        return (string) Str::of((string) $phone)
            ->chopStart('+')
            ->chopStart('98')
            ->replaceStart('09', '9');
    }

    /**
     * Determine whether the payment failed based on the callback.
     */
    private function isFailedPaymentBasedOnCallback(): bool
    {
        return $this->callbackPayload->get('State') !== 'OK';
    }

    /**
     * Set payment status by callback.
     */
    private function setFailedPaymentBasedOnCallback(): void
    {
        $this->apiIsSuccessful = false;
        $this->apiStatusCode = (string) $this->callbackPayload->get('Status');
        $this->apiStatusMessage = $this->getCallbackMessage($this->callbackPayload->get('State'));
        $this->rawResponse = $this->callbackPayload->all();
    }

    /**
     * Get the callback data status message.
     */
    private function getCallbackMessage(string $state): string
    {
        return match ($state) {
            'CanceledByUser' => 'کاربر انصراف داده است',
            'OK' => 'پرداخت با موفقیت انجام شد',
            'Failed' => 'پرداخت انجام نشد.',
            'SessionIsNull' => 'کاربر در بازه زمانی تعیین شده پاسخی ارسال نکرده است.',
            'InvalidParameters' => 'پارامترهای ارسالی نامعتبر است.',
            'MerchantIpAddressIsInvalid' => 'آدرس سرور پذیرنده نامعتبر است (در پرداخت های بر پایه توکن) هستند.',
            'TokenNotFound' => 'توکن ارسال شده یافت نشد.',
            'TokenRequired' => 'با این شماره ترمینال فقط تراکنش های توکنی قابل پرداخت هستند.',
            'TerminalNotFound' => 'شماره ترمینال ارسال شده یافت نشد.',
            'MultisettlePolicyErrors' => 'محدودیت های مدل چند حسابی رعایت نشده',

            default => 'کد پاسخ نامشخص'
        };
    }

    /**
     * Call the gateway's API with the given method and data.
     *
     * @param  array<string,mixed>|Arrayable<string,mixed>  $data
     */
    private function execute(string $url, array|Arrayable $data, ?string $method = null): void
    {
        $this->guardAgainstSandbox();

        if ($method) {
            $url .= "/{$method}";
        }

        $response = Http::post($url, $data)->throwIfServerError();

        $this->rawResponse = $response->json();
    }

    /**
     * Throws an exception if configured to use sandbox.
     *
     * @throws SandboxNotSupportedException
     */
    private function guardAgainstSandbox(): void
    {
        if ($this->useSandbox()) {
            throw SandboxNotSupportedException::make($this->getGateway());
        }
    }

    /**
     * Parse the follow-up API (verification/reversal) response.
     */
    private function parseFollowUpResponse(): void
    {
        $this->apiIsSuccessful = Arr::get($this->rawResponse, 'Success');
        $this->apiStatusCode = (string) Arr::get($this->rawResponse, 'ResultCode');
        $this->apiStatusMessage = Arr::get($this->rawResponse, 'ResultDescription');
    }

    /**
     * Returns the payload used for follow‑up payment operations
     * such as verification, and reversal.
     *
     * @return array<string,mixed>
     */
    private function followUpPayload(): array
    {
        return [
            'TerminalNumber' => (int) $this->terminalId,
            'RefNum' => $this->callbackPayload->get('RefNum'),
        ];
    }

    /**
     * Validate if the paid amount matches the creation amount.
     *
     * @param  array<string,mixed>  $storedPayload
     */
    private function validateVerifiedAmount(array $storedPayload): void
    {
        $this->apiIsSuccessful = Arr::get($storedPayload, 'amount') === Arr::get($this->rawResponse, 'TransactionDetail.OrginalAmount');

        if (! $this->apiIsSuccessful) {
            $this->apiStatusCode = '1010';
            $this->apiStatusMessage = 'مبلغ پرداخت شده نامعتبر است';
        }
    }

    /**
     * Set the payment status when the gateway is called without callback data.
     */
    private function setPaymentStatusForNoCallback(string $method): void
    {
        $this->apiStatusCode = $this->noCallbackStatusCode($method);
        $this->rawResponse = $this->noCallbackRawResponse();
    }
}
