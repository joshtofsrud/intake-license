<?php

namespace App\Console\Commands;

use App\Models\SalesActivity;
use App\Models\SalesCommissionEntry;
use App\Models\SalesProspect;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-SALES-RESET — delete every sales prospect and its timeline.
 *
 * Keeps reps, agencies, industries, territories, Places search history and
 * spend. Commission entries keep their money lines; only their link to the
 * deleted prospect is cleared.
 */
class SalesDeleteProspects extends Command
{
    protected $signature   = 'sales:delete-prospects {--force : Skip the typed confirmation}';
    protected $description = 'Delete every sales prospect with its timeline (keeps reps, agencies, industries, territories and Places spend)';

    public function handle(): int
    {
        $prospects  = SalesProspect::query()->count();
        $activities = SalesActivity::query()->count();
        $linked     = SalesProspect::query()->whereNotNull('tenant_id')->count();
        $invited    = SalesProspect::query()->whereNotNull('invited_at')->count();
        $commission = SalesCommissionEntry::query()->whereNotNull('sales_prospect_id')->count();

        $this->line("Prospects:                 {$prospects}");
        $this->line("Timeline entries:          {$activities}");
        $this->line("Linked to a tenant:        {$linked} (the tenants themselves are not touched)");
        $this->line("Trial invites outstanding: {$invited} (their links will stop working)");
        $this->line("Commission entries:        {$commission} (kept; their prospect link is cleared)");

        if ($prospects === 0) {
            $this->info('Nothing to delete.');
            return self::SUCCESS;
        }

        if (! $this->option('force') && $this->ask('Type DELETE to remove all of them') !== 'DELETE') {
            $this->warn('Cancelled. Nothing was deleted.');
            return self::FAILURE;
        }

        DB::transaction(function () {
            SalesCommissionEntry::query()->whereNotNull('sales_prospect_id')->update(['sales_prospect_id' => null]);
            SalesActivity::query()->delete();
            SalesProspect::query()->delete();
        });

        $this->info("Deleted {$prospects} prospects and {$activities} timeline entries.");
        return self::SUCCESS;
    }
}
