<?php
// MARKER-SALES-INVITE — additive only.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_prospects', 'invite_token'))  $t->string('invite_token', 64)->nullable()->unique()->after('tenant_id');
            if (! Schema::hasColumn('sales_prospects', 'invite_email'))  $t->string('invite_email', 191)->nullable()->after('invite_token');
            if (! Schema::hasColumn('sales_prospects', 'invite_plan'))   $t->string('invite_plan', 20)->nullable()->after('invite_email');
            if (! Schema::hasColumn('sales_prospects', 'invited_at'))    $t->timestamp('invited_at')->nullable()->after('invite_plan');
            if (! Schema::hasColumn('sales_prospects', 'converted_at'))  $t->timestamp('converted_at')->nullable()->after('invited_at');
        });
    }

    public function down(): void {}
};
