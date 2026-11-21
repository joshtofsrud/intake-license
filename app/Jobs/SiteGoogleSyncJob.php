<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\Tenant\TenantGoogleConnection;
use App\Services\SiteHealth\GoogleSiteData;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** MARKER-SITE-HEALTH: reads a shop's numbers from Google (search, visitors, shopping listings, reviews). Reads only. */
class SiteGoogleSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 240;

    public function __construct(public string $tenantId) {}

    public function handle(GoogleSiteData $google): void
    {
        $conn = TenantGoogleConnection::where('tenant_id', $this->tenantId)->first();
        $tenant = Tenant::find($this->tenantId);
        if (! $conn || ! $tenant) {
            return;
        }
        try {
            $google->sync($tenant, $conn);
        } catch (Throwable $e) {
            $st = (array) ($conn->state ?? []);
            $st['running'] = false;
            $st['error'] = 'Reading from Google stopped on an error. Try Refresh.';
            $conn->state = $st;
            $conn->save();
            JobFailureReporter::report(self::class, 'Reading Google data for Site health failed', $e, [], $this->tenantId);
        }
    }
}
