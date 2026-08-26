<?php
// MARKER-CAMPAIGNS-MERGE

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The parallel table from the reverted campaigns-core patch. Empty,
        // never referenced; TenantCampaign is the one campaign model.
        Schema::dropIfExists('tenant_email_campaigns');

        // Campaigns the old dead-end send() marked 'sending' — no worker
        // ever existed, so nothing was actually sent. Back to draft, and
        // their pending queue rows go too: they represent no real send,
        // and a future worker must not find and double-queue them.
        $stuck = DB::table('tenant_campaigns')->where('status', 'sending')->pluck('id');
        if ($stuck->isNotEmpty()) {
            DB::table('tenant_campaign_sends')
                ->whereIn('campaign_id', $stuck)
                ->where('status', 'pending')
                ->delete();
            DB::table('tenant_campaigns')
                ->whereIn('id', $stuck)
                ->update(['status' => 'draft', 'sent_at' => null]);
        }
    }

    public function down(): void
    {
        // The dropped table was empty and the repair is a one-way data fix.
    }
};
