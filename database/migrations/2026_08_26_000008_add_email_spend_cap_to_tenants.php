<?php
// MARKER-EMAIL-BILLING

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Monthly ceiling on MARKETING email spend, in cents.
            // NULL = uncapped. Transactional mail ignores this entirely.
            $table->unsignedInteger('email_spend_cap_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('email_spend_cap_cents');
        });
    }
};
