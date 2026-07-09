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
        $capHours = (int) $this->option('hours');
        $cutoff   = now()->subHours($capHours);

        // Open punches whose clock-in is older than the cap.
        $stale = TenantTimePunch::whereNull('clock_out_at')
            ->where('clock_in_at', '<=', $cutoff)
            ->get();

        $count = 0;
        foreach ($stale as $punch) {
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

        $this->info("Auto-closed {$count} open punch(es) past {$capHours}h.");
        return self::SUCCESS;
    }
}

