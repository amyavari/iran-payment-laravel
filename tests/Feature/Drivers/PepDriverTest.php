<?php

declare(strict_types=1);

use AliYavari\IranPayment\Drivers\PepDriver;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\MissingCallbackDataException;
use AliYavari\IranPayment\Exceptions\SandboxNotSupportedException;
use AliYavari\IranPayment\Tests\Helpers\PepHelper as Helper;
use Illuminate\Contracts\Cache\Lock as CacheLock;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    $this->cacheKey = 'iran_payment_pep_token';

    Helper::setDriverConfigs();
});

it('calls get token API', function (): void {
    fakeHttp(Helper::successfulGetTokenResponse());

    Helper::callResolveToken();

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://base.url/token/getToken') // `base.url` is set by `setDriverConfigs()`
        ->isJson()->toBeTrue()
        ->method()->toBe('POST');

    expect($request->data())
        ->username->toBe('username')
        ->password->toBe('password');
});

it('always calls HTTPS of base URL for getting token', function (string $baseUrl): void {
    fakeHttp(Helper::successfulGetTokenResponse());

    Config::set('iran-payment.gateways.pep.base_url', $baseUrl);

    Helper::callResolveToken();

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://base.url/token/getToken');
})->with([
    'base.url',
    'http://base.url',
    'https://base.url',
]);

it('puts token into the cache on successful getting token', function (): void {
    fakeHttp(Helper::successfulGetTokenResponse());

    $token = Helper::callResolveToken();

    expect($token)
        ->toBe('token'); // From fake get token response

    expect(Cache::get($this->cacheKey))
        ->toBe('token'); // From fake get token response
});

it('does not put anything into the cache on failed getting token', function (): void {
    fakeHttp(Helper::failedResponse());

    $token = Helper::callResolveToken();

    expect($token)
        ->toBeNull();

    expect(Cache::get($this->cacheKey))
        ->toBeNull();
});

it('does not call the API if token exists in the cache', function (): void {
    fakeHttp();

    Cache::set($this->cacheKey, 'test');

    $token = Helper::callResolveToken();

    expect($token)
        ->toBe('test');

    Http::assertNothingSent();
});

it('puts token into the cache on successful getting token for the gateway token expiration time', function (): void {
    /**
     * 10 minutes is the official token expiration time of the gateway.
     * We expect 9 minutes ttl (1 min less) as safe zone to cover latency, inaccurate server time, ...
     */
    fakeHttp(Helper::successfulGetTokenResponse());

    Helper::callResolveToken();

    $this->travel(8)->minutes();

    expect(Cache::get($this->cacheKey))
        ->toBe('token');

    $this->travel(1)->minutes();

    expect(Cache::get($this->cacheKey))
        ->toBeNull();
});

it('uses atomic lock to prevent concurrent API calls', function (): void {
    fakeHttp(Helper::successfulGetTokenResponse());

    Concurrency::driver('sync')->run([
        fn (): ?string => Helper::callResolveToken(),
        fn (): ?string => Helper::callResolveToken(),
    ]);

    Http::assertSentCount(1);
});

it('holds lock for 5 seconds and wait for it for 2 seconds', function (): void {
    fakeHttp(Helper::successfulGetTokenResponse());

    $cacheLock = Mockery::mock(CacheLock::class);
    $cacheLock->shouldReceive('block')
        ->once()
        ->with(2, Mockery::any()) // 2 seconds as wait time
        ->andReturnUsing(fn ($seconds, $callback) => $callback());

    Cache::shouldReceive('lock')
        ->once()
        ->with($this->cacheKey, 5) // 5 seconds for lock time
        ->andReturn($cacheLock);

    Cache::shouldReceive('remember')
        ->once()
        ->andReturnUsing(fn ($key, $ttl, $callable) => $callable());

    Helper::callResolveToken();
});

it('sets failed response on failed getting token on payment creation', function (): void {
    fakeHttp($response = Helper::failedResponse());

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('1')->toContain('ناموفق')
        ->getRawResponse()->toBe($response);

    Http::assertSentCount(1); // Only getToken
});

