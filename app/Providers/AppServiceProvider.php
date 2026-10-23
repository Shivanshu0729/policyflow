<?php

namespace App\Providers;

use App\Services\Provider\InsurerClientInterface;
use App\Services\Provider\MockInsurerService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(InsurerClientInterface::class, MockInsurerService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
