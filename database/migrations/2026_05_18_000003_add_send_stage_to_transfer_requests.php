<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * patch-102: add in_transit stage to transfer requests.
 *
 * quantity_sent — what source actually sent (may differ from quantity requested)
 * sent_at, sent_by_user_id — who acted on the send and when
 *
 * Note: fulfilled_at + fulfilled_by_user_id existing columns are
 * REUSED to mean "received" semantically. Saves a schema churn.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_transfer_requests', function (Blueprint $table) {
            $table->integer('quantity_sent')->nullable()->after('quantity');
            $table->timestamp('sent_at')->nullable()->after('quantity_sent');
            $table->uuid('sent_by_user_id')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_transfer_requests', function (Blueprint $table) {
            $table->dropColumn(['quantity_sent', 'sent_at', 'sent_by_user_id']);
        });
    }
};
