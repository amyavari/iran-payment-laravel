# پرداخت با درگاه‌های پرداخت ایرانی برای لاراول

## پیش‌نیازها

- نسخه php `8.3` یا بالاتر
- نسخه Laravel `^11.44`، `^12.23` یا `^13.0`

## فهرست درگاه‌های پرداخت

| نسخه  | کلید درگاه    | وب‌سایت درگاه     | نام فارسی درگاه           | نام انگلیسی درگاه |
| ----- | ------------- | ----------------- | ------------------------- | ----------------- |
| 1.0.0 | `behpardakht` | [behpardakht.com] | به پرداخت ملت             | Behpardakht       |
| 1.0.0 | `sep`         | [sep.ir]          | سامان کیش (سپ)            | Sep               |
| 1.0.0 | `zarinpal`    | [zarinpal.com]    | زرین پال                  | Zarinpal          |
| 1.0.0 | `idpay`       | [idpay.ir]        | آی دی پی                  | IDPay             |
| 1.1.0 | `pep`         | [pep.co.ir]       | پرداخت الکترونیک پاسارگاد | Pep               |
| 1.1.0 | `sadad`       | [sadadpsp.ir]     | سداد                      | Sadad             |
| 2.0.0 | `zibal`       | [zibal.ir]        | زیبال                     | Zibal             |
| 2.0.0 | `payping`     | [payping.ir]      | پی پینگ                   | PayPing           |
| 2.0.0 | `nextpay`     | [nextpay.org]     | نکست پی                   | NextPay           |

> [!CAUTION]
> هر درگاه قوانین خاص خود را برای تراکنش‌های در انتظار تایید و بازگشت وجه (Reverse) دارد. لطفاً فایل [gateways_note_fa.md](./gateways_note_fa.md) را بررسی کنید.

## منو

