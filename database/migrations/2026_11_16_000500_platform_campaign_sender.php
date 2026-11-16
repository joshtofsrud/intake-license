<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which sender a campaign goes out as: 'platform' (Intake) or 'outreach'
 * (your own name and address). Blank = automatic, outreach for prospects.
 * Replaces from_name / from_email, which nothing ever filled or read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_campaigns', function (Blueprint $t) {
            if (! Schema::hasColumn('platform_campaigns', 'sender')) {
                $t->string('sender', 16)->nullable()->after('preheader');
            }
        });
        foreach (['from_name', 'from_email'] as $col) {
            if (Schema::hasColumn('platform_campaigns', $col)) {
                Schema::table('platform_campaigns', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }

    public function down(): void
    {
        Schema::table('platform_campaigns', function (Blueprint $t) {
            if (! Schema::hasColumn('platform_campaigns', 'from_name'))  $t->string('from_name', 120)->nullable();
            if (! Schema::hasColumn('platform_campaigns', 'from_email')) $t->string('from_email', 191)->nullable();
            if (Schema::hasColumn('platform_campaigns', 'sender'))       $t->dropColumn('sender');
        });
    }
};
