<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-OUTREACH-SENDER: outreach emails can come from a person
 * ("Josh Tofsrud <josh@intake.works>") instead of the platform sender
 * ("Intake <hello@intake.works>"). Blank = the platform sender, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('platform_settings', 'outreach_from_address')) $t->string('outreach_from_address', 191)->nullable();
            if (! Schema::hasColumn('platform_settings', 'outreach_from_name'))    $t->string('outreach_from_name', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $t) {
            foreach (['outreach_from_address', 'outreach_from_name'] as $c) {
                if (Schema::hasColumn('platform_settings', $c)) $t->dropColumn($c);
            }
        });
    }
};
