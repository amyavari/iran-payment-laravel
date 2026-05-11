<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Drivers;

use AliYavari\IranPayment\Abstracts\Driver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Enums\InternalErrorCode;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * @internal
 *
 * @see https://help.zibal.ir/ipg
 */
final class ZibalDriver extends Driver
{
    /**
     * Base URL of the payment gateway.
     */
    private const string GATEWAY_BASE_URL = 'https://gateway.zibal.ir';

    /**
     * Status code returned by the last API call.
     */
    private int $apiStatusCode;

    /**
     * Raw response from the last API call.
     *
     * @var array<string,mixed>|string
     */
    private string|array $rawResponse;

    /**
     * Transaction ID
     */
    private ?int $transactionId = null;

    /**
     * Amount of the payment in Rial.
     */
    private int $amount;

    public function __construct(
        private readonly string $callbackUrl,
        private readonly string $merchant,
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
            'amount' => $this->amount,
            'callbackUrl' => $callbackUrl,
        ])
            ->when($description, fn (Collection $data) => $data->merge(['description' => (string) $description]))
            ->when($phone, fn (Collection $data) => $data->merge(['mobile' => $this->toDriverPhone($phone)]));

        $this->execute('v1/request', $data);

        if ($this->isSuccessful()) {
            $this->setTransactionId();
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
        return InternalErrorCode::getMessage($this->apiStatusCode)
            ?? $this->getGatewayMessage();
    }

    /**
     * {@inheritdoc}
     */
    protected function isSuccessful(): bool
    {
        return $this->apiStatusCode === 100
            || $this->apiStatusCode === 1;
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
            'trackId' => 'trackId',
        ];

        $this->ensureCallbackDataMatchesPayload($storedPayload, $keyMapper);

        $data = collect([
            'trackId' => $this->transactionId,
        ]);

        $this->execute('v1/verify', $data);

        /**
         * Three layers of the verification:
         * 1. API call result
         * 2. Gateway verification status
         * 3. Amount equality
         */
        if ($this->isSuccessful()) {
            $this->setVerificationStatus();
        }

        if ($this->isSuccessful()) {
            $this->validateVerifiedAmount($storedPayload);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function reversePayment(): void
    {
        $this->apiStatusCode = InternalErrorCode::ReverseNotSupport->value;
        $this->rawResponse = 'No API is called. IPG does not support reversal.';
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareFromCallback(): void
    {
        $this->transactionId = (int) $this->callbackPayload->get('trackId');
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareWithoutCallback(string $transactionId): void
    {
        $this->transactionId = (int) $transactionId;

        $this->callbackPayload = collect([
            'success' => '1',
            'status' => '2',
            'trackId' => $this->transactionId,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverTransactionId(): string
    {
        return (string) $this->transactionId;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverPayload(): array
    {
        return [
            'trackId' => $this->transactionId,
            'amount' => $this->amount,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRedirectData(): PaymentRedirectDto
    {
        $url = self::GATEWAY_BASE_URL."/start/{$this->transactionId}";

        return new PaymentRedirectDto($url, 'GET', payload: []);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRefNumber(): string
    {
        return (string) Arr::get($this->rawResponse, 'refNumber');
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverCardNumber(): string
    {
        return Arr::get($this->rawResponse, 'cardNumber');
    }

    /**
     * {@inheritdoc}
     */
    protected function getRequiredCallbackKeys(): array
    {
        return ['success', 'status', 'trackId'];
    }

    /**
     * Get the error message returned by the gateway.
     */
    private function getGatewayMessage(): string
    {
        return match ($this->apiStatusCode) {
            -1 => 'در انتظار پرداخت',
            -2 => 'خطای داخلی',
            1 => 'پرداخت شده - تاییدشده',
            2 => 'پرداخت شده - تاییدنشده',
            3 => 'لغوشده توسط کاربر',
            4 => 'شماره کارت نامعتبر می‌باشد.',
            5 => 'موجودی حساب کافی نمی‌باشد.',
            6 => 'رمز واردشده اشتباه می‌باشد.',
            7 => 'تعداد درخواست‌ها بیش از حد مجاز می‌باشد.',
            8 => 'تعداد پرداخت اینترنتی روزانه بیش از حد مجاز می‌باشد.',
            9 => 'مبلغ پرداخت اینترنتی روزانه بیش از حد مجاز می‌باشد.',
            10 => 'صادرکننده‌ی کارت نامعتبر می‌باشد.',
            11 => 'خطای سوییچ',
            12 => 'کارت قابل دسترسی نمی‌باشد.',
            15 => 'تراکنش استرداد شده',
            16 => 'تراکنش در حال استرداد',
            18 => 'تراکنش ریورس شده',
            21 => 'پذیرنده نامعتبر است',
            100 => 'با موفقیت تایید شد.',
            102 => 'merchant یافت نشد.',
            103 => 'merchant غیرفعال',
            104 => 'merchant نامعتبر',
            105 => 'amount بایستی بزرگتر از 1,000 ریال باشد.',
            106 => 'callbackUrl نامعتبر می‌باشد. (شروع با http و یا https)',
            107 => 'percentMode نامعتبر می‌باشد. (تنها 0 و 1 قابل قبول هستند)',
            108 => 'یک یا چند ذی‌نفع در multiplexingInfos نامعتبر می‌باشند.',
            109 => 'یک یا چند ذی‌نفع در multiplexingInfos غیرفعال می‌باشند.',
            110 => 'id = self در multiplexingInfos وجود ندارد.',
            111 => 'amount با مجموع سهم‌ها در multiplexingInfos برابر نمی‌باشد.',
            112 => 'موجودی کیف پول کارمزد جهت کسر کارمزد کافی نیست.',
            113 => 'amount مبلغ تراکنش از سقف میزان تراکنش بیشتر است.',
            114 => 'کدملی ارسالی نامعتبر است.',
            115 => 'IP شما در پنل کاربری ثبت نشده است.',
            201 => 'قبلا تایید شده',
            202 => 'سفارش پرداخت نشده یا ناموفق بوده است.',
            203 => 'trackId نامعتبر می‌باشد.',

            default => 'کد پاسخ نامشخص',
        };
    }

    /**
     * Call the gateway's API with the given method and data.
     *
     * @param  Collection<string,mixed>  $data
     */
    private function execute(string $url, Collection $data): void
    {
        $this->rawResponse = Http::baseUrl(self::GATEWAY_BASE_URL)
            ->post($url, $this->withCredentials($data))
            ->throwIfServerError()
            ->json();

        $this->setApiStatusCode();
    }

    /**
     * Parse the API response and set the status code.
     */
    private function setApiStatusCode(): void
    {
        $this->apiStatusCode = (int) Arr::get($this->rawResponse, 'result');
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
     * Parse the creation API response and set the transaction ID.
     */
    private function setTransactionId(): void
    {
        $this->transactionId = Arr::get($this->rawResponse, 'trackId');
    }

    /**
     * Add credentials to the data based on the environment.
     *
     * @param  Collection<string,mixed>  $data
     * @return Collection<string,mixed>
     */
    private function withCredentials(Collection $data): Collection
    {
        return $data->merge([
            'merchant' => $this->useSandbox() ? 'zibal' : $this->merchant,
        ]);
    }

    /**
     * Determine whether the payment failed based on the callback.
     */
    private function isFailedPaymentBasedOnCallback(): bool
    {
        return $this->callbackPayload->get('success') === '0';
    }

    /**
     * Set payment status by callback.
     */
    private function setPaymentStatusBasedOnCallback(): void
    {
        $this->apiStatusCode = (int) $this->callbackPayload->get('status');
        $this->rawResponse = $this->callbackPayload->all();
    }

    /**
     * Set verification status based on verification API response.
     */
    private function setVerificationStatus(): void
    {
        $this->apiStatusCode = (int) Arr::get($this->rawResponse, 'status');
    }

    /**
     * Validate if the paid amount matches the creation amount.
     *
     * @param  array<string,mixed>  $storedPayload
     */
    private function validateVerifiedAmount(array $storedPayload): void
    {
        $isAmountValid = Arr::get($storedPayload, 'amount') === Arr::get($this->rawResponse, 'amount');

        if (! $isAmountValid) {
            $this->apiStatusCode = InternalErrorCode::InvalidAmount->value;
        }
    }
}
