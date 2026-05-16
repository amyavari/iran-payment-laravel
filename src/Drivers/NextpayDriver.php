<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Drivers;

use AliYavari\IranPayment\Abstracts\Driver;
use AliYavari\IranPayment\Contracts\UniqueNumberGenerator;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\SandboxNotSupportedException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * @internal
 *
 * @see https://nextpay.org/nx/docs
 */
final class NextpayDriver extends Driver
{
    /**
     * Base URL of the payment gateway.
     */
    private const string GATEWAY_BASE_URL = 'https://nextpay.org/nx/gateway';

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
     * Amount of the payment in Toman.
     */
    private int $amount;

    /**
     * Unique order ID generated for the payment.
     */
    private string $orderId;

    public function __construct(
        private readonly string $callbackUrl,
        private readonly string $apiKey,
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
        $this->amount = $amount / 10; // Toman

        $this->orderId = $this->uniqueNumber->generate();

        $data = collect([
            'order_id' => $this->orderId,
            'amount' => $this->amount,
            'callback_uri' => $callbackUrl,
        ])
            ->when($description, fn (Collection $data) => $data->merge(['payer_desc' => (string) $description]))
            ->when($phone, fn (Collection $data) => $data->merge(['customer_phone' => $this->toDriverPhone($phone)]));

        $this->execute('token', $data);

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
        return match ($this->apiStatusCode) {
            0 => 'پرداخت تکمیل و با موفقیت انجام شده است',
            -1 => 'منتظر ارسال تراکنش و ادامه پرداخت',
            -2 => 'پرداخت رد شده توسط کاربر یا بانک',
            -3 => 'پرداخت در حال انتظار جواب بانک',
            -4 => 'پرداخت لغو شده است',
            -20 => 'کد api_key ارسال نشده است',
            -21 => 'کد trans_id ارسال نشده است',
            -22 => 'مبلغ ارسال نشده',
            -23 => 'لینک ارسال نشده',
            -24 => 'مبلغ صحیح نیست',
            -25 => 'تراکنش قبلا انجام و قابل ارسال نیست',
            -26 => 'مقدار توکن ارسال نشده است',
            -27 => 'شماره سفارش صحیح نیست',
            -28 => 'مقدار فیلد سفارشی [custom_json_fields] از نوع json نیست',
            -29 => 'کد بازگشت مبلغ صحیح نیست',
            -30 => 'مبلغ کمتر از حداقل پرداختی است',
            -31 => 'صندوق کاربری موجود نیست',
            -32 => 'مسیر بازگشت صحیح نیست',
            -33 => 'کلید مجوز دهی صحیح نیست',
            -34 => 'کد تراکنش صحیح نیست',
            -35 => 'ساختار کلید مجوز دهی صحیح نیست',
            -36 => 'شماره سفارش ارسال نشد است',
            -37 => 'شماره تراکنش یافت نشد',
            -38 => 'توکن ارسالی موجود نیست',
            -39 => 'کلید مجوز دهی موجود نیست',
            -40 => 'کلید مجوزدهی مسدود شده است',
            -41 => 'خطا در دریافت پارامتر، شماره شناسایی صحت اعتبار که از بانک ارسال شده موجود نیست',
            -42 => 'سیستم پرداخت دچار مشکل شده است',
            -43 => 'درگاه پرداختی برای انجام درخواست یافت نشد',
            -44 => 'پاسخ دریاف شده از بانک نامعتبر است',
            -45 => 'سیستم پرداخت غیر فعال است',
            -46 => 'درخواست نامعتبر',
            -47 => 'کلید مجوز دهی یافت نشد [حذف شده]',
            -48 => 'نرخ کمیسیون تعیین نشده است',
            -49 => 'تراکنش مورد نظر تکراریست',
            -50 => 'حساب کاربری برای صندوق مالی یافت نشد',
            -51 => 'شناسه کاربری یافت نشد',
            -52 => 'حساب کاربری تایید نشده است',
            -60 => 'ایمیل صحیح نیست',
            -61 => 'کد ملی صحیح نیست',
            -62 => 'کد پستی صحیح نیست',
            -63 => 'آدرس پستی صحیح نیست و یا بیش از 150 کارکتر است',
            -64 => 'توضیحات صحیح نیست و یا بیش از 150 کارکتر است',
            -65 => 'نام و نام خانوادگی صحیح نیست و یا بیش از 35 کاکتر است',
            -66 => 'تلفن صحیح نیست',
            -67 => 'نام کاربری صحیح نیست یا بیش از 30 کارکتر است',
            -68 => 'نام محصول صحیح نیست و یا بیش از 30 کارکتر است',
            -69 => 'آدرس ارسالی برای بازگشت موفق صحیح نیست و یا بیش از 100 کارکتر است',
            -70 => 'آدرس ارسالی برای بازگشت ناموفق صحیح نیست و یا بیش از 100 کارکتر است',
            -71 => 'موبایل صحیح نیست',
            -72 => 'بانک پاسخگو نبوده است لطفا با نکست پی تماس بگیرید',
            -73 => 'مسیر بازگشت دارای خطا میباشد یا بسیار طولانیست',
            -90 => 'بازگشت مبلغ بدرستی انجام شد',
            -91 => 'عملیات ناموفق در بازگشت مبلغ',
            -92 => 'در عملیات بازگشت مبلغ خطا رخ داده است',
            -93 => 'موجودی صندوق کاربری برای بازگشت مبلغ کافی نیست',
            -94 => 'کلید بازگشت مبلغ یافت نشد',

            default => 'کد پاسخ نامشخص',
        };
    }

