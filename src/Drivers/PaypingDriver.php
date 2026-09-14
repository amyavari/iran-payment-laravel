<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Drivers;

use AliYavari\IranPayment\Abstracts\Driver;
use AliYavari\IranPayment\Concerns\DoesNotSupportSandbox;
use AliYavari\IranPayment\Concerns\FailsWithoutCallback;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Enums\ApiMethod;
use AliYavari\IranPayment\Enums\InternalErrorCode;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Override;

/**
 * @internal
 *
 * @see https://docs.payping.ir/
 */
final class PaypingDriver extends Driver
{
    use DoesNotSupportSandbox;
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
     * Determine whether the last API call was successful.
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

    /**
     * URL of the payment page returned by the gateway.
     */
    private string $paymentUrl;

    /**
     * Determine whether the last API call reported the payment as already verified.
     */
    private bool $alreadyVerified = false;

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
            ->when($phone, fn (Collection $data) => $data->merge(['payerIdentity' => $this->toLocalPhone($phone)]));

        $this->execute('pay', $data);

        if ($this->apiIsSuccessful) {
            $this->setTransactionId();
            $this->setPaymentUrl();
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
        return $this->getInvalidErrorCodeMessage()
            ?? InternalErrorCode::getMessage($this->apiStatusCode)
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
            $this->setPaymentStatusForNoCallback(ApiMethod::Verify);

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
            'paymentRefId' => (int) $this->callbackPayload->dot()->get('data.paymentRefId'),
            'paymentCode' => $this->callbackPayload->dot()->get('data.paymentCode'),
            'amount' => (int) Arr::get($storedPayload, 'amount'),
        ];

        $this->execute('pay/verify', $data);

        if ($this->apiIsSuccessful) {
            $this->validateVerifiedAmount($storedPayload);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function reversePayment(): void
    {
        if ($this->isWithoutCallback()) {
            $this->setPaymentStatusForNoCallback(ApiMethod::Reverse);

            return;
        }

        $data = [
            'paymentRefId' => (int) $this->callbackPayload->dot()->get('data.paymentRefId'),
            'paymentCode' => $this->callbackPayload->dot()->get('data.paymentCode'),
        ];

        $this->execute('pay/reverse', $data);

        if ($this->apiIsSuccessful) {
            // The reversal reply carries no status field, so this read is the only proof
            // that the gateway answered with a real reversal receipt.
            $this->asInt($this->rawResponse, 'paymentRefId');
        }
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
        return new PaymentRedirectDto($this->paymentUrl, 'GET', payload: []);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverRefNumber(): string
    {
        $key = $this->alreadyVerified ? 'metaData.message.PaymentRefId' : 'paymentRefId';

        return (string) Arr::get($this->rawResponse, $key);
    }

    /**
     * {@inheritdoc}
     */
    protected function getDriverCardNumber(): string
    {
        $key = $this->alreadyVerified ? 'metaData.message.CardNumber' : 'cardNumber';

        return Arr::get($this->rawResponse, $key);
    }

    /**
     * {@inheritdoc}
     */
    protected function getRequiredCallbackKeys(): array
    {
        return ['status', 'errorCode', 'data.paymentCode'];
    }

    /**
     * {@inheritdoc}
     */
    #[Override]
    protected function getNullableCallbackKeys(): array
    {
        return ['errorCode'];
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
     * Parse the API response.
     */
    private function parseResponse(Response $response): void
    {
        $this->rawResponse = $this->decodeResponse($response);

        $isHttpSuccessful = $response->status() === 200;

        $this->apiStatusCode = ! $isHttpSuccessful
            ? $this->asErrorCode($this->rawResponse, 'metaData.code')
            : 0; // Just to fill the place!

        $this->alreadyVerified = $this->isAlreadyVerified($response);

        $this->apiIsSuccessful = $isHttpSuccessful || $this->alreadyVerified;
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
     * Parse the creation API response and set the transaction ID.
     */
    private function setTransactionId(): void
    {
        $this->transactionId = $this->asString($this->rawResponse, 'paymentCode');
    }

    /**
     * Set the URL of the payment page.
     */
    private function setPaymentUrl(): void
    {
        $this->paymentUrl = $this->asString($this->rawResponse, 'url');
    }

    /**
     * Validate if the paid amount matches the creation amount.
     *
     * @param  array<string,mixed>  $storedPayload
     */
    private function validateVerifiedAmount(array $storedPayload): void
    {
        $key = $this->alreadyVerified ? 'metaData.message.Amount' : 'amount';

        $this->apiIsSuccessful = (int) Arr::get($storedPayload, 'amount') === $this->asInt($this->rawResponse, $key);

        if (! $this->apiIsSuccessful) {
            $this->apiStatusCode = InternalErrorCode::InvalidAmount->value;
        }
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
        return $this->asInt($this->callbackPayload->all(), 'status') !== 1;
    }

    /**
     * Set payment status by callback.
     */
    private function setPaymentStatusBasedOnCallback(): void
    {
        $this->apiIsSuccessful = false;

        $this->rawResponse = $this->callbackPayload->all();

        $this->apiStatusCode = $this->asErrorCode($this->rawResponse, 'errorCode');
    }

    /**
     * Set the payment status when the gateway is called without callback data.
     */
    private function setPaymentStatusForNoCallback(ApiMethod $method): void
    {
        $this->apiStatusCode = $this->withoutCallbackStatusCode($method);
        $this->apiIsSuccessful = $this->isWithoutCallbackSuccessful($this->apiStatusCode);
        $this->rawResponse = $this->withoutCallbackRawResponse();
    }
}
