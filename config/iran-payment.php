<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Payment Gateway (Driver)
    |--------------------------------------------------------------------------
    |
    | This option controls the default Payment Gateway used for purchases,
    | unless another Payment Gateway is explicitly specified at the time
    | of purchase.
    |
    */
    'default' => env('PAYMENT_GATEWAY', ''),

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | This option controls the application's base currency. Amounts will be
    | converted from/to this currency when interacting with the gateway.
    | Available currencies: Toman, Rial.
    |
    */
    'currency' => env('APP_CURRENCY', 'Rial'),

    /*
    |--------------------------------------------------------------------------
    | Use Sandbox Mode
    |--------------------------------------------------------------------------
    |
    | This option determines whether the application should use the sandbox
    | payment URL instead of the real gateway URL.
    |
    */
    'use_sandbox' => env('PAYMENT_USE_SANDBOX', false),

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways Configurations
    |--------------------------------------------------------------------------
    |
    | Here are the configurations for all Payment Gateways available in this
    | package, along with their respective settings.
    |
    */
    'gateways' => [

        // https://behpardakht.com/
        'behpardakht' => [
            'callback_url' => env('BEHPARDAKHT_CALLBACK_URL', ''),
            'terminal_id' => env('BEHPARDAKHT_TERMINAL_ID', ''),
            'username' => env('BEHPARDAKHT_USERNAME', ''),
            'password' => env('BEHPARDAKHT_PASSWORD', ''),
        ],
    ],
];
