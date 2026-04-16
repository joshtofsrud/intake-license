<?php

namespace App\Providers;

use App\Models\Tenant\TenantUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class TenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register a resolvable 'tenant' binding.
        // Returns null when not in a tenant context (platform routes).
        $this->app->bind('tenant', function () {
            return null;
        });
    }

    public function boot(): void
    {
        // Register the 'tenant' authentication guard so TenantUser
        // sessions are separate from the master admin (Filament) sessions.
        Auth::extend('tenant-session', function ($app, $name, array $config) {
            return new \Illuminate\Auth\SessionGuard(
                $name,
                Auth::createUserProvider($config['provider']),
                $app['session.store'],
                $app['request']
            );
        });
    }
}
