<?php

// MARKER-DELIVERY-RESOLUTION — a completed job leaves the "Awaiting delivery"
// queue because someone decided something, not because a 14-day window
// forgot about it. Records what was decided, by whom, and when.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $t) {
            // customer_pickup | handed_over | not_needed  (null = still open)
            $t->string('delivery_resolution', 32)->nullable()->index();
            $t->timestamp('delivery_resolved_at')->nullable();
            $t->uuid('delivery_resolved_by_user_id')->nullable();
            // Actively being chased — hidden from the queue until this passes.
            $t->timestamp('delivery_snooze_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $t) {
            $t->dropColumn([
                'delivery_resolution',
                'delivery_resolved_at',
                'delivery_resolved_by_user_id',
                'delivery_snooze_until',
            ]);
        });
    }
};