    /**
     * {@inheritdoc}
     */
    protected function isSuccessful(): bool
    {
        return in_array($this->apiStatusCode, [-1, 0, -90], true);
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
        $keyMapper = [
            'trans_id' => 'transaction_id',
        ];

        $this->ensureCallbackDataMatchesPayload($storedPayload, $keyMapper);

        $this->amount = Arr::get($storedPayload, 'amount'); // Required for payment reversal.

        $data = collect([
            'trans_id' => $this->transactionId,
            'amount' => $this->amount,
        ]);

        $this->execute('verify', $data);
    }

    /**
     * {@inheritdoc}
     */
    protected function reversePayment(): void
    {
        $data = collect([
            'trans_id' => $this->transactionId,
            'amount' => $this->amount,
            'refund_request' => 'yes_money_back',
        ]);

        $this->execute('verify', $data);
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareFromCallback(): void
    {
        $this->transactionId = $this->callbackPayload->get('trans_id');
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareWithoutCallback(string $transactionId): void
    {
        $this->transactionId = $transactionId;

        $this->callbackPayload = collect([
            'trans_id' => $this->transactionId,
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
            'order_id' => $this->orderId,
            'transaction_id' => $this->transactionId,
            'amount' => $this->amount,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRedirectData(): PaymentRedirectDto
    {
        $url = self::GATEWAY_BASE_URL."/payment/{$this->transactionId}";

        return new PaymentRedirectDto($url, 'GET', payload: []);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRefNumber(): string
    {
        return Arr::get($this->rawResponse, 'Shaparak_Ref_Id');
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverCardNumber(): string
    {
        return Arr::get($this->rawResponse, 'card_holder');
    }

    /**
     * {@inheritdoc}
     */
    protected function getRequiredCallbackKeys(): array
    {
        return ['trans_id'];
    }

    /**
     * {@inheritdoc}
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
     * @param  Collection<string,mixed>  $data
     */
    private function execute(string $method, Collection $data): void
    {
        $this->guardAgainstSandbox();

        $this->rawResponse = Http::baseUrl(self::GATEWAY_BASE_URL)
            ->post($method, $this->withCredentials($data))
            ->throwIfServerError()
            ->json();

        $this->setApiStatusCode();
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
     * Add credentials to the data based on the environment.
     *
     * @param  Collection<string,mixed>  $data
     * @return Collection<string,mixed>
     */
    private function withCredentials(Collection $data): Collection
    {
        return $data->merge([
            'api_key' => $this->apiKey,
        ]);
    }

    /**
     * Parse the API response and set the status code.
     */
    private function setApiStatusCode(): void
    {
        $this->apiStatusCode = Arr::get($this->rawResponse, 'code');
    }

    /**
     * Parse the creation API response and set the transaction ID.
     */
    private function setTransactionId(): void
    {
        $this->transactionId = Arr::get($this->rawResponse, 'trans_id');
    }
}