it('generates and returns transaction ID on payment creation', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulCreationResponse(),
    );
    mockUniqueNumberGenerator('123456789012345');

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->getTransactionId()->toBe('123456789012345');
});

it('calls payment creation API with minimum passed data and config callback URL', function (): void {
    setTestNowIran('2025-12-10 18:30:10');

    URL::useOrigin('http://myapp.com');

    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulCreationResponse(),
    );

    $payment = Helper::driver()->create(1_000);

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://base.url/api/payment/purchase') // `base.url` is set by `setDriverConfigs()`
        ->isJson()->toBeTrue()
        ->method()->toBe('POST')
        ->hasHeader('Authorization', 'Bearer token')->toBeTrue() // From fake get token response
        ->hasHeader('Referer', 'http://myapp.com')->toBeTrue();

    expect($request->data())
        ->invoice->toBe($payment->getTransactionId())
        ->invoiceDate->toBe('2025-12-10')
        ->amount->toBe(1_000)
        ->callbackApi->toBe('http://callback.test') // Config's callback URL
        ->serviceCode->toBe('8')
        ->serviceType->toBe('PURCHASE')
        ->terminalNumber->toBe(1234)
        ->not->toHaveKeys(['mobileNumber', 'description']);
});

it('always calls HTTPS of base URL for payment creation', function (string $baseUrl): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulCreationResponse(),
    );

    Config::set('iran-payment.gateways.pep.base_url', $baseUrl);

    Helper::driver()->create(1_000);

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://base.url/api/payment/purchase');
})->with([
    'base.url',
    'http://base.url',
    'https://base.url',
]);

it('calls payment creation API with full passed data', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulCreationResponse(),
    );

    Helper::driver()->create(1_000, 'Description', '09123456789');

    $request = getRecordedHttpRequest();

    // Only what differs from the previous test
    expect($request->data())
        ->description->toBe('Description')
        ->mobileNumber->toBe('09123456789');
});

it('converts phone number to gateway format if needed', function (string|int $phone): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulCreationResponse(),
    );

    Helper::driver()->create(1_000, phone: $phone);

    $request = getRecordedHttpRequest();

    expect($request->data())
        ->mobileNumber->toBe('09123456789');
})->with([
    'With country code' => 989123456789,
    'Without country code, with first zero' => '09123456789',
    'Without country code, and first zero' => 9123456789,
    'With country code, and first plus' => '+989123456789',
    'With country code and first zero' => 9809123456789,
    'With country code, first zero and first plus' => '+9809123456789',
]);

it('returns successful response on successful payment creation', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: $response = Helper::successfulCreationResponse(),
    );

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment creation', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: $response = Helper::failedResponse(),
    );

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('1')->toContain('ناموفق')
        ->getRawResponse()->toBe($response);
});

it('returns gateway payload needed to verify payment on successful payment creation', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulCreationResponse(),
    );

    $payment = Helper::driver()->create(1_000);

    expect($payment)
        ->getGatewayPayload()->toBe([
            'invoice' => $payment->getTransactionId(),
            'urlId' => '8dcc5cd0ef7348548f8dc2ab29ebe11a7ad3eaad000000006217318', // From fake creation response
            'amount' => 1_000,
        ]);
});

it('returns gateway redirect data on successful payment creation', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulCreationResponse(),
    );

    $payment = Helper::driver()->create(1_000);

    expect($payment->getRedirectData())
        ->toBeInstanceOf(PaymentRedirectDto::class)
        ->url->toBe('http://pep.shaparak.ir/8dcc5cd0ef7348548f8dc2ab29ebe11a7ad3eaad000000006217318') // From fake creation response
        ->method->toBe('GET')
        ->payload->toBe([])
        ->headers->toBe([]);
});

it('throws an exception for payment creation when configured to use sandbox', function (): void {
    fakeHttp();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): PepDriver => Helper::driver()->create(1_000))
        ->toThrow(SandboxNotSupportedException::class, 'Pep gateway does not support the sandbox environment.');

    Http::assertNothingSent();
});

