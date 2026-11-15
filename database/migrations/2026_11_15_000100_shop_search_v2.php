<?php
// MARKER-SHOP-SEARCH-V2: shop search with photos, promotions, pin/boost/hide and
// the search-to-purchase report.
//
//   tenant_search_rules         + item_id (pin / boost / hide), + enabled. Types now:
//                                 synonym | redirect | stop | pin | boost | hide
//   search_defaults             what every shop starts with (master admin): synonyms, stop words
//   tenant_search_default_offs  a shop switching one Intake default off for itself
//   tenant_search_promotions    image + headline + link, shown for words or categories
//   tenant_search_events        click / add / promo_click, tied to the logged search
//   tenant_search_dismissals    no-results searches a shop has looked at
//   tenant_inventory_items      + compare_at_cents (the Sale badge and the crossed-out price)
//   tenant_inventory_categories + image_url (category tiles)
//   platform_settings           + search_settings (which plan gets which search feature)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_search_rules', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_search_rules', 'item_id')) {
                $t->uuid('item_id')->nullable()->after('to_value');
            }
            if (! Schema::hasColumn('tenant_search_rules', 'enabled')) {
                $t->boolean('enabled')->default(true)->after('label');
            }
        });
        // a search can now pin one item and hide another, so one rule per word and type is too strict
        try {
            Schema::table('tenant_search_rules', function (Blueprint $t) {
                $t->index(['tenant_id', 'type'], 'tsr_tenant_type_idx');
            });
        } catch (\Throwable $e) { /* already there */ }
        try {
            Schema::table('tenant_search_rules', function (Blueprint $t) {
                $t->dropUnique('tsr_tenant_type_from_unique');
            });
        } catch (\Throwable $e) { /* already gone */ }

        if (! Schema::hasTable('search_defaults')) {
            Schema::create('search_defaults', function (Blueprint $t) {
                $t->id();
                $t->string('type', 12);                 // synonym | stop
                $t->string('from_term', 120);
                $t->string('to_value', 120)->nullable(); // synonym target; null for a stop word
                $t->boolean('enabled')->default(true);
                $t->unsignedInteger('sort')->default(0);
                $t->timestamps();
                $t->index(['type', 'enabled']);
            });

            $now  = now();
            $rows = [];
            $i    = 0;
            foreach ([
                'mtb' => 'mountain bike', 'derailer' => 'derailleur', 'mudguard' => 'fender', 'cycle' => 'bike',
                'bicycle' => 'bike', 'wheelset' => 'wheel', 'innertube' => 'tube', 'tv' => 'television',
                'headset' => 'headphones', 'cord' => 'cable', 'tee' => 't-shirt', 'sneakers' => 'trainers',
            ] as $from => $to) {
                $rows[] = ['type' => 'synonym', 'from_term' => $from, 'to_value' => $to, 'enabled' => true, 'sort' => $i++, 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (['the', 'for', 'a', 'an', 'and', 'of', 'with', 'in', 'to', 'my'] as $w) {
                $rows[] = ['type' => 'stop', 'from_term' => $w, 'to_value' => null, 'enabled' => true, 'sort' => $i++, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('search_defaults')->insert($rows);
        }

        if (! Schema::hasTable('tenant_search_default_offs')) {
            Schema::create('tenant_search_default_offs', function (Blueprint $t) {
                $t->id();
                $t->uuid('tenant_id');
                $t->unsignedBigInteger('default_id');
                $t->timestamps();
                $t->unique(['tenant_id', 'default_id'], 'tsdo_tenant_default_unique');
            });
        }

        if (! Schema::hasTable('tenant_search_promotions')) {
            Schema::create('tenant_search_promotions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id')->index();
                $t->string('title', 120);
                $t->string('subtitle', 160)->nullable();
                $t->string('image_url', 500)->nullable();
                $t->uuid('image_media_id')->nullable();
                $t->string('link_type', 12)->default('url');   // category | page | brand | item | url
                $t->string('link_value', 500)->nullable();
                $t->string('button_label', 40)->nullable();
                $t->json('keywords')->nullable();
                $t->json('category_ids')->nullable();
                $t->json('placements')->nullable();            // dropdown | banner | card
                $t->date('starts_on')->nullable();
                $t->date('ends_on')->nullable();
                $t->boolean('enabled')->default(true);
                $t->unsignedInteger('seen_count')->default(0);
                $t->unsignedInteger('click_count')->default(0);
                $t->uuid('created_by')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('tenant_search_events')) {
            Schema::create('tenant_search_events', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id');
                $t->uuid('query_id')->nullable();
                $t->string('query', 190)->nullable();
                $t->string('kind', 16);                 // click | add | promo_click
                $t->uuid('item_id')->nullable();
                $t->uuid('promotion_id')->nullable();
                $t->uuid('order_id')->nullable();       // the cart the item went into (the cart row becomes the order)
                $t->string('session_id', 64)->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['tenant_id', 'kind', 'created_at'], 'tse_tenant_kind_at_idx');
                $t->index('order_id', 'tse_order_idx');
                $t->index('query_id', 'tse_query_idx');
            });
        }

        if (! Schema::hasTable('tenant_search_dismissals')) {
            Schema::create('tenant_search_dismissals', function (Blueprint $t) {
                $t->id();
                $t->uuid('tenant_id');
                $t->string('query', 190);
                $t->uuid('dismissed_by')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'query'], 'tsd_tenant_query_unique');
            });
        }

        Schema::table('tenant_inventory_items', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_inventory_items', 'compare_at_cents')) {
                $t->unsignedInteger('compare_at_cents')->nullable();
            }
        });
        Schema::table('tenant_inventory_categories', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_inventory_categories', 'image_url')) {
                $t->string('image_url', 500)->nullable();
            }
        });
        Schema::table('platform_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('platform_settings', 'search_settings')) {
                $t->json('search_settings')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_search_dismissals');
        Schema::dropIfExists('tenant_search_events');
        Schema::dropIfExists('tenant_search_promotions');
        Schema::dropIfExists('tenant_search_default_offs');
        Schema::dropIfExists('search_defaults');
        Schema::table('platform_settings', fn (Blueprint $t) => $t->dropColumn('search_settings'));
        Schema::table('tenant_inventory_categories', fn (Blueprint $t) => $t->dropColumn('image_url'));
        Schema::table('tenant_inventory_items', fn (Blueprint $t) => $t->dropColumn('compare_at_cents'));
        Schema::table('tenant_search_rules', function (Blueprint $t) {
            $t->dropColumn(['item_id', 'enabled']);
        });
    }
};
