<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Drivers;

use AliYavari\IranPayment\Abstracts\Driver;
use AliYavari\IranPayment\Contracts\UniqueNumberGenerator;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\SandboxNotSupportedException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * @internal
 *
 * Based on version 1.11 (August 2022) of IPG documentation.
 */
final class SadadDriver extends Driver
{
    /**
     * Base URL of the payment gateway.
     */
    private const string GATEWAY_BASE_URL = 'https://sadad.shaparak.ir/api/v0';

    /**
     * URL of the payment page where the user should be redirected.
     */
    private const string GATEWAY_REDIRECT_URL = 'https://sadad.shaparak.ir/Purchase';

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
        private readonly string $merchantId,
        private readonly string $terminalKey,
        private readonly string $callbackUrl,
        private readonly UniqueNumberGenerator $uniqueNumber,
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

        $orderId = $this->generateOrderId();

        $data = collect([
            'MerchantId' => $this->merchantId,
            'TerminalId' => $this->terminalId,
            'Amount' => $this->amount,
            'OrderId' => $orderId,
            'LocalDateTime' => now()->tz('Asia/Tehran')->format('Y-m-d H:i:s'),
            'ReturnUrl' => $callbackUrl,
            'SignData' => $this->buildSignData($this->terminalId, $orderId, $this->amount),
        ])
            ->when($description, fn (Collection $data): Collection => $data->merge(['AdditionalData' => (string) $description]))
            ->when($phone, fn (Collection $data): Collection => $data->merge(['CardHolderIdentity' => $this->toDriverPhone($phone)]));

        $this->execute('Request/PaymentRequest', $data);

        if ($this->isSuccessful()) {
            $this->extractToken();
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
        return match ($this->apiStatusCode) {
            10010 => 'مبلغ پرداخت شده نامعتبر است', // Internal status
            10011 => 'درگاه از بازگشت وجه پشتیبانی نمی کند', // Internal status

            0 => 'تراکنش موفق',
            100 => 'درخواست تکراری است (قبلا در سیستم با موفقیت ثبت شده است)',
            -1 => 'تراکنش ناموفق',
            3 => 'پذیرنده کارت فعال نیست لطفا با بخش امور پذیرندگان تماس حاصل فرمائید',
            23 => 'پذیرنده کارت نامعتبر است لطفا با بخش امور پذیرندگان تماس حاصل فرمائید',
            58 => 'انجام تراکنش مربوطه توسط پایانه انجام‌دهنده مجاز نمی‌باشد',
            61 => 'مبلغ تراکنش از حد مجاز بالاتر است',
            101 => 'مهلت ارسال تراکنش به پایان رسیده است',
            1000 => 'ترتیب پارامترهای ارسالی اشتباه می‌باشد، لطفا مسئول فنی پذیرنده با بانک تماس حاصل فرمایند',
            1001 => 'پارامترهای پرداخت اشتباه می‌باشد، لطفا مسئول فنی پذیرنده با بانک تماس حاصل فرمایند',
            1002 => 'خطا در سیستم - تراکنش ناموفق',
            1003 => 'IP پذیرنده اشتباه است، لطفا مسئول فنی پذیرنده با بانک تماس حاصل فرمایند',
            1004 => 'شماره پذیرنده اشتباه است، لطفا مسئول فنی پذیرنده با بانک تماس حاصل فرمایند',
            1005 => 'خطای دسترسی: لطفا بعدا تلاش فرمایید',
            1006 => 'خطا در سیستم',
            1011 => 'درخواست تکراری - شماره سفارش تکراری می‌باشد',
            1012 => 'اطلاعات پذیرنده صحیح نیست، یکی از موارد تاریخ، زمان یا کلید تراکنش اشتباه است',
            1015 => 'پاسخ خطای نامشخص از سمت مرکز',
            1017 => 'مبلغ درخواستی شما جهت پرداخت از حد مجاز تعریف شده برای این پذیرنده بیشتر است',
            1018 => 'اشکال در تاریخ و زمان سیستم، لطفا تاریخ و زمان سرور خود را با بانک هماهنگ نمایید',
            1019 => 'امکان پرداخت از طریق سیستم شتاب برای این پذیرنده امکان‌پذیر نیست',
            1020 => 'پذیرنده غیرفعال شده است، لطفا جهت فعال‌سازی با بانک تماس بگیرید',
            1023 => 'آدرس بازگشت پذیرنده نامعتبر است',
            1024 => 'مهر زمانی پذیرنده نامعتبر است',
            1025 => 'امضا تراکنش نامعتبر است',
            1026 => 'شماره سفارش تراکنش نامعتبر است',
            1027 => 'شماره پذیرنده نامعتبر است',
            1028 => 'شماره ترمینال پذیرنده نامعتبر است',
            1029 => 'آدرس IP پرداخت در محدوده آدرس‌های معتبر اعلام شده توسط پذیرنده نیست',
            1030 => 'آدرس Domain پرداخت در محدوده آدرس‌های معتبر اعلام شده توسط پذیرنده نیست',
            1031 => 'مهلت زمانی شما جهت پرداخت به پایان رسیده است',
            1032 => 'پرداخت با این کارت برای پذیرنده موردنظر امکان‌پذیر نیست',
            1033 => 'به علت مشکل در سایت پذیرنده، پرداخت برای این پذیرنده غیرفعال شده است',
            1036 => 'اطلاعات اضافی ارسال نشده یا دارای اشکال است',
            1037 => 'شماره پذیرنده یا شماره ترمینال پذیرنده صحیح نمی‌باشد',
            1040 => 'شناسه وارد شده معتبر نمی‌باشد',
            1053 => 'درخواست معتبر از سمت پذیرنده صورت نگرفته است، لطفا اطلاعات پذیرنده خود را چک کنید',
            1055 => 'مقدار غیرمجاز در ورود اطلاعات',
            1056 => 'سیستم موقتا قطع می‌باشد، لطفا بعدا تلاش فرمایید',
            1058 => 'سرویس پرداخت اینترنتی خارج از سرویس می‌باشد، لطفا بعدا سعی فرمایید',
            1061 => 'اشکال در تولید کد یکتا، لطفا مرورگر خود را بسته و مجددا تلاش کنید',
            1064 => 'لطفا مجددا سعی فرمایید',
            1065 => 'ارتباط ناموفق، لطفا چند لحظه دیگر مجددا سعی کنید',
            1066 => 'سیستم سرویس‌دهی پرداخت موقتا غیرفعال شده است',
            1068 => 'به علت بروزرسانی، سیستم موقتا قطع می‌باشد',
            1072 => 'خطا در پردازش پارامترهای اختیاری پذیرنده',
            1101 => 'مبلغ تراکنش نامعتبر است',
            1103 => 'توکن ارسالی نامعتبر است',
            1104 => 'اطلاعات تسهیم صحیح نیست',
            1105 => 'تراکنش بازگشت داده شده است (مهلت زمانی به پایان رسیده است)',

            default => 'کد پاسخ نامشخص',
        };
    }

