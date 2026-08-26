<?php
// MARKER-EMAIL-LEDGER

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            // Dollars per email. Changing it affects sends from that moment on;
            // ledger rows keep the rate they were written with.
            $table->decimal('email_rate', 8, 5)->default(0.002);

            // Postmark broadcast message stream ID. Until set, campaign sends
            // are blocked (enforced in the campaign pipeline) — marketing must
            // never ride the transactional stream.
            $table->string('email_broadcast_stream', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn(['email_rate', 'email_broadcast_stream']);
        });
    }
};
