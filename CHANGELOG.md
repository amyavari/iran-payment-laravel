# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [2.1.0] - 2026-09-16

### Changed

- **Pep** and **IDPay** gateways return the message for unknown status codes, like the other gateways.
- A `null` or empty value for a required callback key throws a `MissingCallbackDataException`, like a missing key.
- The fake gateway checks callback keys and throws an exception like the real gateway.
- `Payment::fake($gateway)` throws a `BindingResolutionException` when `$gateway` is not a real driver.
- On the fake gateway, `invalidCallback()` throws a `LogicException` when the payment was built with `noCallback()`.
- A missing or invalid gateway value that decides the payment result, or that a successful call needs, throws an `InvalidGatewayDataException`. The raw gateway body is in the exception context.
- **IDPay**, **Sep**, **Payping**, **Zarinpal** and **Zibal**: an invalid error code or error message from the gateway falls back to an internal error code and message.
- **Zibal** `getGatewayPayload()` returns `trackId` as a string. Stored payloads with an integer `trackId` still verify.
- **IDPay** verification is successful only for the status codes `100`, `101` and `200`.
- **Pep**, **Zarinpal** and **Zibal** treat any callback status other than the success value as a failed payment.
- `getRefNumber()` and `getCardNumber()` return an empty string when the gateway sends no value, instead of throwing a `TypeError`.

### Fixed

- Reading `getRefNumber()` and `getCardNumber()` after a manual `reverse()` call throws an `InvalidCallOrderException`.
- **Behpardakht** `getCardNumber()` returns `CardHolderPan` instead of `CardHolderInfo`.
- **Payping** `getRefNumber()` and `getCardNumber()` return the correct values when the payment was already verified.
- **Nextpay** `create()` throws a `CannotConvertToTomanException` when the Rial amount is not a multiple of 10.
- The `currency` config is compared case-insensitively to compare `Toman` and `Rial` correctly.
  **Warning:** if your app set `APP_CURRENCY` to `Toman` in a different letter case than exactly `Toman` (for example `toman` or `TOMAN`), amounts were sent to the gateway as Rial. After the upgrade, the amount sent is ten times bigger, which is the correct amount.
- The fake gateway `getTransactionId()` returns the correct transaction ID from the callback data after `fromCallback()`.
- **Behpardakht** reported a failed callback with a non-numeric `ResCode` as a successful payment.
- **Behpardakht** reported a non-numeric SOAP response as successful.
- **Sadad** and **Pep** reported a response without a result code as successful.
- **Payping** reported a verification response with HTTP status `200` and no valid body as successful. The verified amount is now checked too.
- **Payping** reported a reversal response with HTTP status `200` and no reversal receipt as successful.
- **Payping** `verify()` threw a `TypeError` when the callback values were strings.
- **IDPay**, **Sep**, **Pep**, **Sadad** and **Zibal** failed the verification of a paid payment when the stored or the verified amount was a numeric string.
- **Nextpay** `verify()` threw a `TypeError` when the stored `amount` was a numeric string.
- A non-JSON gateway response threw a `TypeError`. The body is now kept as text in `getRawResponse()` or in the exception context.
- Auto-store did not save `ref_number` and `card_number` after verification.
- Missing primary key on the `payments.id` column.
  Existing installations must add it with their own migration:
  `Schema::table('payments', fn (Blueprint $table) => $table->primary('id'));`
- The `payments.amount` column is now an unsigned big integer, so `SUM`/`AVG` and range filters work.
  Existing installations must change the column with their own migration:
  `Schema::table('payments', fn (Blueprint $table) => $table->unsignedBigInteger('amount')->change());`
  On PostgreSQL use a raw statement instead:
  `DB::statement('ALTER TABLE payments ALTER COLUMN amount TYPE BIGINT USING amount::bigint');`

## [2.0.1] - 2026-08-22

### Fixed

- Importing `Arr` from Pest namespace instead of Illuminate namespace in some drivers
- Throwing `ConnectionException` correctly in the testing helper methods

## [2.0.0] - 2026-05-16

### Added

- **Zibal** gateway
- **Payping** gateway
- **Nextpay** gateway

### Changed

- **IDPay** gateway key from `id-pay` to `idpay`.

## [1.1.0] - 2026-05-06

### Added

- Support for Laravel `13`
- **Payment Electronic Pasargad (Pep)** gateway
- **Sadad** gateway

### Fixed

- Allow optional values to pass validation when empty or `null`

## [1.0.0] - 2026-03-31

First stable release of the package, features:

- **Multi-gateway support**: Seamlessly switch between gateways
- **Auto-store**: Easily store and update payment records
- **Faking/Testing utilities**: Simplified testing for gateway functionality and payment workflows

Supported Gateways:

- Behpardakht
- Sep
- Zarinpal
- IDPay

[2.1.0]: https://github.com/amyavari/iran-payment-laravel/compare/v2.0.1...v2.1.0
[2.0.1]: https://github.com/amyavari/iran-payment-laravel/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/amyavari/iran-payment-laravel/compare/v1.1.0...v2.0.0
[1.1.0]: https://github.com/amyavari/iran-payment-laravel/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/amyavari/iran-payment-laravel/compare/v0.1.0...v1.0.0
