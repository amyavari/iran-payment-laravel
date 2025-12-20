# Iran SMS Laravel

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

## Requirements

- PHP version `8.2` or higher
- Laravel `^11.44`, or `^12.4`

**THIS PACKAGE IS UNDER DEVELOPMENT, PLEASE DO NOT USE IT YET**

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
    - [Automatic Redirection](#automatic-redirection)
    - [Manual Redirection](#manual-redirection)
  - [Verification, Settle and Refund](#verification-settle-and-refund)
    - [Verification](#verification)
    - [Settle](#settle)
    - [Refund or Reverse](#refund-or-reverse)
    - [Form Request Classes](#form-request-classes)
    - [Verification and Settle Without Callback](#verification-and-settle-without-callback)
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

**Note:** For the `PAYMENT_GATEWAY`, refer to the `gateway Key` column in the [List of Available Payment Gateways](#list-of-available-payment-gateways).

**Note:** For each gateway’s callback URL and credentials, define the required keys under your desired gateway(s) in the `gateways` section of [config/iran-payment.php](./config/iran-payment.php)

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

You can use the package’s internal functionality to store a created payment in the database, or handle it yourself using the [Manual Store](#manual-store)
methods.

**Note:** For automatic storage, you must publish and run the migration files. See [Publish Vendor Files](#publish-vendor-files).

```php
// Store the payment and associate it with the Eloquent model the payment is for.
$payment->store(Model $payable);
```

##### Track Payment Through Your Payable Model

When using automatic storage, you can add the `AliYavari\IranPayment\Concerns\Payable` trait to your payable model to easily track its payment statuses.

```php
<?php

declare(strict_types=1);

namespace App\Models;

use AliYavari\IranPayment\Concerns\Payable;
use Illuminate\Database\Eloquent\Model;

final class Course extends Model
{
    use Payable;

    //
}

// Access the payments relationship (MorphMany)

// Returns a query builder instance for all payments
$course->payments();

// Returns a collection of all related Payment models
$course->payments;

// Returns a query builder for only successful payments
$course->payments()->successful();

// Returns a query builder for only failed payments
$course->payments()->failed();
```

**Note:** For more information about this relationship, see [Eloquent relationships: one-to-many polymorphic].

##### Prune Old Failed Payments

To help keep your payment table clean, this package provides an Artisan command to prune old payment records (failed ones). You can schedule this command using [Laravel's task scheduler]

Example: Delete failed payments created before 30 days ago

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('iran-payment:prune-failed-payments --days=30')->daily();
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

#### Automatic Redirection

To automatically redirect the user to the payment page, use:

```php
$payment->redirect();
```

#### Manual Redirection

If you prefer to manually handle the redirection to the gateway’s payment page, use the data provided by the following method:

```php
$redirectData = $payment->getPaymentRedirectData();

// Redirect URL
$redirectData->url;         // string

// Redirect method (POST, GET)
$redirectData->method;      // string

// Redirect payload (POST body or GET query params)
$redirectData->payload;     // array

// Required HTTP headers
$redirectData->headers;     // array

// Get all redirect information as an array (useful for sending to frontend)
$redirectData->toArray();
```

### Verification, Settle and Refund

#### Verification

After the user is redirected back to your application from the gateway, you can verify the payment using these methods:

**Note:** After calling `verify()`, `settle()`, or `reverse()`, you can use the methods in [Checking API Call Status](#checking-api-call-status) to check the result of the API call.

```php
use AliYavari\IranPayment\Facades\Payment;

// Create a gateway instance from callback data
$payment = Payment::gateway(string $gateway)->fromCallBack(array $callbackData);

// If you used the internal automatic storage
$payment->verify();

// If you stored the payment manually:
// Get the transaction ID (to find the record in your database)
$payment->getTransactionId();

// Verify payment manually using your stored payload
$payment->verify(array $gatewayPayload);
```

**Note:** To get `$callbackData`, this package provides basic `FormRequest` classes to validate callback data.
These classes are located in `AliYavari\IranPayment\Requests\<Gateway>Request`. See [Form Request classes](#form-request-classes)

#### Settle

Some gateways (especially Shaparak gateways) require you to settle the payment after successful verification:

```php
// Request the gateway to transfer the money into your account
$payment->settle();
```

#### Refund or Reverse

If you need to refund a transaction, or if something failed during verification, use:

```php
// Request the gateway to refund or reverse the transaction
$payment->reverse();
```

#### Form Request Classes

To sanitize and validate callback data from each gateway, this package provides simple FormRequest classes.
You can use them like this (using the `sep` gateway as an example):

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use AliYavari\IranPayment\Requests\SepRequest;
use App\Http\Controllers\Controller;

final class SepVerificationController extends Controller
{
    public function update(SepRequest $request): JsonResponse
    {
        $callbackData = $request->validated();

        // Verification logic, product delivery, etc.
    }
}
```

Available Form Request classes:

```php
// Behpardakht
use AliYavari\IranPayment\Requests\BehpardakhtRequest;

// Sep
use AliYavari\IranPayment\Requests\SepRequest;

// Zarinpal
use AliYavari\IranPayment\Requests\ZarinpalRequest;

// IDPay
use AliYavari\IranPayment\Requests\IdPayRequest;
```

#### Verification and Settle Without Callback

Sometimes the user does not return to your website after the payment. In this situation, some gateways (mainly Shaparak-based ones) automatically reverse the transaction, while others do not.
To handle this case the same way across all gateways, you can use the following methods. The package will apply each gateway’s rules internally and provide you with a simple, consistent API:

**Note:** After calling `verifyPendingNoCallback()`, or `settle()`, you can use the methods in [Checking API Call Status](#checking-api-call-status) to check the result of the API call.

```php
use AliYavari\IranPayment\Facades\Payment;

// Verify
$payment = Payment::verifyPendingNoCallback(array $gatewayPayload);

// Settle if verification was successful
$payment->settle();
```

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

**Note:** Defining both _global behavior_ and _per-gateway behaviors_ together is not allowed in a single call. Use one strategy per `fake()` call.

**Note:** If you define multiple behaviors for the same gateway, the last one will override the previous definitions.

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
