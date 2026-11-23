<?php

use App\Providers\AppServiceProvider;
use App\Providers\BrevoMailServiceProvider;
use App\Providers\ConnectorServiceProvider;
use App\Providers\SecurityServiceProvider;
use App\Providers\ShopServiceProvider;

return [
    AppServiceProvider::class,
    SecurityServiceProvider::class,
    BrevoMailServiceProvider::class,
    ShopServiceProvider::class,
    ConnectorServiceProvider::class,
];
