# Iran Payment Laravel

<img src="https://banners.beyondco.de/Iran%20Payment%20Laravel.png?theme=dark&packageManager=composer+require&packageName=amyavari%2Firan-payment-laravel&pattern=architect&style=style_1&description=Pay+through+Iranian+payment+gateways+with+ease&md=1&showWatermark=1&fontSize=100px&images=https%3A%2F%2Flaravel.com%2Fimg%2Flogomark.min.svg">

![PHP Version](https://img.shields.io/packagist/php-v/amyavari/iran-payment-laravel)
![Laravel Version](https://img.shields.io/packagist/dependency-v/amyavari/iran-payment-laravel/illuminate%2Fcontracts?label=Laravel)
![Packagist Version](https://img.shields.io/packagist/v/amyavari/iran-payment-laravel?label=version)
![Packagist Downloads](https://img.shields.io/packagist/dt/amyavari/iran-payment-laravel)
![Packagist License](https://img.shields.io/packagist/l/amyavari/iran-payment-laravel)
![Tests](https://img.shields.io/github/actions/workflow/status/amyavari/iran-payment-laravel/tests.yml?label=tests)

A simple and convenient way to connect your app to Iranian payment providers.

To view the Persian documentation, please refer to [README_FA.md](./docs/README_FA.md).

برای مشاهده راهنمای فارسی، لطفاً به فایل [README_FA.md](./docs/README_FA.md) مراجعه کنید.

**THIS PACKAGE IS UNDER DEVELOPMENT, PLEASE DO NOT USE IT YET**

## Requirements

- PHP version `8.3` or higher
- Laravel `^11.44`, or `^12.23`

## List of Available Payment Gateways

| Gateway Name (EN) | Gateway Name (FA) | Gateway Website   | Gateway Key   | Version    |
| ----------------- | ----------------- | ----------------- | ------------- | ---------- |
| Behpardakht       | به پرداخت ملت     | [behpardakht.com] | `behpardakht` | Unreleased |
| Sep               | سامان کیش (سپ)    | [sep.ir]          | `sep`         | Unreleased |
| Zarinpal          | زرین پال          | [zarinpal.com]    | `zarinpal`    | Unreleased |
| IDPay             | آی دی پی          | [idpay.ir]        | `idpay`       | Unreleased |

> [!CAUTION]
> Gateways have different rules for pending verifications and settlements. Please check [gateways_note_en.md](./docs/gateways_note_en.md).

## Table of Contents

- [Installation](#installation)
- [Publish Vendor Files](#publish-vendor-files)
- [Configuration](#configuration)
- [Usage](#usage)
  - [Create a Payment](#create-a-payment)
  - [Checking API Call Status](#checking-api-call-status)
  - [Storing Payment Data](#storing-payment-data)
    - [Automatic Store](#automatic-store)
    - [Manual Store](#manual-store)
  - [Redirect User to Payment Page](#redirect-user-to-payment-page)
  - [Verification](#verification)
    - [Verify, Settle and Refund](#verify-settle-and-refund)
    - [Successful Payment Details](#successful-payment-details)
    - [Form Request Classes](#form-request-classes)
    - [Verification Without Callback](#verification-without-callback)
- [Testing](#testing)
- [Contributing](#contributing)

## Installation

To install the package via Composer, run:

```bash
composer require amyavari/iran-payment-laravel
```

## Publish Vendor Files

### Publish All Files

To publish all vendor files (config and migrations):

```bash
php artisan iran-payment:install
```

**Note:** To create tables from migrations:

```bash
php artisan migrate
```

### Publish Specific Files

To publish only the config file:

```bash
php artisan vendor:publish --tag=iran-payment-config
```

To publish only the migration file:

```bash
php artisan vendor:publish --tag=iran-payment-migrations
```

**Note:** To create tables from migrations:

```bash
php artisan migrate
```

## Configuration

To configure payment gateways, add the following to your `.env` file:

```env
# Default gateway
PAYMENT_GATEWAY=<default_gateway>

# Default application currency
APP_CURRENCY=<Toman or Rial>

# Whether to use sandbox mode instead of the real gateway
PAYMENT_USE_SANDBOX=<true or false>

# Per-gateway configuration (callback URL and credentials)
# See the "gateways" section in config/iran-payment.php
```

**Notes:**

- For the `PAYMENT_GATEWAY`, refer to the `gateway Key` column in the [List of Available Payment Gateways](#list-of-available-payment-gateways).
- For each gateway’s callback URL and credentials, define the required keys under your desired gateway(s) in the `gateways` section of [config/iran-payment.php](./config/iran-payment.php)

## Usage

### Create a Payment

You can create a new payment using the facade provided by the package:

```php
use AliYavari\IranPayment\Facades\Payment;

// Using the default gateway (uses the callback URL from config)
$payment = Payment::create(int $amount, ?string $description = null, ?string|int $phone = null);

// Using the default gateway (define the callback URL at runtime)
$payment = Payment::callbackUrl(string $callbackUrl)->create(...);

// Using a specific gateway (uses the callback URL from config)
$payment = Payment::gateway(string $gateway)->create(...);

// Using a specific gateway (define the callback URL at runtime)
$payment = Payment::gateway(string $gateway)->callbackUrl(string $callbackUrl)->create(...);
```

**Note:** For the `$gateway`, refer to the `gateway Key` column in the [List of Available Payment Gateways](#list-of-available-payment-gateways).

### Checking API Call Status

In all calls to a gateway’s API (all methods in this package), you can check the latest status and response using the following methods:

```php
$payment->successful();     // bool
$payment->failed();         // bool

// Get the error message (returns `null` if successful)
$payment->error();          // string|null

// Get the raw gateway response (useful for debugging)
$payment->getRawResponse(); // string|array
```

### Storing Payment Data

#### Automatic Store

The package can automatically store payments and keep them in sync during later API calls such as verification, settlement, or reversal.

If you prefer full control, [Manual Store](#manual-store) approach.

Enable automatic storage by chaining `store()` **before** calling `create()`:

```php
use AliYavari\IranPayment\Facades\Payment;

// Store the payment and associate it with a payable Eloquent model
Payment::store(Model $payable)->create(...);

Payment::{other configurations}->store(Model $payable)->create(...);
```

**Notes:**

- For automatic storage, you must publish and run the migration files. See [Publish Vendor Files](#publish-vendor-files).
- If payment creation fails, no record will be stored.
- Once enabled, the package will automatically update the payment record in subsequent API calls.

##### Accessing the Stored Payment

After a payment is created, and during any subsequent API calls such as `verify()`, `settle()`, or `reverse()`, you can access the underlying payment model:

```php
$payment->getModel();      // \AliYavari\IranPayment\Models\Payment

// Access the associated payable model
$payment->getModel()->payable;
```

To see all available attributes, refer to [`src/Models/Payment.php`](./src/Models/Payment.php)

##### Tracking Payments via the Payable Model

When using automatic storage, add the `AliYavari\IranPayment\Concerns\HasPayment` trait to your payable model to track its payments:

```php
// Example payable model (Course)
namespace App\Models;

use AliYavari\IranPayment\Concerns\HasPayment;
use Illuminate\Database\Eloquent\Model;

final class Course extends Model
{
    use HasPayment;

    //
}

// Payments relationship (MorphMany)
$course->payments(); // AliYavari\IranPayment\Models\Payment
```

**Note:** For more information about this relationship, see [Eloquent relationships: one-to-many polymorphic].

##### Querying Stored Payments

The `Payment` model provides query scopes for common payment states:

```php
use AliYavari\IranPayment\Models\Payment as PaymentModel;

// Verified and successful payments
PaymentModel::query()->successful()->...

// Verified and failed payments
PaymentModel::query()->failed()->...

// Pending (unverified) payments
PaymentModel::query()->pending()->...

// Via a payable model using HasPayment
$course->payments()->successful()->...
$course->payments()->failed()->...
$course->payments()->pending()->...
```

#### Manual Store

If you want full control over storing and tracking payments, you can use these methods:

```php
// Data required by the gateway for verification (`null` if payment creation failed)
$payment->getGatewayPayload();   // array|null

// Gateway key
$payment->getGateway();          // string

// Unique transaction ID used for tracking in your database (`null` if payment creation failed)
$payment->getTransactionId();    // string|null
```

### Redirect User to Payment Page

To redirect user to the gateway’s payment page, use the data provided by the following method:

```php
$redirectData = $payment->getRedirectData();

// Redirect URL
$redirectData->url;         // string

// Redirect method (POST, GET)
$redirectData->method;      // string

// Redirect payload (POST body or GET query params)
$redirectData->payload;     // array

// Required HTTP headers
$redirectData->headers;     // array

// Get all redirect information as an array
$redirectData->toArray();   // array
```

### Verification

#### Verify, Settle and Refund

After the user is redirected back to your application from the gateway, you can verify the payment using these methods:

**Notes:**

- After calling `verify()`, `settle()`, or `reverse()`, you can use the methods in [Checking API Call Status](#checking-api-call-status) to check the result of the API call.
- If the payment was stored in the database using this package, these methods will automatically update the payment record. To access the underlying payment model, see [Automatic Store](#automatic-store)

```php
use AliYavari\IranPayment\Facades\Payment;

// Create a gateway instance from callback data
$payment = Payment::gateway(string $gateway)->fromCallback(array $callbackPayload);
```

If you used the internal automatic storage

```php
// Call verify without any arguments
$payment->verify();
```

If you stored the payment manually,

```php
// To find the payment in your database
$payment->getTransactionId();

// Call verify with the stored gateway payload
$payment->verify(array $gatewayPayload);
```

To settle or reverse the payment:

```php
// Settle the payment (call if verification is successful)
$payment->settle();

// Reverse or refund the payment (call if verification fails)
$payment->reverse();

// Let the package handle settle or reverse automatically when needed
$payment
  ->autoSettle(bool $autoSettle = true)
  ->autoReverse(bool $autoReverse = true)
  ->verify(...); // Manual or automatic storage
```

**Notes:**

- To get `$callbackPayload`, this package provides basic `FormRequest` classes to validate callback data.
  These classes are located in `AliYavari\IranPayment\Requests\<Gateway>Request`. See [Form Request classes](#form-request-classes)
- If auto-settle and/or auto-reverse is enabled, the [Checking API Call Status](#checking-api-call-status) applies to **verification**.

#### Successful Payment Details

If the payment is successful, the following methods are available to retrieve additional payment details:

```php
// Get the reference number assigned to the transaction by the bank.
$payment->getRefNumber();   // string|null

// Get user's card number used to pay.
$payment->getCardNumber();  // string|null
```

#### Form Request Classes

To sanitize and validate callback data from each gateway, this package provides simple FormRequest classes.
You can use them like this (using the `sep` gateway as an example):

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

        // Verification logic, product delivery, etc.
    }
}
```

Available Form Request classes:

```php
// Behpardakht
use AliYavari\IranPayment\Http\Requests\BehpardakhtRequest;

// Sep
use AliYavari\IranPayment\Http\Requests\SepRequest;

// Zarinpal
use AliYavari\IranPayment\Http\Requests\ZarinpalRequest;

// IDPay
use AliYavari\IranPayment\Http\Requests\IdPayRequest;
```

#### Verification Without Callback

Sometimes the user does not return to your website after completing the payment. In this case, you should use the `noCallback()` method instead of `fromCallback()`. All other steps remain the same.

```php
use AliYavari\IranPayment\Facades\Payment;

$payment = Payment::gateway(string $gateway)->noCallback(string $transactionId);
```

If you are using [Automatic Store](#automatic-store), you can directly rebuild the gateway payment instance from the stored model:

```php
$payment = $paymentModel->toGatewayPayment();
```

**Note:** Some gateways (mainly Shaparak-based gateways) automatically reverse the transaction if the callback is not received, while others allow verification without a callback. This package applies each gateway’s rules internally.

## Testing

For local development or automated tests, you can fake payment responses so no real transactions are made.  
This allows you to test success, failure, and connection errors safely.

```php
use AliYavari\IranPayment\Facades\Payment;

/**
 * Fake the default gateway to return successful response
 *
 * Default redirect data for testing:
 * ['url' => 'https://gateway.test', 'method' => 'POST', 'payload' => ['status' => 'successful'], 'headers' => []]
 */
Payment::fake();

/**
 * Fake specific gateways to return successful responses
 *
 * Note: Use `default` as the gateway key to target the default gateway
 */
Payment::fake([/* gateway keys */]);

/**
 * Equivalent to the above (explicit success definition)
 *
 * Optional: Custom redirect data using `AliYavari\IranPayment\Dtos\PaymentRedirectDto`
 */
Payment::fake([...], Payment::successfulRequest(?PaymentRedirectDto $paymentRedirect = null));

/**
 * Fake gateways to return failed responses
 *
 * Optional: custom error message and error code
 */
Payment::fake([...], Payment::failedRequest(string $errorMessage = 'Error Message', string|int $errorCode = 0));

/**
 * Fake gateways to throw a ConnectionException
 */
Payment::fake([...], Payment::failedConnection());

/**
 * Define different behaviors per gateway
 */
Payment::fake([
    'gateway_one' => Payment::successfulRequest(),
    'gateway_two' => Payment::failedRequest(),
    'gateway_three' => Payment::failedConnection(),
]);
```

**Notes:**

- Defining both _global behavior_ and _per-gateway behaviors_ together is not allowed in a single call. Use one strategy per `fake()` call.
- If you define multiple behaviors for the same gateway, the last one will override the previous definitions.

## Contributing

Thank you for considering contributing to the Iran Payment Laravel! The contribution guide can be found in the [CONTRIBUTING.md](CONTRIBUTING.md).

## License

**Iran Payment Laravel** was created by **[Ali Mohammad Yavari](https://www.linkedin.com/in/ali-m-yavari/)** under the **[MIT license](https://opensource.org/licenses/MIT)**.

<!-- Links -->

[behpardakht.com]: https://behpardakht.com
[sep.ir]: https://sep.ir
[zarinpal.com]: https://zarinpal.com
[idpay.ir]: https://idpay.ir
[Eloquent relationships: one-to-many polymorphic]: https://laravel.com/docs/12.x/eloquent-relationships#one-to-many-polymorphic-relations
[Laravel's task scheduler]: https://laravel.com/docs/12.x/scheduling#scheduling-artisan-commands
