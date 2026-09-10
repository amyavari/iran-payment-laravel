# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

### Changed

- **Pep** and **IDPay** gateways return `کد پاسخ نامشخص` as the message for unknown status codes, like the other gateways.

### Fixed

- Reading `getRefNumber()` and `getCardNumber()` after a manual `reverse()` call throws an `InvalidCallOrderException`.
- **Behpardakht** `getCardNumber()` returns `CardHolderPan` instead of `CardHolderInfo`.
- **Payping** `getRefNumber()` and `getCardNumber()` return the correct values when the payment was already verified.
- **Nextpay** `create()` throws a `CannotConvertToTomanException` when the Rial amount is not a multiple of 10.

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

[unreleased]: https://github.com/amyavari/iran-payment-laravel/compare/v2.0.1...HEAD
[2.0.1]: https://github.com/amyavari/iran-payment-laravel/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/amyavari/iran-payment-laravel/compare/v1.1.0...v2.0.0
[1.1.0]: https://github.com/amyavari/iran-payment-laravel/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/amyavari/iran-payment-laravel/compare/v0.1.0...v1.0.0
