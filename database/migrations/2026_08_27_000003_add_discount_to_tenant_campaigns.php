<?php
// MARKER-CAMPAIGN-ATTRIBUTION

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_campaigns', function (Blueprint $table) {
            // The discount code this campaign promotes. Nullable: most
            // campaigns are just news. No FK — a code may be deleted while
            // it was never used, and that must not take the campaign with it.
            $table->uuid('discount_id')->nullable()->after('targeting');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_campaigns', function (Blueprint $table) {
            $table->dropColumn('discount_id');
        });
    }
};
