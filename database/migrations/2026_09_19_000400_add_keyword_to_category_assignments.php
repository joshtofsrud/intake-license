<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-UNCAT-CARVE — what the operator searched for when they assigned.
 *
 * Lets the screen show which keywords have already been worked through in a
 * bucket, and how many each one took, without a second table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_category_assignments', function (Blueprint $t) {
            $t->string('keyword', 120)->nullable()->after('bucket_key');
            $t->string('source_key', 64)->nullable()->after('keyword');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_category_assignments', function (Blueprint $t) {
            $t->dropColumn(['keyword', 'source_key']);
        });
    }
};
