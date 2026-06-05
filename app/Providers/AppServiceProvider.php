<?php

namespace App\Providers;

use Lily\Foundation\Application;

class AppServiceProvider
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function register(): void
    {
        // Bind application-specific services here
        // $this->app->singleton(MyService::class, function () { return new MyService(); });
    }

    public function boot(): void
    {
        // Bootstrap any application services
    }
}
