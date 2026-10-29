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
        // MARKER-SEC-AUDIT: workers are long-lived; each job reads add-on access fresh
        \Illuminate\Support\Facades\Queue::before(fn () => \App\Services\FeatureAccessService::flushRequestCache());
        // MARKER-ADMIN-TZ: same for the master-admin time zone, so a changed setting reaches workers
        \Illuminate\Support\Facades\Queue::before(fn () => app()->forgetInstance('intake.admin_tz'));

        // MARKER-ADMIN-TZ: every Filament date column, entry and date-time picker shows
        // (and, for pickers, reads back) times in the master-admin zone instead of UTC.
        // Date-only values (a DatePicker, or a column on a `date` field) are calendar days
        // with no instant behind them; shifting them would move them a day, so they opt out.
        \Filament\Tables\Columns\TextColumn::configureUsing(fn ($c) => $c->timezone(fn () => admin_tz()));
        \Filament\Infolists\Components\TextEntry::configureUsing(fn ($c) => $c->timezone(fn () => admin_tz()));
        \Filament\Forms\Components\DateTimePicker::configureUsing(function ($c) {
            if ($c instanceof \Filament\Forms\Components\DatePicker || $c instanceof \Filament\Forms\Components\TimePicker) return;
            $c->timezone(fn () => admin_tz());
        });

        // enforce one primary domain per tenant
        // every bare ->links() renders the themed
        // paginator (PATCH-364) instead of Laravel's unstyled tailwind default.
        \Illuminate\Pagination\Paginator::defaultView('vendor.pagination.intake');

        TenantDomain::observe(TenantDomainObserver::class);

        TenantInventoryReceiveShipmentItem::observe(TenantInventoryReceiveShipmentItemObserver::class);

        \Illuminate\Database\Eloquent\Model::shouldBeStrict(
            ! app()->isProduction()
        );

        // Register debug-log event subscribers. Each listener's subscribe()
        // method returns a [Event::class => 'handler'] map.
        Event::subscribe(LogAuthEvents::class);
        Event::subscribe(LogMailEvents::class);

        // fill the platform sender on mail that has
        // not set its own From (was falling through to hello@example.com).
        Event::listen(
            \Illuminate\Mail\Events\MessageSending::class,
            \App\Listeners\ApplyPlatformMailFrom::class
        );

        // nothing from a demo shop, and nothing to a
        // reserved fake domain, ever leaves Intake (returns false = not sent).
        Event::listen(
            \Illuminate\Mail\Events\MessageSending::class,
            \App\Listeners\BlockDemoAndReservedMail::class
        );

        // every send declares its Postmark stream;
        // unstamped mail is transactional and gets 'outbound' explicitly.
        Event::listen(
            \Illuminate\Mail\Events\MessageSending::class,
            \App\Listeners\AssertMessageStream::class
        );
        Event::subscribe(LogQueueEvents::class);

        // record every scheduled run so a job that stops
        // working is visible before a shop reports it.
        Event::listen(\Illuminate\Console\Events\ScheduledTaskStarting::class,
            [\App\Listeners\RecordScheduledTask::class, 'starting']);
        Event::listen(\Illuminate\Console\Events\ScheduledTaskFinished::class,
            [\App\Listeners\RecordScheduledTask::class, 'finished']);
        Event::listen(\Illuminate\Console\Events\ScheduledTaskFailed::class,
            [\App\Listeners\RecordScheduledTask::class, 'failed']);

        // Model observers
        Tenant::observe(TenantObserver::class);
        TenantUser::observe(TenantUserObserver::class);
        \App\Models\Tenant\TenantAppointment::observe(\App\Observers\TenantAppointmentObserver::class);
        \App\Models\Tenant\TenantLocation::observe(TenantLocationObserver::class);
    }
}