it('creates payment instance from callback data', function (): void {
    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

    expect($payment)
        ->toBeInstanceOf(PepDriver::class)
        ->getTransactionId()->toBe('123456789012345');
});

it('throws exception when callback lacks required keys', function (string $key): void {
    $callbackPayload = Arr::except(Helper::successfulCallback(), $key);

    expect(fn (): PepDriver => Helper::driver()->fromCallback($callbackPayload))
        ->toThrow(
            MissingCallbackDataException::class,
            sprintf('To create pep gateway instance from callback, "status, invoiceId" are required. "%s" is missing.', $key)
        );
})->with([
    'status',
    'invoiceId',
]);

it('throws exception when stored payload and successful callback data do not match', function (string $payloadKey, string $callbackKey): void {
    fakeHttp();

    $payload = Helper::gatewayPayload();
    Arr::set($payload, $payloadKey, '123'); // Change payload value for the given key so it no longer matches

    $payment = Helper::driver()->fromCallback(Helper::successfulCallback());

    expect(fn (): PepDriver => $payment->verify($payload))
        ->toThrow(
            InvalidCallbackDataException::class,
            sprintf('"%s" in the callback does not match with "%s" in the stored gateway payload.', $callbackKey, $payloadKey)
        );

    Http::assertNothingSent();
})->with([
    ['invoice', 'invoiceId'],
]);

it('does not verify payment when callback status is not successful', function (): void {
    fakeHttp();

    $callbackPayload = Helper::failedCallback();

    $payment = Helper::driver()
        ->fromCallback($callbackPayload)
        ->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('1')->toContain('ناموفق') // The error code is set by fake failed callback.
        ->getRawResponse()->toBe($callbackPayload);

    Http::assertNothingSent();
});

it('sets failed response on failed getting token on payment verification', function (): void {
    fakeHttp($response = Helper::failedResponse());

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('1')->toContain('ناموفق')
        ->getRawResponse()->toBe($response);

    Http::assertSentCount(1); // Only getToken
});

it('verifies payment when callback is successful and matches stored payload', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulVerificationResponse(),
    );

    Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://base.url/api/payment/verify-payment') // `base.url` is set by `setDriverConfigs()`
        ->isJson()->toBeTrue()
        ->method()->toBe('POST')
        ->hasHeader('Authorization', 'Bearer token')->toBeTrue(); // From fake get token response

    expect($request->data())
        ->checkVerify->toBeFalse()
        ->invoice->toBe('123456789012345') // From fake callback
        ->urlId->toBe('8dcc5cd0ef7348548f8dc2ab29ebe11a7ad3eaad000000006217318'); // From gateway payload
});

it('always calls HTTPS of base URL for payment verification', function (string $baseUrl): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulVerificationResponse(),
    );

    Config::set('iran-payment.gateways.pep.base_url', $baseUrl);

    Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://base.url/api/payment/verify-payment');
})->with([
    'base.url',
    'http://base.url',
    'https://base.url',
]);

it('returns successful response on successful payment verification', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: $response = Helper::successfulVerificationResponse(),
    );

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on successful payment verification with invalid amount', function (): void {
    $response = Helper::successfulVerificationResponse();
    Arr::set($response, 'data.amount', 2_000);

    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: $response,
    );

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('9300')->toContain('مبلغ پرداخت شده نامعتبر است')
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment verification', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: $response = Helper::failedResponse(),
    );

    $payment = Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload());

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('1')->toContain('ناموفق')
        ->getRawResponse()->toBe($response);
});

it('throws an exception for payment verification when configured to use sandbox', function (): void {
    fakeHttp();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): PepDriver => Helper::driverFromSuccessfulCallback()->verify(Helper::gatewayPayload()))
        ->toThrow(SandboxNotSupportedException::class, 'Pep gateway does not support the sandbox environment.');

    Http::assertNothingSent();
});