- [نصب](#نصب)
- [انتشار فایل‌های Vendor](#انتشار-فایلهای-vendor)
- [پیکربندی](#پیکربندی)
- [استفاده](#استفاده)
  - [ایجاد پرداخت](#ایجاد-پرداخت)
  - [بررسی وضعیت فراخوانی API](#بررسی-وضعیت-فراخوانی-api)
  - [ذخیره‌سازی اطلاعات پرداخت](#ذخیرهسازی-اطلاعات-پرداخت)
    - [ذخیره‌سازی خودکار](#ذخیرهسازی-خودکار)
    - [ذخیره‌سازی دستی](#ذخیرهسازی-دستی)
  - [انتقال کاربر به صفحه پرداخت](#انتقال-کاربر-به-صفحه-پرداخت)
  - [تایید (Verification)](#تایید-verification)
    - [تایید و بازگشت وجه (Reverse)](#تایید-و-بازگشت-وجه-reverse)
    - [جزئیات پرداخت موفق](#جزئیات-پرداخت-موفق)
    - [کلاس‌های Form Request](#کلاسهای-form-request)
    - [تایید بدون Callback](#تایید-بدون-callback)
- [تست](#تست)

## نصب

برای نصب بسته از طریق Composer دستور زیر را اجرا کنید:

```bash
composer require amyavari/iran-payment-laravel
```

## انتشار فایل‌های Vendor

### انتشار همه فایل‌ها

برای انتشار تمام فایل‌های vendor (پیکربندی و دیتابیس):

```bash
php artisan iran-payment:install
```

**نکته:** برای ساخت جدول‌ها از طریق فایل‌های دیتابیس:

```bash
php artisan migrate
```

### انتشار فایل‌های خاص

برای انتشار فقط فایل پیکربندی:

```bash
php artisan vendor:publish --tag=iran-payment-config
```

برای انتشار فقط فایل دیتابیس:

```bash
php artisan vendor:publish --tag=iran-payment-migrations
```

**نکته:** برای ساخت جدول‌ها از طریق فایل‌های دیتابیس:

```bash
php artisan migrate
```

## پیکربندی

برای تنظیم درگاه‌های پرداخت، موارد زیر را به فایل `.env` اضافه کنید:

```env
# درگاه پیش‌فرض
PAYMENT_GATEWAY=<default_gateway>

# واحد پول پیش‌فرض برنامه
APP_CURRENCY=<Toman or Rial>

# آیا از محیط تست به جای درگاه واقعی استفاده شود
PAYMENT_USE_SANDBOX=<true or false>

# تنظیمات مربوط به هر درگاه (آدرس بازگشت و اطلاعات احراز هویت)
# بخش "gateways" در فایل config/iran-payment.php را ببینید
```

**نکات:**

- برای `PAYMENT_GATEWAY` از ستون `کلید درگاه` در [فهرست درگاه‌های پرداخت](#فهرست-درگاههای-پرداخت) استفاده کنید.
- برای تنظیم آدرس بازگشت و اطلاعات احراز هویت هر درگاه، کلیدهای مورد نیاز در بخش `gateways` فایل [config/iran-payment.php](./config/iran-payment.php) را تعریف کنید.

## استفاده

### ایجاد پرداخت

برای ایجاد یک پرداخت جدید می‌توانید از Facade ارائه‌شده توسط این پکیج استفاده کنید:

```php
use AliYavari\IranPayment\Facades\Payment;

// با استفاده از درگاه پیش‌فرض (استفاده از آدرس بازگشت از پیکربندی)
$payment = Payment::create(int $amount, ?string $description = null, ?string|int $phone = null);

// با استفاده از درگاه پیش‌فرض (تعیین آدرس بازگشت در زمان اجرا)
$payment = Payment::callbackUrl(string $callbackUrl)->create(...);

// با استفاده از درگاه خاص (استفاده از آدرس بازگشت از پیکربندی)
$payment = Payment::gateway(string $gateway)->create(...);

// با استفاده از درگاه خاص (تعیین آدرس بازگشت در زمان اجرا)
$payment = Payment::gateway(string $gateway)->callbackUrl(string $callbackUrl)->create(...);
```

**نکته:** برای `$gateway` از ستون کلید درگاه در [فهرست درگاه‌های پرداخت](#فهرست-درگاههای-پرداخت) استفاده کنید.

### بررسی وضعیت فراخوانی API

در تمامی فراخوانی‌های API یک درگاه (تمامی متدهای این پکیج)، می‌توانید آخرین وضعیت و پاسخ خام را با استفاده از متدهای زیر بررسی کنید:

```php
$payment->successful();     // bool
$payment->failed();         // bool

// دریافت پیام خطا (در صورت موفقیت null)
$payment->error();          // string|null

// دریافت پاسخ خام درگاه (مفید برای دیباگ)
$payment->getRawResponse(); // string|array
```

### ذخیره‌سازی اطلاعات پرداخت

#### ذخیره‌سازی خودکار

این پکیج می‌تواند پرداخت‌ها را به‌صورت خودکار ذخیره کرده و در فراخوانی‌های بعدی API مانند تایید (verification) یا بازگشت وجه (reversal) آن‌ها را همگام نگه دارد.

اگر ترجیح می‌دهید کنترل کاملی داشته باشید، از روش [ذخیره‌سازی دستی](#ذخیرهسازی-دستی) استفاده کنید.

با اضافه کردن متد `store()` قبل از فراخوانی `create()`، ذخیره‌سازی خودکار را فعال کنید:

```php
use AliYavari\IranPayment\Facades\Payment;

// ذخیره پرداخت و مرتبط کردن آن با یک مدل Eloquent قابل پرداخت
Payment::store(Model $payable)->create(...);

Payment::{other configurations}->store(Model $payable)->create(...);
```

**نکات:**

- برای ذخیره‌سازی خودکار، باید فایل‌های دیتابیس (migration) را منتشر و اجرا کنید. بخش [انتشار فایل‌های Vendor](#انتشار-فایلهای-vendor) را ببینید.
- در صورت عدم موفقیت در ایجاد پرداخت، هیچ رکوردی ذخیره نخواهد شد.
- پس از فعال‌سازی، پکیج به‌طور خودکار رکورد پرداخت را در فراخوانی‌های بعدی API به‌روز می‌کند.

##### دسترسی به پرداخت ذخیره‌شده

پس از ایجاد یک پرداخت و در طول هرگونه فراخوانی بعدی API مانند `verify()` یا `reverse()`، می‌توانید به مدل پرداخت ایجاد شده دسترسی پیدا کنید:

```php
$payment->getModel(); // \AliYavari\IranPayment\Models\Payment

// دسترسی به مدلی که پرداخت برای آن انجام شده است
$payment->getModel()->payable;
```

برای مشاهده تمام ویژگی‌های (attributes) در دسترس، فایل [`src/Models/Payment.php`](./src/Models/Payment.php) را بررسی کنید.

##### پیگیری پرداخت‌ها از طریق مدل Payable

هنگام استفاده از ذخیره‌سازی خودکار، trait با نام `AliYavari\IranPayment\Concerns\HasPayment` را به مدلی که پرداخت برای آن انجام می شود، اضافه کنید تا پرداخت‌های آن را پیگیری کنید:

```php
// مثال از مدل Course که پرداخت برای آن انجام می شود
namespace App\Models;

use AliYavari\IranPayment\Concerns\HasPayment;
use Illuminate\Database\Eloquent\Model;

final class Course extends Model
{
    use HasPayment;

        //

}

// MorphMany
$course->payments(); // AliYavari\IranPayment\Models\Payment
```

**نکته:** برای اطلاعات بیشتر در مورد این رابطه، [Eloquent relationships: one-to-many polymorphic] را ببینید.

##### کوئری گرفتن از پرداخت‌های ذخیره‌شده

مدل Payment چندین scope برای کوئری گرفتن از وضعیت‌های رایج پرداخت ارائه می‌دهد:

```php
use AliYavari\IranPayment\Models\Payment as PaymentModel;

// پرداخت‌های تاییدشده و موفق
PaymentModel::query()->successful()->...

// پرداخت‌های تاییدشده و ناموفق
PaymentModel::query()->failed()->...

// پرداخت‌های در انتظار تایید (تاییدنشده)
PaymentModel::query()->pending()->...

// از طریق یک مدل که پرداخت برای ان است با استفاده از HasPayment
$course->payments()->successful()->...
$course->payments()->failed()->...
$course->payments()->pending()->...
```

#### ذخیره‌سازی دستی

اگر می‌خواهید کنترل کاملی بر ذخیره‌سازی و پیگیری پرداخت‌ها داشته باشید، می‌توانید از این متدها استفاده کنید:

```php
// داده‌های مورد نیاز درگاه برای تایید پرداخت (در صورت ناموفق بودن ایجاد پرداخت null)
$payment->getGatewayPayload();  // array|null

// کلید درگاه
$payment->getGateway();         // string

// شناسه یکتای تراکنش که برای پیگیری در دیتابیس شما استفاده می‌شود (در صورت ناموفق بودن ایجاد پرداخت null)
$payment->getTransactionId();   // string|null
```

### انتقال کاربر به صفحه پرداخت

برای انتقال کاربر به صفحه پرداخت درگاه، از داده‌های ارائه‌شده توسط متد زیر استفاده کنید:

```php
$redirectData = $payment->getRedirectData(); // در صورت عدم موفقیت در ایجاد پرداخت null است

// آدرس انتقال
$redirectData->url;         // string

// متد انتقال (POST, GET)
$redirectData->method;      // string

// داده‌های انتقال (پارامترهای POST یا GET)
$redirectData->payload;     // array

// هدرهای HTTP مورد نیاز
$redirectData->headers;     // array

// دریافت تمام اطلاعات انتقال به صورت آرایه
$redirectData->toArray();   // array
```

### تایید (Verification)

#### تایید و بازگشت وجه (Reverse)

پس از اینکه کاربر از درگاه به برنامه شما بازگردانده شد، می‌توانید پرداخت را با استفاده از متدهای زیر تایید کنید:

**نکات:**

- پس از فراخوانی `verify()` یا `reverse()`،می‌توانید از متدهای بخش [بررسی وضعیت فراخوانی API](#بررسی-وضعیت-فراخوانی-api) برای بررسی نتیجه استفاده کنید.
- اگر پرداخت با استفاده از این پکیج در دیتابیس ذخیره شده باشد، این متدها به‌طور خودکار رکورد پرداخت را به‌روز می‌کنند. برای دسترسی به مدل پرداخت، بخش [ذخیره‌سازی خودکار](#ذخیرهسازی-خودکار) را ببینید.

```php
use AliYavari\IranPayment\Facades\Payment;

// ایجاد یک نمونه از درگاه از طریق داده‌های callback
$payment = Payment::gateway(string $gateway)->fromCallback(array $callbackPayload);
```

اگر از ذخیره‌سازی خودکار داخلی استفاده کرده‌اید:

```php
// فراخوانی verify بدون هیچ آرگومانی
$payment->verify();
```

اگر پرداخت را به‌صورت دستی ذخیره کرده‌اید:

```php
// برای پیدا کردن پرداخت در دیتابیس شما
$payment->getTransactionId();

// فراخوانی verify با استفاده از payload ذخیره‌شده درگاه
$payment->verify(array $gatewayPayload);
```

برای بازگشت وجه پرداخت:

```php
// بازگشت وجه پرداخت (در صورت عدم موفقیت تایید فراخوانی شود)
$payment->reverse();

// اجازه دهید پکیج در صورت نیاز بازگشت وجه را به‌صورت خودکار انجام دهد
$payment
->autoReverse(bool $autoReverse = true)
->verify(...); // ذخیره‌سازی دستی یا خودکار
```

**نکات:**

- برای دریافت `$callbackPayload`، این پکیج کلاس‌های پایه FormRequest را برای اعتبارسنجی داده‌های callback فراهم کرده است.
  این کلاس‌ها در مسیر `AliYavari\IranPayment\Requests\<Gateway>Request` قرار دارند. بخش [کلاس‌های Form Request](#کلاسهای-form-request) را ببینید.
- در صورت فعال بودن بازگشت وجه خودکار (auto-reverse)، [بررسی وضعیت فراخوانی API](#بررسی-وضعیت-فراخوانی-api) برای تایید (verification) اعمال می‌شود.

#### جزئیات پرداخت موفق

اگر پرداخت موفقیت‌آمیز باشد، متدهای زیر برای دریافت جزئیات بیشتر پرداخت در دسترس هستند:

```php
// دریافت شماره مرجع اختصاص داده شده به تراکنش توسط بانک. (در صورت ناموفق بودن تایید پرداخت null است)
$payment->getRefNumber();   // string|null

// دریافت شماره کارت کاربری که برای پرداخت استفاده شده است. (در صورت ناموفق بودن تایید پرداخت null است)
$payment->getCardNumber();  // string|null
```

#### کلاس‌های Form Request

برای اعتبارسنجی داده‌های callback از هر درگاه، این پکیج کلاس‌های FormRequest ساده‌ای را ارائه می‌دهد.
می‌توانید به این شکل از آن‌ها استفاده کنید (مثال با استفاده از درگاه sep):

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use AliYavari\IranPayment\Http\Requests\SepRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

final class SepVerificationController extends Controller
{
    public function update(SepRequest $request): RedirectResponse
    {
        $callbackData = $request->validated();

        // منطق تایید، تحویل محصول و غیره.
    }
}
```

کلاس‌های Form Request موجود:

```php
// Behpardakht
use AliYavari\IranPayment\Http\Requests\BehpardakhtRequest;

// Sep
use AliYavari\IranPayment\Http\Requests\SepRequest;

// Zarinpal
use AliYavari\IranPayment\Http\Requests\ZarinpalRequest;

// IDPay
use AliYavari\IranPayment\Http\Requests\IdpayRequest;

// Pep
use AliYavari\IranPayment\Http\Requests\PepRequest;

// Sadad
use AliYavari\IranPayment\Http\Requests\SadadRequest;

// Zibal
use AliYavari\IranPayment\Http\Requests\ZibalRequest;

// PayPing
use AliYavari\IranPayment\Http\Requests\PaypingRequest;

// NextPay
use AliYavari\IranPayment\Http\Requests\NextpayRequest;
```

#### تایید بدون Callback

گاهی اوقات کاربر پس از اتمام پرداخت به وب‌سایت شما باز نمی‌گردد. در این حالت، باید به جای `fromCallback()` از متد `noCallback()` استفاده کنید. سایر مراحل به همان شکل باقی می‌مانند.

```php
use AliYavari\IranPayment\Facades\Payment;

$payment = Payment::gateway(string $gateway)->noCallback(string $transactionId);
```

اگر از ذخیره‌سازی خودکار استفاده می‌کنید، می‌توانید مستقیماً آبجکت درگاه پرداخت را از طریق رکورد ذخیره‌شده پرداخت ایجاد کنید:

```php
$payment = $paymentModel->toGatewayPayment();
```

**نکته:** برخی درگاه‌ها (عمدتاً درگاه‌های مبتنی بر شاپرک) در صورت عدم دریافت callback، تراکنش را به‌طور خودکار برگشت می‌زنند، در حالی که برخی دیگر اجازه تایید بدون callback را می‌دهند. این پکیج قوانین هر درگاه را به‌صورت داخلی اعمال می‌کند.

## تست

برای توسعه لوکال (local) یا تست‌های خودکار، می‌توانید پاسخ‌های پرداخت را فیک (Fake) کنید تا هیچ تراکنش واقعی انجام نشود. این قابلیت به شما اجازه می‌دهد موفقیت، خطا و خطاهای ارتباطی را با خیال راحت تست کنید.

**نکته:** تمامی متدها از chain کردن پشتیبانی می‌کنند. مثال‌های زیر برای وضوح بیشتر به‌صورت فراخوانی‌های تکی آورده شده‌اند.

### فیک کردن درگاه

```php
use AliYavari\IranPayment\Facades\Payment;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;

// درگاه پیش‌فرض
$fake = Payment::fake();

// درگاه خاص
$fake = Payment::fake($gateway);
```

**نکته:** برای `$gateway` از ستون کلید درگاه در [فهرست درگاه های پرداخت](#فهرست-درگاه-های-پرداخت) استفاده کنید.

### تعریف رفتار فیک برای `create()`

```php
$fake->successfulCreate($rawResponse = 'Creation raw response', $gatewayPayload = ['payload' => 'test value'], ?PaymentRedirectDto $redirectData = null);

$fake->failedCreate($rawResponse = 'Creation raw response', $errorCode = 0, $errorMessage = 'Creation failed');

$fake->failedConnectionCreate($message = 'Creation connection failed');
```

**نکته:** اگر `$redirectData` برابر با `null` باشد، مقدار پیش‌فرض زیر برگردانده می‌شود:

```php
'url' => 'https://gateway.test',
'method' => 'POST',
'payload' => ['status' => 'successful'],
'headers' => ['X-IranPayment-Fake' => 'true'],
```

### تعریف رفتار فیک برای `verify()`

```php
$fake->successfulVerify($rawResponse = 'Verification raw response', $refNumber = '123456789', $cardNumber = '1234-****-****-1234');

$fake->failedVerify($rawResponse = 'Verification raw response', $errorCode = 0, $errorMessage = 'Verification failed');

$fake->failedConnectionVerify($message = 'Verification connection failed');
```

**نکته:** درگاه فیک هیچ اعتبارسنجی روی داده‌های درگاه یا اطلاعات callback انجام نمی‌دهد. شما فقط می‌توانید با متد زیر یک exception خطای callback نامعتبر را شبیه‌سازی کنید:

```php
$fake->invalidCallback($message = 'Invalid callback data');
```

### تعریف رفتار فیک برای `reverse()`

```php
$fake->successfulReverse($rawResponse = 'Reversal raw response');

$fake->failedReverse($rawResponse = 'Reversal raw response', $errorCode = 0, $errorMessage = 'Reversal failed');

$fake->failedConnectionReverse($message = 'Reverse connection failed');
```

**نکته:** اگر رفتار را برای یک درگاه و متد مشخص چندین بار تنظیم کنید، تنها آخرین تنظیم اعمال خواهد شد.

<!-- Links -->

[behpardakht.com]: https://behpardakht.com
[sep.ir]: https://sep.ir
[zarinpal.com]: https://zarinpal.com
[idpay.ir]: https://idpay.ir
[pep.co.ir]: https://pep.co.ir/
[sadadpsp.ir]: https://sadadpsp.ir/
[zibal.ir]: https://zibal.ir
[payping.ir]: https://payping.ir
[nextpay.org]: https://nextpay.org
[Eloquent relationships: one-to-many polymorphic]: https://laravel.com/docs/12.x/eloquent-relationships#one-to-many-polymorphic-relations
