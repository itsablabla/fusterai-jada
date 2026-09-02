<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\ModuleServiceProvider;
use App\Providers\OpenAiCompatShimProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    ModuleServiceProvider::class,
    OpenAiCompatShimProvider::class,
];
