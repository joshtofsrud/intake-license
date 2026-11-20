<?php

namespace App\Jobs;

use App\Services\Pos\PosPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-POS-DRAIN: retired. Sends are now worked by one PosDrainJob per shop.
 * Kept only so jobs already waiting in the queue finish instantly: each one just
 * makes sure its shop's worker is coming, and calls nothing.
 */
class PosPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $pushId) {}

    public function handle(): void
    {
        $tenantId = DB::table('tenant_pos_pushes')->where('id', $this->pushId)->where('status', 'pending')->value('tenant_id');
        if ($tenantId) {
            PosPushService::kick($tenantId);
        }
    }
}