    /**
     * {@inheritdoc}
     */
    protected function isSuccessful(): bool
    {
        return $this->apiStatusCode === 0
            || $this->apiStatusCode === 100;
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
            'OrderId' => 'orderId',
        ];

        $this->ensureCallbackDataMatchesPayload($storedPayload, $keyMapper);

        $token = Arr::get($storedPayload, 'token');

        $data = [
            'Token' => $token,
            'SignData' => $this->buildSignData($token),
        ];

        $this->execute('Advice/Verify', $data);

        if ($this->isSuccessful()) {
            $this->validateVerifiedAmount($storedPayload);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function reversePayment(): void
    {
        $this->apiStatusCode = 10011;
        $this->rawResponse = 'No API is called. IPG does not support reversal.';
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareFromCallback(): void
    {
        $this->transactionId = (string) $this->callbackPayload->get('OrderId');
        $this->apiStatusCode = (int) $this->callbackPayload->get('ResCode');
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareWithoutCallback(string $transactionId): void
    {
        $this->transactionId = $transactionId;

        $this->callbackPayload = collect([
            'ResCode' => 0,
            'OrderId' => $this->transactionId,
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
            'orderId' => $this->transactionId,
            'token' => $this->token,
            'amount' => $this->amount,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRedirectData(): PaymentRedirectDto
    {
        $payload = [
            'Token' => $this->token,
        ];

        return new PaymentRedirectDto(self::GATEWAY_REDIRECT_URL, 'GET', $payload);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRefNumber(): string
    {
        return (string) Arr::get($this->rawResponse, 'RetrivalRefNo');
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverCardNumber(): string
    {
        return $this->callbackPayload->get('PrimaryAccNo');
    }

    /**
     * {@inheritdoc}
     */
    protected function getRequiredCallbackKeys(): array
    {
        return ['OrderId', 'ResCode'];
    }

    /**
     * Generate unique order ID
     */
    private function generateOrderId(): int
    {
        $this->transactionId = $this->uniqueNumber->generate();

        return (int) $this->transactionId;
    }

    /**
     * Build the gateway 'signData' value from the given fields.
     */
    private function buildSignData(string|int ...$values): string
    {
        $data = Arr::join($values, ';');

        $key = base64_decode($this->terminalKey, true);

        $encrypted = openssl_encrypt($data, 'DES-EDE3', $key, OPENSSL_RAW_DATA);

        return base64_encode($encrypted);
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
     * Call the gateway's API with the given method and data.
     *
     * @param  array<string,mixed>|Arrayable<string,mixed>  $data
     */
    private function execute(string $method, array|Arrayable $data): void
    {
        $this->guardAgainstSandbox();

        $this->rawResponse = Http::baseUrl(self::GATEWAY_BASE_URL)
            ->post($method, $data)
            ->throwIfServerError()
            ->json();

        $this->extractStatusCode();
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
     * Extract status code from API response.
     */
    private function extractStatusCode(): void
    {
        $this->apiStatusCode = (int) Arr::get($this->rawResponse, 'ResCode');
    }

    /**
     *  Extract token from gateway response.
     */
    private function extractToken(): void
    {
        $this->token = Arr::get($this->rawResponse, 'Token');
    }

    /**
     * Determine whether the payment failed based on the callback.
     */
    private function isFailedPaymentBasedOnCallback(): bool
    {
        return ((int) $this->callbackPayload->get('ResCode')) !== 0;
    }

    /**
     * Set payment status by callback.
     */
    private function setPaymentStatusBasedOnCallback(): void
    {
        $this->apiStatusCode = (int) $this->callbackPayload->get('ResCode');
        $this->rawResponse = $this->callbackPayload->all();
    }

    /**
     * Validate if the paid amount matches the creation amount.
     *
     * @param  array<string,mixed>  $storedPayload
     */
    private function validateVerifiedAmount(array $storedPayload): void
    {
        $isAmountValid = Arr::get($storedPayload, 'amount') === (int) Arr::get($this->rawResponse, 'Amount');

        if (! $isAmountValid) {
            $this->apiStatusCode = 10010;
        }
    }
}
