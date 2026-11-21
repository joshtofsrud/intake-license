<?php

namespace App\Console\Commands;

use App\Jobs\SiteGoogleSyncJob;
use App\Jobs\SiteHealthJob;
use App\Models\Tenant;
use App\Models\Tenant\TenantGoogleConnection;
use App\Models\Tenant\TenantSiteHealth;
use App\Services\SiteHealth\SiteChecker;
use App\Support\Seo;
use Illuminate\Console\Command;

/**
 * MARKER-SITE-HEALTH: the nightly run. Starts a site check for every shop whose site is
 * in search, and reads Google again for every shop that connected it. A shop that
 * pressed Stop on a check is left alone until it resumes or starts a new one.
 *
 *   php artisan site-health:check  [--tenant=subdomain]
 */
class SiteHealthCheck extends Command
{
    protected $signature = 'site-health:check {--tenant= : only this shop (subdomain)}';

    protected $description = 'Check every live shop site for things to fix, and read Google for shops that connected it';

    public function handle(SiteChecker $checker): int
    {
        $q = Tenant::query()->where('is_active', true);
        if ($this->option('tenant')) {
            $q->where('subdomain', $this->option('tenant'));
        }
        $started = 0; $google = 0;
        $q->orderBy('id')->chunk(100, function ($tenants) use ($checker, &$started, &$google) {
            foreach ($tenants as $tenant) {
                if (Seo::tenantIndexable($tenant)) {
                    $st = (array) (TenantSiteHealth::where('tenant_id', $tenant->id)->value('state') ?? []);
                    if (! ($st['running'] ?? false) && ! ($st['stopped'] ?? false)) {
                        $checker->start($tenant);
                        SiteHealthJob::dispatch($tenant->id)->delay(now()->addSeconds($started * 20));
                        $started++;
                    }
                }
                if (TenantGoogleConnection::where('tenant_id', $tenant->id)->whereNotNull('refresh_token')->exists()) {
                    SiteGoogleSyncJob::dispatch($tenant->id)->delay(now()->addSeconds($google * 10));
                    $google++;
                }
            }
        });
        $this->info("Site checks started: {$started}. Google reads queued: {$google}.");

        return self::SUCCESS;
    }
}
