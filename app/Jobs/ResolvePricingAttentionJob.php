<?php
// MARKER-ATTENTION-QUEUE

namespace App\Jobs;

use App\Services\Tenant\PricingAttentionResolver;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** A Catalog attention bulk action over more flags than a web request can finish. */
class ResolvePricingAttentionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(
        public string $tenantId,
        public string $action,
        public array $selection,
        public ?string $userId = null,
        public ?string $userEmail = null,
    ) {}

    /** Set while the run is queued or working, so the page can say so. */
    public static function runningKey(string $tenantId): string
    {
        return 'attention-resolve-running:' . $tenantId;
    }

    public function handle(PricingAttentionResolver $resolver): void
    {
        try {
            $resolver->run($this->tenantId, $this->action, $this->selection, $this->userId, $this->userEmail);
        } finally {
            cache()->forget(self::runningKey($this->tenantId));
        }
    }

    public function failed(\Throwable $e): void
    {
        cache()->forget(self::runningKey($this->tenantId));
        JobFailureReporter::report(self::class, 'Catalog attention bulk action failed', $e,
            ['action' => $this->action], $this->tenantId);
    }
}
