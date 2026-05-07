<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a generic metadata JSON column to tenant_sales.
 *
 * First use case: cash-pays-for-class flow. When the admin picks "Cash" on a
 * class registration, we open a draft sale with the class drop-in as a line
 * item and stash the class_session_id in metadata. When the sale completes,
 * a hook in SaleService::commitDraft() reads metadata.class_session_id and
 * calls ClassRegistrationService::register() to actually add the customer.
 *
 * Future use cases (don't add columns for these unless they become first-class):
 *   - gift card recipient details
 *   - promo / coupon code applied
 *   - lead source attribution
 *   - kitchen / preparation notes
 *
 * Promotion criteria: if you find yourself querying `metadata->>'$.foo'` more
 * than twice, OR if a metadata key drives logic in 5+ places, do another
 * migration and pull it out into its own typed column.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_sales', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sales', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
