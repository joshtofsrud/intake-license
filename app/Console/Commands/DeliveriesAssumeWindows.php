<?php
// MARKER-PATCH-534 — assume-first removed: we never show up unannounced.
// (Supersedes the MARKER-PATCH-529 behavior in this same command.)

namespace App\Console\Commands;

use App\Models\Tenant\TenantDeliveryProposal;
use Illuminate\Console\Command;

/**
 * deliveries:assume-windows — repurposed. Pending delivery proposals whose
 * no-reply deadline (expires_at) has passed flip to `no_reply`: nothing is
 * scheduled and no message is sent. The dashboard "Awaiting delivery" tile
 * surfaces these for staff follow-up. Signature kept so the schedule and
 * any muscle memory keep working.
 */
class DeliveriesAssumeWindows extends Command
{
    protected $signature = 'deliveries:assume-windows';
    protected $description = 'Flag unanswered delivery proposals past their deadline as no_reply for dashboard follow-up.';

    public function handle(): int
    {
        // MARKER-DELIVERY-ALERTS — was a single bulk UPDATE, which could not
        // say WHO went unanswered. Now per proposal, so each one raises an
        // alert naming the customer. The status write is unchanged; the
        // alert is best-effort and never blocks the flag.
        $flagged = 0;
        $alerts  = app(\App\Services\Tenant\StaffAlertService::class);

        TenantDeliveryProposal::query()
            ->where('status', TenantDeliveryProposal::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with(['tenant', 'customer'])
            ->orderBy('expires_at')
            ->chunkById(200, function ($proposals) use (&$flagged, $alerts) {
                foreach ($proposals as $proposal) {
                    $proposal->update(['status' => TenantDeliveryProposal::STATUS_NO_REPLY]);
                    $flagged++;

                    if (! $proposal->tenant) {
                        continue;
                    }

                    try {
                        $custName = trim(($proposal->customer->first_name ?? '') . ' ' . ($proposal->customer->last_name ?? '')) ?: 'A customer';
                        $alerts->emit($proposal->tenant, 'delivery.no_reply', [
                            'title' => 'Delivery window unanswered',
                            'body'  => $custName . ' never picked a window — give them a call',
                            'link'  => route('tenant.deliveries.index'),
                            'meta'  => ['proposal_id' => $proposal->id, 'appointment_id' => $proposal->appointment_id],
                        ]);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('Delivery no_reply alert failed', [
                            'proposal_id' => $proposal->id, 'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info("Flagged {$flagged} proposal(s) as no_reply.");
        return self::SUCCESS;
    }
}
