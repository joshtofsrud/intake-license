<?php
// MARKER-PATCH-614 — guardrail: close punches left open past a cap so a
// forgotten clock-out doesn't bill 14h. Flags for manager review + audits.

namespace App\Console\Commands;

use App\Models\Tenant\TenantTimePunch;
use App\Models\Tenant\TenantTimePunchAudit;
use Illuminate\Console\Command;

class AutoCloseTimePunches extends Command
{
    protected $signature = 'timeclock:auto-close {--hours=10 : cap in hours}';
    protected $description = 'Auto-close time punches left open past the cap and flag them for review.';

    public function handle(): int
    {
        $defaultCap = (int) $this->option('hours');

        // All open punches; each tenant's configured cap wins over the default.
        $stale = TenantTimePunch::whereNull('clock_out_at')->get();

        $count = 0;
        foreach ($stale as $punch) {
            $tenant  = \App\Models\Tenant::find($punch->tenant_id);
            $capHours = (int) ($tenant?->settings['timeclock_autoclose_hours'] ?? $defaultCap);
            if ($punch->clock_in_at->gt(now()->subHours($capHours))) continue; // not stale yet
            // Close at cap (clock_in + cap), not now — don't invent hours.
            $closeAt = $punch->clock_in_at->copy()->addHours($capHours);
            $punch->update([
                'clock_out_at' => $closeAt,
                'auto_closed'  => true,
            ]);

            TenantTimePunchAudit::log(
                $punch->tenant_id, $punch->id, $punch->tenant_user_id, null,
                'auto_closed', "Auto-closed at {$capHours}h cap — left open past limit; needs review."
            );
            $count++;
        }

        // MARKER-PATCH-628 — $capHours only exists inside the loop; with zero
        // open punches this line fataled every hour since ship.
        $this->info("Auto-closed {$count} open punch(es).");
        return self::SUCCESS;
    }
}

