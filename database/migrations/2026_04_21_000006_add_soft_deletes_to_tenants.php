<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add soft-delete support to tenants.
 *
 * Master admin deletes set deleted_at but preserve the row and all FK-related
 * data. Soft-deleted tenants hide from admin lists and their subdomains 404,
 * but data can be recovered if deletion was a mistake.
 *
 * Hard purge (physical DELETE) is a separate operation — manually via SQL
 * or a scheduled job after 30+ days of soft-delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->softDeletes()->after('updated_at');
            $t->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropIndex(['deleted_at']);
            $t->dropSoftDeletes();
        });
    }
};
