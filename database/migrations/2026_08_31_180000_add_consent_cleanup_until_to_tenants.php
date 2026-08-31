<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-CONSENT-CLEANUP — time-boxed onboarding window. Null = off, which
// is the state every tenant starts and ends in.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'consent_cleanup_until')) {
                $table->timestamp('consent_cleanup_until')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('consent_cleanup_until');
        });
    }
};
