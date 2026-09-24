<?php
// MARKER-PRICE-SEED

namespace App\Jobs;

use App\Services\Distributors\DistributorPricingService;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Moves a distributor's untouched item prices to MSRP or MAP, in the background. */
class SweepDistributorPricingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(
        public string $tenantId,
        public string $code,
        public string $target,
        public ?string $userId = null,
        public ?string $userEmail = null,
    ) {}

    public static function runningKey(string $tenantId, string $code): string
    {
        return 'pricing-sweep-running:' . $tenantId . ':' . strtoupper($code);
    }

    public function handle(DistributorPricingService $pricing): void
    {
        try {
            $pricing->sweep($this->tenantId, $this->code, $this->target, $this->userId, $this->userEmail);
        } finally {
            cache()->forget(self::runningKey($this->tenantId, $this->code));
        }
    }

    public function failed(\Throwable $e): void
    {
        cache()->forget(self::runningKey($this->tenantId, $this->code));
        JobFailureReporter::report(self::class, strtoupper($this->code) . ' pricing sweep failed', $e,
            ['code' => $this->code, 'target' => $this->target], $this->tenantId);
    }
}
