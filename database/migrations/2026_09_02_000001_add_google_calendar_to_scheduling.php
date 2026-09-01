<?php
// MARKER-SCHED-GOOGLE

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_bookings', function (Blueprint $table) {
            $table->string('google_event_id', 191)->nullable()->after('location_detail');
        });

        // Busy blocks pulled from the connected calendar. Replaced wholesale
        // on every sync for the booking window, so there's no external id.
        Schema::create('platform_booking_busy', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16)->default('google');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('summary', 191)->nullable(); // never shown publicly
            $table->timestamps();
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_booking_busy');
        Schema::table('platform_bookings', function (Blueprint $table) {
            $table->dropColumn('google_event_id');
        });
    }
};