it('returns card number and reference ID from successful verification', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulVerificationResponse(),
    );

    $payment = Helper::verifiedPayment();

    expect($payment)
        ->getRefNumber()->toBe('142514251425') // From fake verification response
        ->getCardNumber()->toBe('123456******1234'); // From fake verification response
});

it('sets failed response on failed getting token on payment reversal', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulVerificationResponse(),
    )
        ->push($response = Helper::failedResponse()) // Second getToken call
        ->push(Helper::successfulReversalResponse());

    $payment = Helper::verifiedPayment();

    Cache::forget($this->cacheKey); // Force to call getToken for reversal

    $payment->reverse();

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('1')->toContain('ناموفق')
        ->getRawResponse()->toBe($response);

    Http::assertSentCount(3); // getToken, verification and getToken
});

it('reverses the payment', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulVerificationResponse(),
    )->push(Helper::successfulReversalResponse());

    Helper::verifiedPayment()->reverse();

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://base.url/api/payment/reverse-transactions') // `base.url` is set by `setDriverConfigs()`
        ->isJson()->toBeTrue()
        ->method()->toBe('POST')
        ->hasHeader('Authorization', 'Bearer token')->toBeTrue(); // From fake get token response

    expect($request->data())
        ->invoice->toBe('123456789012345') // From fake callback
        ->urlId->toBe('8dcc5cd0ef7348548f8dc2ab29ebe11a7ad3eaad000000006217318'); // From gateway payload
});

it('always calls HTTPS of base URL for payment reversal', function (string $baseUrl): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulVerificationResponse(),
    )->push(Helper::successfulReversalResponse());

    Config::set('iran-payment.gateways.pep.base_url', $baseUrl);

    Helper::verifiedPayment()->reverse();

    $request = getRecordedHttpRequest();

    expect($request)
        ->url()->toBe('https://base.url/api/payment/reverse-transactions');
})->with([
    'base.url',
    'http://base.url',
    'https://base.url',
]);

it('returns successful response on successful payment reversal', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulVerificationResponse(),
    )->push($response = Helper::successfulReversalResponse());

    $payment = Helper::verifiedPayment()->reverse();

    expect($payment)
        ->successful()->toBeTrue()
        ->error()->toBeNull()
        ->getRawResponse()->toBe($response);
});

it('returns failed response on failed payment reversal', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulVerificationResponse(),
    )->push($response = Helper::failedResponse());  // Reversal

    $payment = Helper::verifiedPayment()->reverse();

    expect($payment)
        ->successful()->toBeFalse()
        ->error()->toContain('1')->toContain('ناموفق')
        ->getRawResponse()->toBe($response);
});

it('throws an exception for payment reversal when configured to use sandbox', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulVerificationResponse(),
    );

    $payment = Helper::verifiedPayment();

    Config::set('iran-payment.use_sandbox', true);

    expect(fn (): PepDriver => $payment->reverse())
        ->toThrow(SandboxNotSupportedException::class, 'Pep gateway does not support the sandbox environment.');

    Http::assertSentCount(2); // Only getToken and verification, before set to sandbox
});

it('creates payment instance with no callback data', function (): void {
    $payment = Helper::driver()->noCallback(transactionId: '123456789');

    expect($payment)
        ->toBeInstanceOf(PepDriver::class)
        ->getTransactionId()->toBe('123456789');
});

it('verifies normally with no callback data', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulVerificationResponse(),
    );

    $payment = Helper::driver()->noCallback('123456789012345');

    $payment->verify(Helper::gatewayPayload());

    Http::assertSentCount(2); // getToken, verification
});

it('reverses normally with no callback data', function (): void {
    fakeHttp(
        firstResponse: Helper::successfulGetTokenResponse(),
        secondResponse: Helper::successfulVerificationResponse(),
    )->push(Helper::successfulReversalResponse());

    $payment = Helper::driver()->noCallback('123456789012345')->verify(Helper::gatewayPayload());

    $payment->reverse();

    Http::assertSentCount(3); // getToken, verification and reversal
});
