<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Enums;

/**
 * @internal
 *
 * API methods defined by this package.
 * Used to track which method has been called on a driver.
 */
enum ApiMethod: string
{
    case Create = 'create';
    case Verify = 'verify';
    case Reverse = 'reverse';

    case FromCallback = 'fromCallback';
    case NoCallback = 'noCallback';
}
