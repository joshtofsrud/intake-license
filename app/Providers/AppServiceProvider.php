<?php

namespace App\Providers;

use App\Listeners\LogAuthEvents;
use App\Listeners\LogMailEvents;
use App\Listeners\LogQueueEvents;
use App\Models\Tenant\TenantUser;
use App\Observers\TenantUserObserver;
use App\Services\DebugLogService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(TenantServiceProvider::class);

        // Singleton so correlation IDs persist across a single request.
        $this->app->singleton(DebugLogService::class);
        $this->app->alias(DebugLogService::class, 'debug_log');
    }

    public function boot(): void
    {
        \Illuminate\Database\Eloquent\Model::shouldBeStrict(
            ! app()->isProduction()
        );

        // Register debug-log event subscribers. Each listener's subscribe()
        // method returns a [Event::class => 'handler'] map.
        Event::subscribe(LogAuthEvents::class);
        Event::subscribe(LogMailEvents::class);
        Event::subscribe(LogQueueEvents::class);

        // Model observers
        TenantUser::observe(TenantUserObserver::class);
    }
}
