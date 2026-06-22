<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// MARKER-PATCH-396 — collapse per-channel threads into one thread per customer.
// Messages already carry their own channel, so the conversation is preserved.
return new class extends Migration {
    public function up(): void
    {
        $groups = DB::table('tenant_threads')
            ->select('tenant_id', 'customer_id', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('customer_id')
            ->groupBy('tenant_id', 'customer_id')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($groups as $g) {
            $threads = DB::table('tenant_threads')
                ->where('tenant_id', $g->tenant_id)
                ->where('customer_id', $g->customer_id)
                ->orderBy('created_at')
                ->get();

            $survivor   = $threads->first();
            $siblingIds = $threads->slice(1)->pluck('id')->all();
            if (empty($siblingIds)) {
                continue;
            }

            // Move messages onto the survivor BEFORE deleting siblings, so the
            // onDelete('cascade') FK never removes a message.
            DB::table('tenant_messages')
                ->whereIn('thread_id', $siblingIds)
                ->update(['thread_id' => $survivor->id]);

            $unread     = (int) $threads->sum('unread_count');
            $lastAt     = $threads->max('last_message_at');
            $needsReply = $threads->contains(fn ($t) => $t->status === 'needs_reply');

            DB::table('tenant_threads')->where('id', $survivor->id)->update([
                'unread_count'    => $unread,
                'last_message_at' => $lastAt,
                'channel'         => 'mixed',
                'status'          => $needsReply ? 'needs_reply' : $survivor->status,
                'updated_at'      => now(),
            ]);

            DB::table('tenant_threads')->whereIn('id', $siblingIds)->delete();
        }
    }

    public function down(): void
    {
        // Merge is not reversible — messages can't be re-split by channel.
    }
};
