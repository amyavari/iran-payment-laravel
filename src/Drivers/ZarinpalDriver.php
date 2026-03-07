<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Drivers;

use AliYavari\IranPayment\Abstracts\Driver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * @internal
 *
 * @see https://www.zarinpal.com/docs/paymentGateway/
 */
final class ZarinpalDriver extends Driver
{
    /**
     * Base URL of the payment gateway.
     */
    private const string GATEWAY_BASE_URL = 'https://%s.zarinpal.com/pg/v4/payment/%s.json';

    /**
     * URL of the payment page where the user should be redirected.
     */
    private const string PAYMENT_REDIRECT_URL = 'https://%s.zarinpal.com/pg/StartPay/%s';

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
    private string $amount;

    public function __construct(
        private readonly string $callbackUrl,
        private readonly string $merchantId,
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
            'merchant_id' => $this->merchantId,
            'amount' => $this->amount,
            'currency' => 'IRR',
            'description' => $description ?? '',
            'callback_url' => $callbackUrl,
        ])
            ->when($phone, fn (Collection $data) => $data->merge([
                'metadata' => [
                    'mobile' => $this->toDriverPhone($phone),
                ],
            ]));

        $this->execute('request', $data);

        if ($this->isSuccessful()) {
            $this->transactionId = Arr::get($this->rawResponse, 'data.authority');
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
            -9 => 'خطای اعتبار سنجی',
            -10 => 'ای پی یا مرچنت كد پذیرنده صحیح نیست',
            -11 => 'مرچنت کد فعال نیست، پذیرنده مشکل خود را به امور مشتریان زرین‌پال ارجاع دهد',
            -12 => 'تلاش بیش از دفعات مجاز در یک بازه زمانی کوتاه به امور مشتریان زرین پال اطلاع دهید',
            -13 => 'خطای مربوط به محدودیت تراکنش برای رفع این مورد نسبت به تکمیل مدارک خود با مراجعه به پشتیبانی اقدام نمایید',
            -14 => 'کال‌بک URL با دامنه ثبت شده درگاه مغایرت دارد',
            -15 => 'درگاه پرداخت به حالت تعلیق در آمده است، پذیرنده مشکل خود را به امور مشتریان زرین‌پال ارجاع دهد',
            -16 => 'سطح تایید پذیرنده پایین تر از سطح نقره ای است',
            -17 => 'محدودیت پذیرنده در سطح آبی',
            -18 => 'امکان استف کد درگاه اختصاصی خود بر روی سایت یا جای دیگری را ندارید',
            -19 => 'امکان ایجاد تراکنش برای این ترمینال امکان پذیر نیست',
            -30 => 'پذیرنده اجازه دسترسی به سرویس تسویه اشتراکی شناور را ندارد',
            -31 => 'حساب بانکی تسویه را به پنل اضافه کنید مقادیر وارد شده برای تسهیم درست نیست پذیرنده جهت استفاده از خدمات سرویس تسویه اشتراکی شناور، باید حساب بانکی معتبری به پنل کاربری خود اضافه نماید',
            -32 => 'مبلغ وارد شده از مبلغ کل تراکنش بیشتر است',
            -33 => 'درصدهای وارد شده صحیح نیست',
            -34 => 'مبلغ وارد شده از مبلغ کل تراکنش بیشتر است',
            -35 => 'تعداد افراد دریافت کننده تسهیم بیش از حد مجاز است',
            -36 => 'حداقل مبلغ جهت تسهیم باید 10000 ریال باشد',
            -37 => 'یک یا چند شماره شبای وارد شده برای تسهیم از سمت بانک غیر فعال است',
            -38 => 'خطا٬عدم تعریف صحیح شبا٬لطفا دقایقی دیگر تلاش کنید',
            -39 => 'خطایی رخ داده است به امور مشتریان زرین پال اطلاع دهید',
            -40 => 'Invalid extra params, expire_in is not valid',
            -41 => 'حداکثر مبلغ پرداختی 100 میلیون تومان است',
            -50 => 'مبلغ پرداخت شده با مقدار مبلغ ارسالی در متد وریفای متفاوت است',
            -51 => 'پرداخت ناموفق',
            -52 => 'خطای غیر منتظره‌ای رخ داده است پذیرنده مشکل خود را به امور مشتریان زرین‌پال ارجاع دهد',
            -53 => 'پرداخت متعلق به این مرچنت کد نیست',
            -54 => 'اتوریتی نامعتبر است',
            -55 => 'تراکنش مورد نظر یافت نشد',
            -60 => 'امکان ریورس کردن تراکنش با بانک وجود ندارد',
            -61 => 'تراکنش موفق نیست یا قبلا ریورس شده است',
            -62 => 'آی پی درگاه ست نشده است',
            -63 => 'حداکثر زمان (۳۰ دقیقه) برای ریورس کردن این تراکنش منقضی شده است',
            100 => 'عملیات موفق',
            101 => 'تراکنش وریفای شده است',

            default => 'کد پاسخ نامشخص'
        };
    }

    /**
     * {@inheritdoc}
     */
    protected function isSuccessful(): bool
    {
        return $this->apiStatusCode === 100
            || $this->apiStatusCode === 101;
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
            $this->setFailedPaymentBasedOnCallback();

            return;
        }

        $keyMapper = [
            'Authority' => 'authority',
        ];

        $this->ensureCallbackDataMatchesPayload($storedPayload, $keyMapper);

        $data = [
            'merchant_id' => $this->merchantId,
            'authority' => $this->transactionId,
            'amount' => Arr::get($storedPayload, 'amount'),
        ];

        $this->execute('verify', $data);
    }

    /**
     * {@inheritdoc}
     */
    protected function settlePayment(): void
    {
        $this->apiStatusCode = 100;
        $this->rawResponse = 'No API is called. IPG only has auto settlement.';
    }

    /**
     * {@inheritdoc}
     */
    protected function reversePayment(): void
    {
        $data = [
            'merchant_id' => $this->merchantId,
            'authority' => $this->transactionId,
        ];

        $this->execute('reverse', $data);
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareFromCallback(): void
    {
        $this->transactionId = $this->callbackPayload->get('Authority');
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareWithoutCallback(string $transactionId): void
    {
        $this->transactionId = $transactionId;

        $this->callbackPayload = collect([
            'Authority' => $this->transactionId,
            'Status' => 'OK',
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
            'authority' => $this->transactionId,
            'amount' => $this->amount,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRedirectData(): PaymentRedirectDto
    {
        return new PaymentRedirectDto($this->getGaymentRedirectUrl(), 'GET', payload: []);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRefNumber(): string
    {
        return (string) Arr::get($this->rawResponse, 'data.ref_id');
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverCardNumber(): string
    {
        return Arr::get($this->rawResponse, 'data.card_pan');
    }

    /**
     * {@inheritdoc}
     */
    protected function getRequiredCallbackKeys(): array
    {
        return ['Authority', 'Status'];
    }

    /**
     * Call the gateway's API with the given method and data.
     *
     * @param  array<string,mixed>|Arrayable<string,mixed>  $data
     */
    private function execute(string $method, array|Arrayable $data): void
    {
        $url = $this->getGatewayUrl($method);

        $response = Http::post($url, $data)->throwIfServerError();

        $this->rawResponse = $response->json();

        $this->setApiStatusCode();
    }

    /**
     * Get the gateway API URL based on the configuration and the specified API method.
     */
    private function getGatewayUrl(string $method): string
    {
        return sprintf(self::GATEWAY_BASE_URL, $this->getApiSubdomain(), $method);
    }

    /**
     * Get the gateway redirect URL based on the cofiguration and the authority.
     */
    private function getGaymentRedirectUrl(): string
    {
        return sprintf(self::PAYMENT_REDIRECT_URL, $this->getApiSubdomain(), $this->transactionId);
    }

    /**
     * Get the gateway subsomain based on the configuration.
     */
    private function getApiSubdomain(): string
    {
        return $this->useSandbox() ? 'sandbox' : 'payment';
    }

    /**
     * Pars the API response and set the status code.
     */
    private function setApiStatusCode(): void
    {
        $successCode = Arr::get($this->rawResponse, 'data.code');
        $errorCode = Arr::get($this->rawResponse, 'errors.code');

        $this->apiStatusCode = $successCode ?? $errorCode;
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
        return $this->callbackPayload->get('Status') === 'NOK';
    }

    /**
     * Set payment status by callback.
     */
    private function setFailedPaymentBasedOnCallback(): void
    {
        $this->apiStatusCode = -51;
        $this->rawResponse = $this->callbackPayload->all();
    }
}
