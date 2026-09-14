# Contributing

## Getting started
`make build-image && make install && make ci`. No PHP on the host is required or wanted.

## Conventions
- PHP 8.2+, `declare(strict_types=1)`, PSR-12 (php-cs-fixer), PHPStan level 6.
- All HTTP goes through `Http\HttpClientInterface`; all WHMCS globals through `Bridge` or `Whmcs\*Repository`.
- Every user-facing string exists in `lang/english.php` and `lang/german.php`.
- Tests first. A behaviour without a test in `tests/Unit` is not done.

## Pull requests
Branch from `main`, keep `make ci` green, add a line under `[Unreleased]` in `CHANGELOG.md`.

## Scope
This module is an API client for uptimeify.io. Billing, provisioning products and monitoring logic are out of scope.
