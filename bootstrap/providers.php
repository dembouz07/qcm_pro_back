<?php

use App\Providers\NeonDatabaseServiceProvider;
use App\Providers\AppServiceProvider;

return [
    NeonDatabaseServiceProvider::class,  // IMPORTANT: avant AppServiceProvider
    AppServiceProvider::class,
];
