<?php
// MARKER-CAMPAIGNS-QUOTE — Prospects join a channel and carry a built quote.
// quote_monthly is a snapshot-on-write derived from tier + addons at save time
// (design principle 13), so list/funnel reads never re-price.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_prospects', 'channel_id')) {
                $t->uuid('channel_id')->nullable()->after('id');
            }
            if (! Schema::hasColumn('sales_prospects', 'categories')) {
                $t->json('categories')->nullable()->after('type');
            }
            if (! Schema::hasColumn('sales_prospects', 'quote_tier')) {
                $t->string('quote_tier', 40)->nullable()->after('lead_score');
            }
            if (! Schema::hasColumn('sales_prospects', 'quote_addons')) {
                $t->json('quote_addons')->nullable()->after('quote_tier');
            }
            if (! Schema::hasColumn('sales_prospects', 'quote_monthly')) {
                $t->unsignedInteger('quote_monthly')->nullable()->after('quote_addons');
            }
        });

        Schema::table('sales_prospects', function (Blueprint $t) {
            try {
                $t->foreign('channel_id', 'sales_prospects_channel_fk')
                  ->references('id')->on('sales_channels')->nullOnDelete();
            } catch (\Throwable $e) { /* already present */ }
            try { $t->index('channel_id', 'sales_prospects_channel_index'); } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            try { $t->dropForeign('sales_prospects_channel_fk'); } catch (\Throwable $e) {}
            try { $t->dropIndex('sales_prospects_channel_index'); } catch (\Throwable $e) {}
            foreach (['channel_id', 'categories', 'quote_tier', 'quote_addons', 'quote_monthly'] as $col) {
                if (Schema::hasColumn('sales_prospects', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
