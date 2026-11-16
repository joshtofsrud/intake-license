<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// cold prospects in a campaign are sent at the outreach sender's pace
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('platform_campaign_sends', 'paced')) {
            Schema::table('platform_campaign_sends', function (Blueprint $t) {
                $t->boolean('paced')->default(false)->after('status')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('platform_campaign_sends', 'paced')) {
            Schema::table('platform_campaign_sends', fn (Blueprint $t) => $t->dropColumn('paced'));
        }
    }
};
