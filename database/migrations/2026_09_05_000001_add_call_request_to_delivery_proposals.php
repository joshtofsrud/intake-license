<?php
// MARKER-DELIVERY-CALL

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_delivery_proposals', function (Blueprint $table) {
            // What the customer typed when they asked for a call. Free text,
            // shown to staff verbatim. Never used for routing.
            $table->string('call_note', 200)->nullable()->after('confirmed_at');
            $table->timestamp('call_requested_at')->nullable()->after('call_note');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_delivery_proposals', function (Blueprint $table) {
            $table->dropColumn(['call_note', 'call_requested_at']);
        });
    }
};
