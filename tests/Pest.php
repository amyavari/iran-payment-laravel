<?php

declare(strict_types=1);

use AliYavari\IranPayment\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Sets the current test time in Iran's timezone.
 */
function setTestNowIran(string $dateTime = '2025-12-10 18:30:10'): void
{
    setTestNow($dateTime.'+03:30'); // Iran Standard Time
}

/**
 * Sets the current test time.
 */
function setTestNow(string $dateTime = '2025-12-10 18:30:10'): void
{
    Carbon::setTestNow($dateTime);
}
