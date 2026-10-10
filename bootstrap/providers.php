<?php

use App\Providers\AppServiceProvider;
use App\Providers\BrevoMailServiceProvider;
use App\Providers\SecurityServiceProvider;

return [
    AppServiceProvider::class,
    SecurityServiceProvider::class,
    BrevoMailServiceProvider::class,
];
