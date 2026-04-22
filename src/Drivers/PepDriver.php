<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Drivers;

use AliYavari\IranPayment\Abstracts\Driver;
use AliYavari\IranPayment\Concerns\HasUniqueNumber;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\SandboxNotSupportedException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * @internal
 *
 * Based on version 22.0 (December 2025) of IPG documentation.
 */
final class PepDriver extends Driver
{
    use HasUniqueNumber;

    /**
     * Cache key used to store the gateway token.
     */
    private const string  CACHE_KEY = 'iran_payment_pep_token';

    /**
     * Endpoint for retrieving a new token from the gateway.
     */
    private const string GATEWAY_GET_TOKEN = 'token/getToken';

    /**
     * Base path for all payment-related API requests.
     */
    private const string GATEWAY_API_BASE_PATH = 'api/payment';

    /**
     * Status code returned by the last API call.
     */
    private int $apiStatusCode;

    /**
     * Raw response from the last API call.
     *
     * @var array<string,mixed>
     */
    private array $rawResponse;

    /**
     * Transaction ID
     */
    private ?string $transactionId = null;

    /**
     * Amount of the payment in Rial.
     */
    private int $amount;

    /**
     * Payment unique URL ID returned by the gateway, required for verification and reversal
     */
    private string $urlId;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $terminalNumber,
        private readonly string $username,
        private readonly string $password,
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
            'invoice' => $this->generateInvoice(),
            'invoiceDate' => now()->tz('Asia/Tehran')->format('Y-m-d'),
            'amount' => $this->amount,
            'callbackApi' => $callbackUrl,
            'serviceCode' => '8',
            'serviceType' => 'PURCHASE',
            'terminalNumber' => (int) $this->terminalNumber,
        ])
            ->when($phone, fn (Collection $data) => $data->merge(['mobileNumber' => $this->toDriverPhone($phone)]))
            ->when($description, fn (Collection $data) => $data->merge(['description' => (string) $description]));

        $this->execute($this->toApiUrl('purchase'), $data);

        if ($this->successful()) {
            $this->urlId = Arr::get($this->rawResponse, 'data.urlId');
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
            1010 => 'مبلغ پرداخت شده نامعتبر است', // Internal status

            0 => 'تراکنش موفق است',
            1 => 'ناموفق',
            401 => 'مجاز به استفاده از سرویس نیستید',
            500 => 'خطا داخلی سرور',
            13000 => 'ورودی نامعتبر است.',
            13001 => 'توکن نامعتبر است',
            13002 => 'توکن نامعتبر است',
            13003 => 'کد رهگیری یکتا نیست',
            13004 => 'توکن خالی است',
            13005 => 'عدم امکان دسترسی موقت به سرور',
            13008 => 'توكن منقضی شده است.',
            13009 => 'توکن یافت نشد',
            13010 => 'شماره موبایل نامعتبر است.',
            13011 => 'کد محصول نامعتبر است',
            13012 => 'شناسه قبض نامعتبر است',
            13013 => 'شناسه پرداخت نامعتبر است.',
            13016 => 'تراکنش یافت نشد',
            13018 => 'یافت نشد',
            13020 => 'خطا هنگام چک کردن که شرکت',
            13025 => 'تراکنش ناموفق است',
            13026 => 'شماره کارت نامعتبر است',
            13027 => 'شماره موبایل برای شماره کارت وارد شده نیست',
            13028 => 'آدرس کالبک نامعتبر است',
            13029 => 'تراکنش موفق و تایید شده است',
            13030 => 'تراکنش تاییده شده و ناموفق است',
            13031 => 'تراکنش منتظر تایید است.',
            13032 => 'کارت منقضی شده است.',
            13033 => 'تراکنش و بازگشت تراکنش با موفقیت انجام شده است.',
            13045 => 'دارنده کارت تراکنش را لغو کرد',
            13046 => 'تراکنش تسویه شده است',
            13047 => 'پذیرنده کارت نامعتبر است.',
            13049 => 'تراکنش کامل نشده است',
            13054 => 'تراکنش نامعتبر است',
            13055 => 'مبلغ تراکنش نامعتبر است.',
            13056 => 'صادر کننده کارت نامعتبر است.',
            13057 => 'تاریخ کارت منقضی شده است',
            13058 => 'کارت موقتاً مسدود شده است',
            13059 => 'حساب تعریف نشده است',
            13060 => 'نوع تراکنش نامعتبر است',
            13061 => 'شناسه انتقال نامعتبر است.',
            13062 => 'تراکنش تکراری',
            13063 => 'مبلغ کافی نیست',
            13064 => 'پین اشتباه است',
            13065 => 'کارت نامعتبر است',
            13066 => 'سرویس روی کارت مجاز نیست',
            13067 => 'کارت برای ترمینال مجاز نیست',
            13068 => 'مبلغ تراکنش بیش از حد مجاز است',
            13069 => 'کارت محدود است',
            13070 => 'خطای امنیتی',
            13071 => 'مبلغ تراکنش با قیمت نهایی مطابقت ندارد',
            13072 => 'درخواست تراکنش بیش از حد مجاز است',
            13073 => 'حساب غیر فعال است',
            13074 => 'کارت توسط ترمینال مسدود شد',
            13075 => 'زمان تأییدیه منقضی شده است',
            13076 => 'زمان اصلاحیه منقضی شده است.',
            13077 => 'تراکنش اصلی نامعتبر است',
            13078 => 'رمز کارت وارد گردد',
            13079 => 'روز کاری نامعتبر است',
            13080 => 'کارت غیر فعال است',
            13081 => 'حساب کارت نامعتبر است.',
            13083 => 'داده های رمزگذاری شده نامعتبر است',
            13084 => 'سوئیچ یا شاپرک خاموش است.',
            13085 => 'میزبان بانک مقصد پایین است.',
            13086 => 'اطلاعات تاییدیه نامعتبر است',
            13087 => 'سوئیچ یا شاپرک در حال خاموش شدن است.',
            13088 => 'کلید رمزگذاری شده نامعتبر است',
            13089 => 'انجام تراکنش با واسط غیر تماسی مردود شده است',
            13090 => 'پایان روز کاری',
            13091 => 'سوئیچ غیر فعال است',
            13092 => 'صادر کننده کارت نامعتبر',
            13093 => 'تراکنش موفقیت آمیز نیست',
            13094 => 'تراکنش تکراری است',
            13095 => 'پین قدیمی اشتباه است.',
            13096 => 'خطای داخلی سوئیچ',
            13097 => 'در حال انجام فرآیند تغییر کلید',
            13098 => 'پین استاتیک فراتر از محدودیت',
            13099 => 'پایانه فروش نامعتبر است',
            13300 => 'پایانه در سیستم غیر فعال است.',
            13301 => 'فروشگاه در سیستم تعریف نشده است.',
            13302 => 'فرمت کد ملی نامعتبر است',
            13303 => 'پسورد ترمینال نامعتبر است.',
            13304 => 'شناسه پرداخت نامعتبر است.',
            13305 => 'داده های تراکنش ناکافی است.',
            13306 => 'کد شارژ نامعتبر است',
            13307 => 'مهلت زمانی برای کارت یا پین به پایان رسیده است',
            13308 => 'اطلاعات حساب ناشناس است',
            13310 => 'خطای دیگر',
            13311 => 'تمام شارژها فروخته شده',
            13312 => 'شارژ موجود نیست',
            13313 => 'اپراتور در دسترس نیست',
            13314 => 'شماره موبایل خالی است',
            13315 => 'در رزرو فاکتور مشکلی وجود دارد',
            13316 => 'اصل تراکنش مالی موفق نمیباشد',
            13317 => 'کپچا اشتباه است.',
            13318 => 'کد سازمان ناموجود',
            13319 => 'سیستم با اختلال همراه است',
            13320 => 'کارت نامعتبر است',
            13321 => 'اطلاعات تکمیلی پایانه موجود نیست',
            13322 => 'درخواست رمز پویا بیش از حد مجاز',
            13323 => 'عدم انطباق کد ملی با شماره کارت',
            13324 => 'اصل تراکنش مالی موفق نمیباشد',
            13325 => 'اصل تراکنش یافت نشد',
            13326 => 'کارت مسدود است',
            13327 => 'کارت به دلایل ویژه مسدود شده است',
            13328 => 'موفق با احراز هویت دارنده کارت',
            13329 => 'سیستم مشغول است',
            13331 => 'کارمزد نامعتبر است.',
            13332 => 'PSP توسط شاپرک پشتیبانی نمی شود.',
            13333 => 'کارت نامعتبر است',
            13334 => 'ورود پین از حد مجاز گذشته است',
            13335 => 'کارت گمشده است',
            13336 => 'کارت حساب عمومی ندارد.',
            13337 => 'کارت سرقت شده است',
            13338 => 'حساب تعریف نشده',
            13339 => 'پاسخ خیلی دیر دریافت شد',
            13340 => 'کارت یا حساب مبدا در وضعیت نامناسب میباشد',
            13341 => 'کارت یا حساب مقصد در وضعیت نامناسب میباشد',
            13342 => 'ورود پین اشتباه بیش از حد مجاز است',
            13343 => 'فروشگاه نامعتبر است.',
            13344 => 'خطای داخلی ثبت کارت',
            13345 => 'شماره کارت تکراری است',
            13346 => 'فرمت شماره موبایل نامعتبر است.',
            13347 => 'فرمت شماره شناسنامه نامعتبر است',
            13348 => 'کد ملی یا سازمان تکراری است.',
            13350 => 'خطای اعتبار سنجی',
            13351 => 'قالب شماره فاکتور معتبر نیست',
            13352 => 'نام کاربری معتبر نیست',
            13353 => 'دسترسی اپراتور لغو شد',
            13354 => 'دسترسی سرویس لغو شده است',
            13355 => 'اعتبار اپراتور کافی نیست',
            13356 => 'در رزرو فاکتور مشکلی وجود دارد',
            13357 => 'اعتبار سرویس کافی نیست',
            13358 => 'وضعیت تراکنش باید نامشخص باشد',
            13359 => 'پروتکل یافت نشد',
            13360 => 'تراکنش اصلی باید مالی باشد',
            13361 => 'تراکنش تسویه ناموفق است',
            13364 => 'نوع وصول آنی نامعتبر می باشد',
            13365 => 'تراکنش پشتیبانی نمی شود',
            13366 => 'توکن فعال نشده است',
            13367 => 'فرمت ورود نا معتبر',
            13368 => 'خطای ورود',
            13369 => 'فرمت ورودی نا معتبر',
            13370 => 'خطای محدودیت در زمان',
            13371 => 'خطای دسترسی',
            13372 => 'خطای بازیابی آدرس',
            13373 => 'گروه ترمینال یافت نشد.',
            13374 => 'خطای درخواست',
            13375 => 'شماره تلفن نا معتبر',
            13376 => 'خطای اعتبار سنجی',
            13377 => 'خطا در تراکنش',
            13378 => 'خطای پروتکل',
            13379 => 'پین بلاک نا معتبر است.',
            13380 => 'cvv2 نا معتبر است.',
            13381 => 'فرمت نادرست شماره تماس',
            13382 => 'خطا در نوع اصلاحیه',
            13383 => 'تراکنش تسویه شده است',
            13384 => 'رمز اشتباه است',
            13385 => 'رکورد تکراری',
            13386 => 'خطا در انتقال',
            13390 => 'خطا در تغییر سایز تصویر',
            13391 => 'خطا در پارامترهای ورودی',
            13392 => 'خطا در درخواست رمزنگاری سخت افزاری',
            13393 => 'درخواستی یافت نشد',
            13394 => 'خطا در فرمت ورودی',
            13395 => 'تراکنش برگشت از خرید ناموفق است',
            13396 => 'خطا در سریال پایانه فروش',
            13397 => 'ورود مجدد تراکنش',
            13398 => 'درخواست کپچا بیش از حد مجاز است',
            13399 => 'ترمینال یافت نشد',
            13400 => 'کلید عمومی یافت نشد',
            13401 => 'شارژ مورد نظر موجود نیست',
            13402 => 'ارجاع دهنده با اطلاعات ثبت شده تطابق ندارد',
            13403 => 'شبا معتبر نیست',
            13404 => 'خطا در تغییر نوع متغییر',
            13405 => 'خطا (مقدار خالی',
            13406 => 'عملیات به خطا خورد',
            13407 => 'خطا در احراز هویت',
            13408 => 'کد سازمان یافت نشد',
            13409 => 'خطا در offset',
            13410 => 'خطا در سایز صفحه',
            13411 => 'خطا در هم سازی',
            13412 => 'خطا در خواندن مقدار',
            13413 => 'خطا در عملیات',
            13414 => 'خطا در آپدیت',
            13415 => 'خطا در خرید شناسه دار',
            13416 => 'خطای چند شبایی',
            13417 => 'عملیات مورد نظر پیاده سازی نشده است',
            13418 => 'خطا در تاریخ درخواست',
            13419 => 'خطا در زمان ورود',
            13420 => 'شماره مرجع نامعتبر',
            13421 => 'تراکنش به اتمام رسید',
            13422 => 'آی پی پذیرنده معتبر نمی باشد',
            13423 => 'تراکنش مجاز نیست',
            13424 => 'پیام پرداخت یار مجاز نمی باشد',
            13425 => 'خطایی از طرف تامین کننده رخ داده است',
            13426 => 'وضعیت تراکنش تایید شده نیست',
            13427 => 'دسترسی غیر مجاز',
            13428 => 'درخواست تاییدیه قبلا ارسال شده است.',
            13429 => 'احراز هویت موفق نبود',
            13430 => 'احراز هویت موفق نبود - بعدا دوباره تلاش کنید',
            default => 'خطای ناشناخته'
        };
    }

    /**
     * {@inheritdoc}
     */
    protected function isSuccessful(): bool
    {
        return $this->apiStatusCode === 0;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRawResponse(): array
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
            'invoiceId' => 'invoice',
        ];

        $this->ensureCallbackDataMatchesPayload($storedPayload, $keyMapper);

        $this->urlId = Arr::get($storedPayload, 'urlId'); // Required for reverse.

        $data = [
            'checkVerify' => false,
            'invoice' => $this->transactionId,
            'urlId' => $this->urlId,
        ];

        $this->execute($this->toApiUrl('verify-payment'), $data);

        if ($this->isSuccessful()) {
            $this->validateVerifiedAmount($storedPayload);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function reversePayment(): void
    {
        $data = [
            'invoice' => $this->transactionId,
            'urlId' => $this->urlId,
        ];

        $this->execute($this->toApiUrl('reverse-transactions'), $data);
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareFromCallback(): void
    {
        $this->transactionId = (string) $this->callbackPayload->get('invoiceId');
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareWithoutCallback(string $transactionId): void
    {
        $this->transactionId = $transactionId;

        $this->callbackPayload = collect([
            'status' => 'success',
            'invoiceId' => $this->transactionId,
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
            'invoice' => $this->transactionId,
            'urlId' => $this->urlId,
            'amount' => $this->amount,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRedirectData(): PaymentRedirectDto
    {
        $url = Arr::get($this->rawResponse, 'data.url');

        return new PaymentRedirectDto($url, 'GET', payload: []);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRefNumber(): string
    {
        return Arr::get($this->rawResponse, 'data.referenceNumber');
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverCardNumber(): string
    {
        return Arr::get($this->rawResponse, 'data.maskedCardNumber');
    }

    /**
     * {@inheritdoc}
     */
    protected function getRequiredCallbackKeys(): array
    {
        return ['status', 'invoiceId'];
    }

    /**
     * Generate unique order ID
     */
    private function generateInvoice(): string
    {
        $this->transactionId = $this->generateUniqueTimeBaseNumber();

        return $this->transactionId;
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
     * Convert API method to full endpoint URL.
     */
    private function toApiUrl(string $method): string
    {
        return self::GATEWAY_API_BASE_PATH."/{$method}";
    }

    /**
     * Call the gateway's API with the given method and data.
     *
     * @param  array<string,mixed>|Arrayable<string,mixed>  $data
     */
    private function execute(string $url, array|Arrayable $data, bool $requireAuth = true): void
    {
        $this->guardAgainstSandbox();

        $token = $requireAuth ? $this->resolveToken() : null;

        if ($requireAuth && ! $token) {
            return;
        }

        $response = Http::baseUrl($this->toHttps($this->baseUrl))
            ->withToken($token)
            ->withHeader('Referer', URL::current())
            ->post($url, $data)
            ->throwIfServerError();

        $this->rawResponse = $response->json();

        $this->apiStatusCode = (int) Arr::get($this->rawResponse, 'resultCode');
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
     * Resolve and return a valid token
     */
    private function resolveToken(): ?string
    {
        return Cache::lock(self::CACHE_KEY, 5)
            ->block(2, fn (): ?string => Cache::remember(self::CACHE_KEY, 9 * 60, function (): ?string {
                $data = [
                    'username' => $this->username,
                    'password' => $this->password,
                ];

                $this->execute(self::GATEWAY_GET_TOKEN, $data, requireAuth: false);

                return Arr::get($this->rawResponse, 'token');
            }));
    }

    /**
     * Ensure the given URL uses HTTPS scheme.
     */
    private function toHttps(string $url): string
    {
        return (string) Str::of($url)
            ->chopStart('http://')
            ->chopStart('https://')
            ->prepend('https://');
    }

    /**
     * Determine whether the payment failed based on the callback.
     */
    private function isFailedPaymentBasedOnCallback(): bool
    {
        return $this->callbackPayload->get('status') === 'failed';
    }

    /**
     * Set payment status by callback.
     */
    private function setFailedPaymentBasedOnCallback(): void
    {
        $this->apiStatusCode = 1;
        $this->rawResponse = $this->callbackPayload->all();
    }

    /**
     * Validate if the paid amount matches the creation amount.
     *
     * @param  array<string,mixed>  $storedPayload
     */
    private function validateVerifiedAmount(array $storedPayload): void
    {
        $isAmountValid = Arr::get($storedPayload, 'amount') === Arr::get($this->rawResponse, 'data.amount');

        if (! $isAmountValid) {
            $this->apiStatusCode = 1010;
        }
    }
}
