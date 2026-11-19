<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MARKER-POS-CALLBACK: the OAuth callback URL is a master-admin setting per POS, not code. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('platform_pos_adapters', 'redirect_uri')) {
            Schema::table('platform_pos_adapters', function (Blueprint $t) {
                $t->string('redirect_uri', 300)->nullable()->after('api_base');
            });
        }
    }

    public function down(): void
    {
        Schema::table('platform_pos_adapters', function (Blueprint $t) {
            $t->dropColumn('redirect_uri');
        });
    }
};
