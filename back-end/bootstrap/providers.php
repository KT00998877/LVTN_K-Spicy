<?php

use App\Providers\AppServiceProvider;
use App\Providers\ChatbotServiceProvider;
use App\Providers\MarketingAiServiceProvider;

return [
    AppServiceProvider::class,
    MarketingAiServiceProvider::class,
    ChatbotServiceProvider::class,
];