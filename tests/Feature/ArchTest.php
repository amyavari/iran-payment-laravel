<?php

declare(strict_types=1);

arch()->preset()->security();
arch()->preset()->php();

arch("Pest's classes are not imported in the src")
    ->expect('AliYavari\IranPayment')
    ->not->toUse('Pest\Support');
