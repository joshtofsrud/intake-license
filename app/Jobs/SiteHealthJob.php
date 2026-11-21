<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\Tenant\TenantSiteHealth;
use App\Services\SiteHealth\SiteChecker;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** MARKER-SITE-HEALTH: one step of a shop's site check; queues the next until it is done or stopped. */
class SiteHealthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(public string $tenantId) {}

    public function handle(SiteChecker $checker): void
    {
        $lock = Cache::lock('site_health:' . $this->tenantId, 170);
        if (! $lock->get()) {
            return;
        }
        $h = TenantSiteHealth::where('tenant_id', $this->tenantId)->first();
        try {
            $tenant = Tenant::find($this->tenantId);
            if (! $tenant || ! $h || ! (($h->state ?? [])['running'] ?? false)) {
                return;
            }
            $more = $checker->step($tenant, $h);
        } catch (Throwable $e) {
            if ($h) {
                $checker->fail($h, 'The check stopped on an error. Resume to carry on from where it was.');
                JobFailureReporter::report(self::class, 'Site health check failed', $e, [], $this->tenantId);
            }

            return;
        } finally {
            $lock->release();
        }
        if ($more) {
            self::dispatch($this->tenantId);
        }
    }
}
