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

        // https://www.sep.ir/
        'sep' => [
            'callback_url' => env('SEP_CALLBACK_URL', ''),
            'terminal_id' => env('SEP_TERMINAL_ID', ''),
        ],

        // https://www.zarinpal.com/
        'zarinpal' => [
            'callback_url' => env('ZARINPAL_CALLBACK_URL', ''),
            'merchant_id' => env('ZARINPAL_MERCHANT_ID', ''),
        ],

        // https://idpay.ir/
        'id_pay' => [
            'callback_url' => env('ID_PAY_CALLBACK_URL', ''),
            'api_key' => env('ID_PAY_API_KEY', ''),
        ],

        // https://pep.co.ir/
        'pep' => [
            'terminalNumber' => env('PEP_TERMINAL_NUMBER', ''),
            'base_url' => env('PEP_BASE_URL', ''),
            'username' => env('PEP_USERNAME', ''),
            'password' => env('PEP_PASSWORD', ''),
            'callback_url' => env('PEP_CALLBACK_URL', ''),
        ],
    ],
];
