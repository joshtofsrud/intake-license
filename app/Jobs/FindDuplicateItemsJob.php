<?php
// MARKER-DUP-MERGE

namespace App\Jobs;

use App\Services\Inventory\DuplicateItemFinder;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Refreshes one shop's duplicate groups — after an import, and nightly via the command. */
class FindDuplicateItemsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;
    public int $uniqueFor = 600;

    public function __construct(public string $tenantId) {}

    public function uniqueId(): string
    {
        return 'find-duplicates:' . $this->tenantId;
    }

    public function handle(DuplicateItemFinder $finder): void
    {
        $finder->find($this->tenantId);
    }

    public function failed(\Throwable $e): void
    {
        JobFailureReporter::report(self::class, 'Finding duplicate items failed', $e, [], $this->tenantId);
    }
}
