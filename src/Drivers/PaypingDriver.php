<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Drivers;

use AliYavari\IranPayment\Abstracts\Driver;
use AliYavari\IranPayment\Concerns\FailsWithoutCallback;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Enums\InternalErrorCode;
use AliYavari\IranPayment\Exceptions\SandboxNotSupportedException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Pest\Support\Arr;

/**
 * @internal
 *
 * @see https://docs.payping.ir/
 */
final class PaypingDriver extends Driver
{
    use FailsWithoutCallback;

    /**
     * Base URL of the payment gateway.
     */
    private const string GATEWAY_BASE_URL = 'https://api.payping.ir/v3';

    /**
     * Status code returned by the last API call.
     */
    private int $apiStatusCode;

    /**
     * Determine whether te last API call was successful.
     */
    private bool $apiIsSuccessful;

    /**
     * Raw response from the last API call.
     *
     * @var array<string,mixed>|string
     */
    private array|string $rawResponse;

    /**
     * Transaction ID
     */
    private ?string $transactionId = null;

    /**
     * Amount of the payment in Rial.
     */
    private int $amount;

    public function __construct(
        private readonly string $callbackUrl,
        private readonly string $token,
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
            'amount' => $amount,
            'returnUrl' => $callbackUrl,
            'isReversible' => true,
        ])
            ->when($description, fn (Collection $data) => $data->merge(['description' => (string) $description]))
            ->when($phone, fn (Collection $data) => $data->merge(['payerIdentity' => $this->toDriverPhone($phone)]));

        $this->execute('pay', $data);

        if ($this->apiIsSuccessful) {
            $this->transactionId = Arr::get($this->rawResponse, 'paymentCode');
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
        return $this->apiIsSuccessful;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRawResponse(): array|string
    {
        return $this->rawResponse;
    }

    /**
     * {@inheritdoc}
     */
    protected function verifyPayment(array $storedPayload): void
    {
        if ($this->isWithoutCallback()) {
            $this->setPaymentStatusForNoCallback('verify');

            return;
        }

        if ($this->isFailedPaymentBasedOnCallback()) {
            $this->setPaymentStatusBasedOnCallback();

            return;
        }

        $keyMapper = [
            'data.paymentCode' => 'payment_code',
        ];

        $this->ensureCallbackDataMatchesPayload($storedPayload, $keyMapper);

        $data = [
            'paymentRefId' => $this->callbackPayload->dot()->get('data.paymentRefId'),
            'paymentCode' => $this->callbackPayload->dot()->get('data.paymentCode'),
            'amount' => Arr::get($storedPayload, 'amount'),
        ];

        $this->execute('pay/verify', $data);
    }

    /**
     * {@inheritdoc}
     */
    protected function reversePayment(): void
    {
        if ($this->isWithoutCallback()) {
            $this->setPaymentStatusForNoCallback('reverse');

            return;
        }

        $data = [
            'paymentRefId' => $this->callbackPayload->dot()->get('data.paymentRefId'),
            'paymentCode' => $this->callbackPayload->dot()->get('data.paymentCode'),
        ];

        $this->execute('pay/reverse', $data);
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareFromCallback(): void
    {
        $this->transactionId = $this->callbackPayload->dot()->get('data.paymentCode');
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareWithoutCallback(string $transactionId): void
    {
        $this->transactionId = $transactionId;

        $this->enableWithoutCallback();
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
            'payment_code' => $this->transactionId,
            'amount' => $this->amount,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRedirectData(): PaymentRedirectDto
    {
        $url = Arr::get($this->rawResponse, 'url');

        return new PaymentRedirectDto($url, 'GET', payload: []);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRefNumber(): string
    {
        return (string) Arr::get($this->rawResponse, 'paymentRefId');
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
        return ['status', 'errorCode', 'data.paymentCode'];
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

        $response = Http::baseUrl(self::GATEWAY_BASE_URL)
            ->withToken($this->token)
            ->post($method, $data)
            ->throwIfServerError();

        $this->parseResponse($response);
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
     * Parse the API response.
     */
    private function parseResponse(Response $response): void
    {
        $this->rawResponse = $response->json();

        $this->apiStatusCode = (int) Arr::get($this->rawResponse, 'metaData.code');

        $this->apiIsSuccessful = $response->status() === 200
                              || $this->isAlreadyVerified($response);
    }

    /**
     * Determine whether the payment has already been verified in a previous request.
     */
    private function isAlreadyVerified(Response $response): bool
    {
        return $response->status() === 409
            && $this->apiStatusCode === 110;
    }

    /**
     * Get the error message returned by the gateway.
     */
    private function getGatewayMessage(): string
    {
        return match ($this->apiStatusCode) {
            101 => 'داده های ارسالی نامعتبر است',
            102 => 'درگاه پرداخت فعال برای پذیرنده یافت نشد',
            103 => 'توکن احراز هویت پذیرنده تایید نشده است',
            104 => 'مبلغ برای مشتری آزمایشی معتبر نیست (دقت داشته باشید توکن احراز هویت تست دارای محدودیت در مبلغ پرداخت می‌باشد)',
            105 => 'آدرس بازگشت پذیرنده معتبر نمی‌باشد',
            106 => 'خطای داخلی سرویس رخ داده است',
            107 => 'براساس داده های ارسالی قوانین مورد انتظار رعایت نشده است',
            108 => 'خطای داخلی سرور رخ داده است',
            109 => 'پرداخت در حال بررسی می‌باشد',
            110 => 'پرداخت قبلاً انجام شده است',
            111 => 'حساب کاربری پذیرنده مسدود می‌باشد',
            112 => 'سقف تراکنش مشتری آزمایشی به اتمام رسیده است',
            113 => 'شماره تلفن همراه معتبر نمی‌باشد',
            114 => 'خطای درگاه بانکی رخ داده است',
            115 => 'اطلاعات ارسالی از بانک تکراری می‌باشد',
            116, 118 => 'حساب های کاربری در تسهیم معتبر نمی‌باشند',
            117 => 'شماره شبای تکراری در تسهیم وجود دارد',
            121 => 'تایید تراکنش نیازمند پرداخت موفق می‌باشد',
            122 => 'داده‌های ارسالی نامعتبر است. پرداخت یافت نشد',
            123 => 'تراکنش قبلا تایید شده است',
            124 => 'تراکنش با این نسخه از سیستم سازگار نیست',
            126 => 'درخواست با وضعیت فعلی اطلاعات در تعارض است',
            127 => 'خطایی در انجام عملیات در درگاه رخ داده است (کاربر عملیات پرداخت را لغو کرده یا زمان مجاز انجام تراکنش به اتمام رسیده است)',
            128 => 'این پرداخت بلاک نمی‌باشد',
            129 => 'وضعیت تراکنش اجازه حذف آن را نمی‌دهد (در صورتیکه تراکنش حداقل یکبار به درگاه پرداخت منتقل شده باشد، امکان حذف آن وجود نخواهد داشت)',
            130 => 'در حال حاضر امکان پردازش این کد پرداخت وجود ندارد',
            131 => 'تراکنش شما در وضعیت نامعتبر قرار دارد و امکان ادامه فرآیند پرداخت وجود ندارد',
            132 => 'شما مجاز به انجام این تغییر وضعیت نیستید',
            133 => 'اطلاعات پرداخت نامعتبر می‌باشد',
            134 => 'این تراکنش قابلیت بازگشت وجه ندارد',
            135, 136 => 'مهلت انجام عملیات بازگشت وجه به پایان رسیده است',
            137 => 'مبلغ تراکنش اشتباه می‌باشد',
            155 => 'عملیات تایید تراکنش در حال پردازش است. لطفا مجددا تلاش نمایید',
            156 => 'عملیات بازگشت وجه تراکنش در حال پردازش است. لطفا مجددا تلاش نمایید',

            default => 'کد پاسخ نامشخص',
        };
    }

    /**
     * Determine whether the payment failed based on the callback.
     */
    private function isFailedPaymentBasedOnCallback(): bool
    {
        return $this->callbackPayload->get('status') !== 1;
    }

    /**
     * Set payment status by callback.
     */
    private function setPaymentStatusBasedOnCallback(): void
    {
        $this->apiIsSuccessful = false;

        $this->apiStatusCode = $this->callbackPayload->get('errorCode');
        $this->rawResponse = $this->callbackPayload->all();
    }

    /**
     * Set the payment status when the gateway is called without callback data.
     */
    private function setPaymentStatusForNoCallback(string $method): void
    {
        $this->apiStatusCode = $this->withoutCallbackStatusCode($method);
        $this->apiIsSuccessful = $this->isWithoutCallbackSuccessful($this->apiStatusCode);
        $this->rawResponse = $this->withoutCallbackRawResponse();
    }
}
