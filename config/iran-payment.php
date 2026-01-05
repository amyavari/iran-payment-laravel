<?php

declare(strict_types=1);

return [

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
];
