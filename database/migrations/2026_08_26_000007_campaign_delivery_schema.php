<?php
// MARKER-CAMPAIGN-DELIVERY

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Defect fix from the ledger patch: campaigns are uuid-keyed, the
        // ledger's campaign_id was bigint. Column has never been written
        // (campaign sends didn't exist), so drop-and-recreate is lossless.
        Schema::table('tenant_email_ledger', function (Blueprint $table) {
            $table->dropIndex(['campaign_id']);
            $table->dropColumn('campaign_id');
        });
        Schema::table('tenant_email_ledger', function (Blueprint $table) {
            $table->uuid('campaign_id')->nullable()->index()->after('status');
        });

        // Worker outcome 'skipped' (no consent / suppressed at send time)
        // alongside the existing pending/sent/failed/bounced.
        DB::statement("ALTER TABLE tenant_campaign_sends MODIFY COLUMN status ENUM('pending','sent','failed','bounced','skipped') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tenant_campaign_sends MODIFY COLUMN status ENUM('pending','sent','failed','bounced') NOT NULL DEFAULT 'pending'");
        Schema::table('tenant_email_ledger', function (Blueprint $table) {
            $table->dropIndex(['campaign_id']);
            $table->dropColumn('campaign_id');
        });
        Schema::table('tenant_email_ledger', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable()->index();
        });
    }
};
