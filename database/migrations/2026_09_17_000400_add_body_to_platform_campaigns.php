<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MARKER-PLATFORM-CAMPAIGNS-UI — campaigns are written as text, like templates. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_campaigns', function (Blueprint $t) {
            $t->text('body')->nullable()->after('preheader');
        });
    }

    public function down(): void
    {
        Schema::table('platform_campaigns', function (Blueprint $t) {
            $t->dropColumn('body');
        });
    }
};
