<?php
// MARKER-AUTO-REPLY: flag automatic replies (help-desk receipts, out of office)
// on platform threads, then clean up the ones already stored: label them, take
// back the lead score they earned, and put a prospect email thread that only
// got an automatic answer back where it was (archived).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_inbox_replies', function (Blueprint $t) {
            if (! Schema::hasColumn('platform_inbox_replies', 'is_auto')) $t->boolean('is_auto')->default(false);
        });

        try {
            $rows = \App\Models\PlatformInboxReply::where('direction', 'in')->where('is_auto', false)->get();
            foreach ($rows as $r) {
                if (! \App\Support\AutoReply::detectText('', (string) $r->body)) continue;
                $r->forceFill(['is_auto' => true])->save();
                $m = \App\Models\PlatformInboxMessage::find($r->message_id);
                if (! $m) continue;
                $realLeft = \App\Models\PlatformInboxReply::where('message_id', $m->id)->where('direction', 'in')->where('is_auto', false)->exists();
                if ($realLeft) continue;
                \App\Support\AutoReply::undoReplyCredit($m);
                if ((($m->meta ?? [])['origin'] ?? null) === 'prospect_email' && $m->status === 'new') {
                    $m->forceFill(['status' => 'archived'])->save();
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function down(): void {}
};
