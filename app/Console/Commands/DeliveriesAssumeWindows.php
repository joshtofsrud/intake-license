<?php
// MARKER-PATCH-529

namespace App\Console\Commands;

use App\Http\Controllers\Tenant\DeliveryConfirmController;
use App\Models\Tenant;
use App\Models\Tenant\TenantDeliveryProposal;
use App\Models\Tenant\TenantRouteWindow;
use App\Services\Tenant\TenantDeliveryNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * deliveries:assume-windows — the assume-first fallback.
 *
 * Pending delivery proposals whose assume-by deadline (expires_at) has
 * passed get their first still-open window locked in automatically:
 * we create the TenantDelivery, mark the proposal `assumed`, and send
 * the standard "scheduled" notification. If every proposed window is
 * gone (full / deactivated / date passed), the proposal is `expired`
 * and staff follow up manually.
 *
 * Runs every 15 minutes; status transition is the idempotence guard.
 */
class DeliveriesAssumeWindows extends Command
{
    protected $signature = 'deliveries:assume-windows';
    protected $description = 'Lock in the first proposed delivery window for unanswered proposals past their deadline.';

    public function handle(): int
    {
        $due = TenantDeliveryProposal::query()
            ->where('status', TenantDeliveryProposal::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit(200)
            ->get();

        $assumed = 0; $expired = 0;

        foreach ($due as $proposal) {
            try {
                $tenant = Tenant::find($proposal->tenant_id);
                if (!$tenant || !$tenant->deliveries_enabled) {
                    $proposal->update(['status' => TenantDeliveryProposal::STATUS_EXPIRED]);
                    $expired++;
                    continue;
                }

                $tz     = $tenant->timezone();
                $picked = null; $pickedWindow = null; $pickedDay = null;

                foreach ($proposal->windows as $w) {
                    $day = Carbon::parse($w['date'], $tz);
                    if ($day->lt(Carbon::now($tz)->startOfDay()->addDay())) continue; // never same-day/past
                    $window = TenantRouteWindow::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('id', $w['window_id'])
                        ->first();
                    if (!$window || !$window->is_active || !$window->runsOn($day)) continue;
                    if ($window->remainingStops($day) < 1) continue;
                    $picked = $w; $pickedWindow = $window; $pickedDay = $day;
                    break;
                }

                if (!$picked) {
                    $proposal->update(['status' => TenantDeliveryProposal::STATUS_EXPIRED]);
                    $expired++;
                    continue;
                }

                $delivery = DeliveryConfirmController::materialize($tenant, $proposal, $pickedWindow, $pickedDay);

                $proposal->update([
                    'status'              => TenantDeliveryProposal::STATUS_ASSUMED,
                    'confirmed_window_id' => $pickedWindow->id,
                    'confirmed_date'      => $pickedDay->toDateString(),
                    'delivery_id'         => $delivery->id,
                    'confirmed_at'        => now(),
                ]);

                try {
                    TenantDeliveryNotificationService::forTenant($tenant)->sendScheduled($delivery);
                } catch (\Throwable $e) {
                    Log::error('Assume-first notification failed', [
                        'proposal_id' => $proposal->id, 'error' => $e->getMessage(),
                    ]);
                }
                $assumed++;
            } catch (\Throwable $e) {
                Log::error('Assume-first failed for proposal', [
                    'proposal_id' => $proposal->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Assumed {$assumed}, expired {$expired}, scanned {$due->count()}.");
        return self::SUCCESS;
    }
}
