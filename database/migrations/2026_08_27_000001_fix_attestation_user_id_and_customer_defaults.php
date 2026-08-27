<?php
// MARKER-CONSENT-IMPORT-FIX

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- 1. confirmed_by_user_id must be a UUID -------------------
        // The column was created as unsignedBigInteger, so every insert
        // failed with 1366 and the table is empty. Nothing to preserve.
        Schema::table('tenant_consent_attestations', function (Blueprint $table) {
            $table->dropColumn('confirmed_by_user_id');
        });
        Schema::table('tenant_consent_attestations', function (Blueprint $table) {
            $table->uuid('confirmed_by_user_id')->nullable()->after('wording');
        });

        // --- 2. DATA REPAIR ------------------------------------------
        // Customers marked consented by the broken attestation flow have no
        // attestation row standing behind them. Reset them to unconfirmed so
        // the shop re-runs the flow and ends up with consent that can be
        // evidenced. Scoped to source='attestation', which nothing else writes.
        $hasAttestations = DB::table('tenant_consent_attestations')->exists();
        if (! $hasAttestations) {
            DB::table('tenant_customers')
                ->where('email_marketing_consent_source', 'attestation')
                ->update([
                    'email_marketing_consent_at'     => null,
                    'email_marketing_consent_source' => null,
                ]);
        }

        // --- 3. Text columns get a default ---------------------------
        // A missing name should never take down a write. DEFAULT '' rather
        // than nullable, so nothing downstream has to handle a null string.
        DB::statement("ALTER TABLE tenant_customers MODIFY first_name VARCHAR(255) NOT NULL DEFAULT ''");
        DB::statement("ALTER TABLE tenant_customers MODIFY last_name VARCHAR(255) NOT NULL DEFAULT ''");
    }

    public function down(): void
    {
        Schema::table('tenant_consent_attestations', function (Blueprint $table) {
            $table->dropColumn('confirmed_by_user_id');
        });
        Schema::table('tenant_consent_attestations', function (Blueprint $table) {
            $table->unsignedBigInteger('confirmed_by_user_id')->nullable();
        });
        DB::statement("ALTER TABLE tenant_customers MODIFY first_name VARCHAR(255) NOT NULL");
        DB::statement("ALTER TABLE tenant_customers MODIFY last_name VARCHAR(255) NOT NULL");
    }
};
