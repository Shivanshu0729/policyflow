<?php

namespace App\Providers;

use App\Services\Provider\InsurerClientInterface;
use App\Services\Provider\MockInsurerService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{

    public function register(): void
    {
        $this->app->bind(InsurerClientInterface::class, MockInsurerService::class);
    }

    public function boot(): void
    {

    }
}
