<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            // IANA timezone string, e.g. 'America/Los_Angeles'.
            // Default chosen to be a US zone — any new tenant gets a working
            // calendar even if onboarding doesn't ask. Tenants in other zones
            // change it in Settings.
            $t->string('timezone', 64)->default('America/Los_Angeles')->after('currency_symbol');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn('timezone');
        });
    }
};
