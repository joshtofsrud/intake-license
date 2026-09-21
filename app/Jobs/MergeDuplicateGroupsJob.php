<?php
// MARKER-DUP-MERGE

namespace App\Jobs;

use App\Models\Tenant\TenantDuplicateGroup;
use App\Models\Tenant\TenantUser;
use App\Services\Inventory\DuplicateItemMerger;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * "Merge all": every READY group for one shop, one at a time, each in its own
 * transaction. A group that fails is marked failed with the reason (it shows
 * under Needs a look) and the rest carry on; the first failure is reported.
 * Interrupted runs are safe to repeat — merged groups are skipped.
 */
class MergeDuplicateGroupsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;
    public int $uniqueFor = 3600;

    public function __construct(public string $tenantId, public ?string $userId = null) {}

    /** Set while a merge-all is queued or running, so the page can say so. */
    public static function runningKey(string $tenantId): string
    {
        return 'dup-merge-running:' . $tenantId;
    }

    public function uniqueId(): string
    {
        return 'merge-duplicates:' . $this->tenantId;
    }

    public function handle(DuplicateItemMerger $merger): void
    {
        $user = $this->userId ? TenantUser::where('tenant_id', $this->tenantId)->find($this->userId) : null;
        $failed = 0;

        try {
            TenantDuplicateGroup::where('tenant_id', $this->tenantId)->where('status', 'ready')
                ->lazyById(100)
                ->each(function (TenantDuplicateGroup $g) use ($merger, $user, &$failed) {
                    try {
                        $merger->merge($g, [], $user);
                    } catch (\Throwable $e) {
                        $failed++;
                        $g->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
                        if ($failed === 1) {
                            JobFailureReporter::report(self::class, 'Merging duplicate items: a group could not be merged', $e,
                                ['group_id' => $g->id, 'label' => $g->label], $this->tenantId);
                        }
                    }
                });
        } finally {
            cache()->forget(self::runningKey($this->tenantId));
        }
    }

    public function failed(\Throwable $e): void
    {
        cache()->forget(self::runningKey($this->tenantId));
        JobFailureReporter::report(self::class, 'Merging duplicate items stopped', $e, [], $this->tenantId);
    }
}
