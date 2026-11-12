<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Support\JobFailureReporter;
use App\Support\Launch\LaunchChecks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * MARKER-LAUNCH-CHECKLIST: counts a shop's online items missing a photo, price
 * or weight, and those Google would turn away, for the launch checklist. Too
 * slow for a page load on a big shop (it walks every online item), so it runs
 * here and the checklist reads the saved count.
 */
class LaunchItemFactsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries = 1;
    public int $uniqueFor = 900;

    public function __construct(public string $tenantId) {}

    public function uniqueId(): string
    {
        return 'launch-item-facts:' . $this->tenantId;
    }

    public function handle(): void
    {
        $t = Tenant::find($this->tenantId);
        if (! $t) return;
        LaunchChecks::countFacts($t);
    }

    public function failed(\Throwable $e): void
    {
        Cache::forget('launch:items:queued:' . $this->tenantId);
        JobFailureReporter::report(self::class, 'Launch checklist could not count the online items', $e, [], $this->tenantId);
    }
}
