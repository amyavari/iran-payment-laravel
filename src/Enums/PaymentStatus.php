<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Successful = 'successful';
    case Failed = 'failed';
}
