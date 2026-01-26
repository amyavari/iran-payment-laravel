<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Drivers;

use AliYavari\IranPayment\Abstracts\Driver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Facades\Soap;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;

/**
 * @internal
 *
 * Based on version 1.38 of IPG documentation.
 */
final class BehpardakhtDriver extends Driver
{
    private const string GATEWAY_WSDL_URL = 'https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl';

    private const string PAYMENT_REDIRECT_URL = 'https://bpm.shaparak.ir/pgwchannel/startpay.mellat';

    private const string SANDBOX_GATEWAY_WSDL_URL = 'https://pgw.dev.bpmellat.ir/pgwchannel/services/pgw?wsdl';

    private const string SANDBOX_PAYMENT_REDIRECT_URL = 'https://pgw.dev.bpmellat.ir/pgwchannel/startpay.mellat';

    private string $response;

    private ?string $orderId = null;

    private string $apiStatusCode;

    private int $amount;

    private string $refId;

    /**
     * @var array<string,string>
     */
    private array $metadata;

    public function __construct(
        private readonly string $terminalId,
        private readonly string $username,
        private readonly string $password,
        private readonly string $callbackUrl,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function getTransactionId(): ?string
    {
        return $this->orderId;
    }

    /**
     * {@inheritdoc}
     */
    public function getGatewayPayload(): ?array
    {
        return $this->whenSuccessful(fn (): array => [
            'orderId' => $this->orderId,
            'amount' => $this->amount,
            'refId' => $this->refId,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getPaymentRedirectData(): ?PaymentRedirectDto
    {
        $payload = collect([
            'RefId' => $this->refId,
        ])
            ->merge($this->metadata)
            ->mapWithKeys(fn (string $value, string $key): array => [(string) Str::of($key)->studly() => $value])
            ->all();

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Referer' => URL::current(),
        ];

        return $this->whenSuccessful(fn (): PaymentRedirectDto => new PaymentRedirectDto($this->getGaymentRedirectUrl(), 'POST', $payload, $headers));
    }

    /**
     * {@inheritdoc}
     */
    protected function isSuccessful(): bool
    {
        return $this->apiStatusCode === '0';
    }

    /**
     * {@inheritdoc}
     */
    protected function getGatewayRawResponse(): string
    {
        return $this->response;
    }

    protected function getGatewayStatusCode(): string
    {
        return $this->apiStatusCode;
    }

    /**
     * {@inheritdoc}
     */
    protected function getGatewayStatusMessage(): string
    {
        return match ($this->apiStatusCode) {
            '0' => 'تراکنش با موفقیت انجام شد',
            '11' => 'شماره کارت نامعتبر است',
            '12' => 'موجودی کافی نیست',
            '13' => 'رمز نادرست است',
            '14' => 'تعداد دفعات وارد کردن رمز بیش از حد مجاز است',
            '15' => 'کارت نامعتبر است',
            '16' => 'دفعات برداشت وجه بیش از حد مجاز است',
            '17' => 'کاربر از انجام تراکنش منصرف شده است',
            '18' => 'تاریخ انقضای کارت گذشته است',
            '19' => 'مبلغ برداشت وجه بیش از حد مجاز است',
            '20' => 'عدم ارسال پارامترهای احراز هویت مشتری توسط پذیرنده',
            '23' => 'خطای امنیتی رخ داده است',
            '32' => 'فرمت اطلاعات ورودی صحیح نیست',
            '21' => 'پذیرنده نامعتبر است',
            '22' => 'ترمینال نامعتبر است',
            '24' => 'اطلاعات کاربری پذیرنده نامعتبر است',
            '29' => 'آدرس بازگشت (CallBackUrl) نامعتبر است',
            '25' => 'مبلغ نامعتبر است',
            '26' => 'شماره مرجع تراکنش نامعتبر است',
            '27' => 'شماره درخواست تکراری است',
            '28' => 'شماره درخواست یافت نشد',
            '30' => 'تراکنش قبلاً با موفقیت انجام شده است',
            '31' => 'پاسخ نامعتبر است',
            '33' => 'حساب نامعتبر است',
            '34' => 'خطای سیستمی',
            '35' => 'تراکنش ناموفق',
            '36' => 'تراکنش قبلاً برگشت داده شده است',
            '37' => 'تراکنش در حال انجام است',
            '38' => 'مدت زمان مجاز انجام تراکنش به پایان رسیده است',
            '39' => 'خطا در انجام عملیات',
            '40' => 'تراکنش مورد نظر یافت نشد',
            '41' => 'تراکنش قبلاً تأیید (Verify) شده است',
            '42' => 'تراکنش قبلاً تسویه (Settle) شده است',
            '43' => 'امکان تسویه تراکنش وجود ندارد',
            '44' => 'امکان برگشت تراکنش وجود ندارد',
            '45' => 'تراکنش قبلاً برگشت داده شده است',
            '46' => 'تراکنش تسویه نشده است',
            '47' => 'خطا در انجام عملیات تسویه',
            '48' => 'خطا در انجام عملیات تأیید',
            '49' => 'خطا در انجام عملیات برگشت',
            '50' => 'خطای داخلی سیستم',
            '51' => 'تراکنش نامعتبر است',
            '52' => 'اطلاعات پرداخت ناقص است',
            '53' => 'پاسخ بانک نامعتبر است',
            '54' => 'خطا در ارتباط با بانک',
            '55' => 'عدم تطابق اطلاعات تراکنش',
            '56' => 'خطا در پردازش اطلاعات',
            '57' => 'پرداخت توسط کاربر لغو شد',
            '58' => 'عدم تطابق RefId',
            '59' => 'عدم تطابق SaleOrderId',
            '60' => 'خطای ناشناخته',
            '110' => 'کالا مشمول محدودیت سامانه مکنا می‌باشد',
            '111' => 'کد کالای ارسالی نامعتبر است',
            '112' => 'تعداد کالای ارسالی بیش از حد مجاز است',
            '113' => 'خطا در بررسی اطلاعات کالای ایرانی',
            '412' => 'خطا در ارتباط با شاپرک',
            '413' => 'خطای زمان انتظار (Timeout)',
            '414' => 'پاسخ نامعتبر از شاپرک',
            '415' => 'خطای پردازش در شاپرک',
            '416' => 'تراکنش توسط شاپرک رد شد',
            '417' => 'خطای امنیتی در شاپرک',
            '418' => 'عدم تطابق اطلاعات در شاپرک',
            '419' => 'خطای ناشناخته در شاپرک',
            '421' => 'IP سرور پذیرنده پیشتر به سامانه اعلام نشده است',
            '995' => 'خطای سیستمی (Internal Error)',
            '997' => 'سامانه مقصد غیر فعال می‌باشد',

            default => 'کد پاسخ نامشخص'
        };
    }

    /**
     * {@inheritdoc}
     */
    protected function createPayment(string $callbackUrl, int $amount, ?string $description = null, string|int|null $phone = null): void
    {
        $this->amount = $amount;

        $this->setPaymentMetadata($description, (string) $phone);

        $now = now()->tz('Asia/Tehran');

        $data = collect([
            'terminalId' => (int) $this->terminalId,
            'userName' => $this->username,
            'userPassword' => $this->password,
            'orderId' => $this->generateOrderId(),
            'amount' => $this->amount,
            'localDate' => $now->format('Ymd'),
            'localTime' => $now->format('His'),
            'additionalData' => $description ?? '',
            'callBackUrl' => $callbackUrl,
            'payerId' => 0,
        ])
            ->merge($this->metadata);

        $this->execute('bpPayRequest', $data->all());

        $this->setRefId();
    }

    /**
     * {@inheritdoc}
     */
    protected function driverCallbackUrl(): string
    {
        return $this->callbackUrl;
    }

    /**
     * Generate unique order ID
     */
    private function generateOrderId(): int
    {
        $this->orderId = $this->generateUniqueTimeBaseNumber();

        return (int) $this->orderId;
    }

    /**
     * Convert the phone number to the format expected by the gateway.
     */
    private function toDriverPhone(string $phone): string
    {
        return (string) Str::of($phone)
            ->whenStartsWith('09', fn (Stringable $phone) => $phone->replaceFirst('0', '98'))
            ->whenStartsWith('+98', fn (Stringable $phone) => $phone->replaceFirst('+98', '98'))
            ->whenStartsWith('9809', fn (Stringable $phone) => $phone->replaceFirst('9809', '989'))
            ->when(
                fn (Stringable $phone): bool => $phone->startsWith('9') && $phone->doesntStartWith('98'),
                fn (Stringable $phone) => $phone->prepend('98')
            );
    }

    /**
     * Call the gateway's API with the given method and data.
     *
     * @param  array<string,mixed>  $data
     */
    private function execute(string $method, array $data): void
    {
        $this->response = Soap::to($this->getGatewayWsdlUrl())->call($method, $data);

        $this->setApiStatusCode();
    }

    /**
     * Pars the API response and set the status code.
     */
    private function setApiStatusCode(): void
    {
        $this->apiStatusCode = (string) Str::of($this->response)->before(',');
    }

    /**
     * Set the reference ID required to redirect the user to the payment page.
     */
    private function setRefId(): void
    {
        $this->refId = (string) Str::of($this->response)->after(',');
    }

    /**
     * Set payment metadata
     */
    private function setPaymentMetadata(?string $description, ?string $phone): void
    {
        $this->metadata = collect([])
            ->when($phone, fn (Collection $data) => $data->merge(['mobileNo' => $this->toDriverPhone($phone)]))
            ->when($description, fn (Collection $data) => $data->merge(['cartItem' => $description]))
            ->all();
    }

    /**
     * Get the gateway WSDL URL based on the configuration.
     */
    private function getGatewayWsdlUrl(): string
    {
        return $this->useSandbox() ? self::SANDBOX_GATEWAY_WSDL_URL : self::GATEWAY_WSDL_URL;
    }

    /**
     * Get the gateway redirect URL based on the configuration.
     */
    private function getGaymentRedirectUrl(): string
    {
        return $this->useSandbox() ? self::SANDBOX_PAYMENT_REDIRECT_URL : self::PAYMENT_REDIRECT_URL;
    }
}
