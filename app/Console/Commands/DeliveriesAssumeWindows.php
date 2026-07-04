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
        $flagged = TenantDeliveryProposal::query()
            ->where('status', TenantDeliveryProposal::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => TenantDeliveryProposal::STATUS_NO_REPLY]);

        $this->info("Flagged {$flagged} proposal(s) as no_reply.");
        return self::SUCCESS;
    }
}
