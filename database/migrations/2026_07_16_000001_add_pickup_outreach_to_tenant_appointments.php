<?php

// MARKER-PICKUP-OUTREACH — bookings that skipped the pickup-window choice
// carry a pending flag until staff arrange pickup (assigning a route window
// or clearing it manually clears the flag).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $t) {
            $t->boolean('pickup_outreach_pending')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $t) {
            $t->dropColumn('pickup_outreach_pending');
        });
    }
};
