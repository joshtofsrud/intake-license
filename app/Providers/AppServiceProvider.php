<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(TenantServiceProvider::class);
    }

    public function boot(): void
    {
        \Illuminate\Database\Eloquent\Model::shouldBeStrict(
            ! app()->isProduction()
        );
    }
}
