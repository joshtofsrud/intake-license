<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\AddonManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * addons:expire
 *
 * Runs daily. Expires every tenant_feature_addons row whose status is 'canceling'
 * and whose current_period_end has passed.
 *
 * Matches waitlist:expire which runs on the existing intake-scheduler.
 */
class ExpireAddonsCommand extends Command
{
    protected $signature = 'addons:expire';

    protected $description = 'Expire addons whose current_period_end has passed.';

    public function handle(AddonManagementService $manager): int
    {
        $rows = DB::table('tenant_feature_addons')
            ->where('status', 'canceling')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->get();

        $count = 0;

        foreach ($rows as $row) {
            $tenant = Tenant::find($row->tenant_id);
            if (! $tenant) continue;

            $manager->expire($tenant, $row->addon_code, [
                'actor_type' => 'system',
                'actor_label' => 'addons:expire scheduler',
                'reason' => 'current_period_end reached',
            ]);

            $count++;
        }

        $this->info("Expired {$count} addon(s).");
        return self::SUCCESS;
    }
}
