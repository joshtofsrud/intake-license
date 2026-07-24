<?php

namespace App\Providers;

use App\Models\Tenant\TenantInventoryReceiveShipmentItem;
use App\Observers\Pos\TenantInventoryReceiveShipmentItemObserver;

use App\Listeners\LogAuthEvents;
use App\Listeners\LogMailEvents;
use App\Listeners\LogQueueEvents;
use App\Models\Tenant\TenantUser;
use App\Models\Tenant;
use App\Observers\TenantLocationObserver;
use App\Observers\TenantObserver;
use App\Observers\TenantUserObserver;
use App\Services\DebugLogService;
use App\Support\MySQLLock;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use App\Models\Tenant\TenantDomain;
use App\Observers\TenantDomainObserver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(TenantServiceProvider::class);

        // Singleton so correlation IDs persist across a single request.
        $this->app->singleton(DebugLogService::class);
        $this->app->alias(DebugLogService::class, 'debug_log');

        // MySQLLock is stateless; singleton avoids re-instantiation per inject.
        $this->app->singleton(MySQLLock::class);
    }

    public function boot(): void
    {
        // MARKER-PATCH-116 — enforce one primary domain per tenant
        TenantDomain::observe(TenantDomainObserver::class);

        TenantInventoryReceiveShipmentItem::observe(TenantInventoryReceiveShipmentItemObserver::class);

        \Illuminate\Database\Eloquent\Model::shouldBeStrict(
            ! app()->isProduction()
        );

        // Register debug-log event subscribers. Each listener's subscribe()
        // method returns a [Event::class => 'handler'] map.
        Event::subscribe(LogAuthEvents::class);
        Event::subscribe(LogMailEvents::class);

        // MARKER-PLATFORM-MAIL — fill the platform sender on mail that has
        // not set its own From (was falling through to hello@example.com).
        Event::listen(
            \Illuminate\Mail\Events\MessageSending::class,
            \App\Listeners\ApplyPlatformMailFrom::class
        );
        Event::subscribe(LogQueueEvents::class);

        // Model observers
        Tenant::observe(TenantObserver::class);
        TenantUser::observe(TenantUserObserver::class);
        \App\Models\Tenant\TenantAppointment::observe(\App\Observers\TenantAppointmentObserver::class); // MARKER-PATCH-311
        \App\Models\Tenant\TenantLocation::observe(TenantLocationObserver::class);
    }
}
