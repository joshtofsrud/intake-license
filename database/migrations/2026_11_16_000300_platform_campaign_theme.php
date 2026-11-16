<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// master-admin campaigns: send a letter in light or dark
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('platform_campaigns', 'theme')) {
            Schema::table('platform_campaigns', function (Blueprint $t) {
                $t->string('theme', 10)->default('light')->after('blocks');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('platform_campaigns', 'theme')) {
            Schema::table('platform_campaigns', fn (Blueprint $t) => $t->dropColumn('theme'));
        }
    }
};
