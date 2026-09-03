<?php
// MARKER-EMAIL-RATES

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            // email_rate keeps its meaning: the transactional rate.
            $table->decimal('email_rate_marketing', 8, 5)->default(0.0035)->after('email_rate');
            $table->unsignedInteger('email_free_monthly')->default(0)->after('email_rate_marketing');
        });

        Schema::table('tenants', function (Blueprint $table) {
            // null = use the platform default; 0 = deliberately none.
            $table->unsignedInteger('email_free_monthly')->nullable()->after('email_spend_cap_cents');
        });

        Schema::table('tenant_email_ledger', function (Blueprint $table) {
            // Flagged as well as zero-rated, so a free row is distinguishable
            // from a shop on its own Twilio or a genuine metering failure.
            $table->boolean('is_free')->default(false)->after('segments')->index();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn(['email_rate_marketing', 'email_free_monthly']);
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('email_free_monthly');
        });
        Schema::table('tenant_email_ledger', function (Blueprint $table) {
            $table->dropColumn('is_free');
        });
    }
};
