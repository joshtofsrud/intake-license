<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-CONNECTED-POS-SECTION: Connected POS becomes its own area.
 *
 *  - addons row 'connected_pos': free, Branded and up, shops turn it on themselves
 *  - shops that already have a Connected POS keep it: the add-on is switched on for them
 *  - roles that can open E-commerce (where this used to live) can open Connected POS
 *  - the connection learns its mode (2 Way Data / Reading Only), whether matches link on
 *    their own, and the state of an import run
 *  - Connected POS products keep the category, supplier, cost and stock-tracking the POS
 *    has for them, and when Intake first saw them (for "new since your last import")
 *
 * Nothing is deleted and no item is changed. Additive throughout.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('addons') && ! DB::table('addons')->where('code', 'connected_pos')->exists()) {
            $like = DB::table('addons')->where('code', 'online_store')->first();
            DB::table('addons')->insert([
                'code'                   => 'connected_pos',
                'name'                   => 'Connected POS',
                'category'               => $like->category ?? 'operations',
                'description'            => 'Connect the POS your shop already uses. Bring in the items you choose and your customers, keep price and stock in step, and send online orders back to it. Also how a shop moves over to Intake.',
                'tooltip'                => 'Your outside POS, connected: items on demand, customers, price and stock, online orders sent back.',
                'price_cents'            => 0,
                'billing_cadence'        => 'monthly',
                'price_display_override' => 'Free',
                'included_in_plans'      => null,
                'min_plan_tier'          => 'branded',
                'sort_order'             => ((int) ($like->sort_order ?? 130)) + 1,
                'status'                 => 'active',
                'is_self_serve'          => 1,
                'visibility'             => 'self_serve',
                'is_new'                 => 1,
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);
        }

        Schema::table('tenant_pos_connections', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_pos_connections', 'mode')) {
                $t->string('mode', 12)->default('two_way');   // two_way | read_only
                $t->boolean('auto_link')->default(true);       // link barcode / part-number matches on their own
                $t->json('import_state')->nullable();          // the Inventory › Import run, for live progress and Stop/Resume
                $t->timestamp('items_marked_at')->nullable();  // products first seen after this are "new"
                $t->timestamp('switched_at')->nullable();      // when the shop switched over to Intake
            }
        });

        Schema::table('tenant_pos_items', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_pos_items', 'category')) {
                $t->string('category', 190)->nullable();
                $t->string('supplier', 190)->nullable();
                $t->string('supplier_code', 100)->nullable();
                $t->integer('cost_cents')->nullable();
                $t->boolean('track_stock')->default(true);
                $t->text('description')->nullable();
                $t->timestamp('first_seen_at')->nullable();
                $t->index(['tenant_id', 'first_seen_at'], 'tpi_tenant_seen');
            }
        });

        // products already known are not "new"
        DB::table('tenant_pos_items')->whereNull('first_seen_at')->update(['first_seen_at' => DB::raw('COALESCE(synced_at, NOW())')]);
        DB::table('tenant_pos_connections')->whereNull('items_marked_at')->update(['items_marked_at' => $now]);

        // shops already connected keep everything they have
        if (Schema::hasTable('tenant_feature_addons')) {
            foreach (DB::table('tenant_pos_connections')->pluck('tenant_id')->unique() as $tenantId) {
                $has = DB::table('tenant_feature_addons')->where('tenant_id', $tenantId)->where('addon_code', 'connected_pos')
                    ->whereIn('status', ['active', 'canceling', 'failed_payment'])->exists();
                if (! $has) {
                    DB::table('tenant_feature_addons')->insert([
                        'tenant_id'    => $tenantId,
                        'addon_code'   => 'connected_pos',
                        'source'       => 'self_serve',
                        'status'       => 'active',
                        'activated_at' => $now,
                        'metadata'     => json_encode(['reason' => 'already had a Connected POS when the add-on was introduced']),
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ]);
                }
            }
        }

        if (Schema::hasTable('tenant_roles')) {
            DB::table('tenant_roles')->whereNotNull('sections')->orderBy('id')->chunk(500, function ($roles) {
                foreach ($roles as $r) {
                    $s = json_decode($r->sections, true);
                    // whoever could reach it where it used to live (E-commerce) keeps it
                    if (! is_array($s) || in_array('connected_pos', $s, true) || ! in_array('storefront', $s, true)) {
                        continue;
                    }
                    $s[] = 'connected_pos';
                    DB::table('tenant_roles')->where('id', $r->id)->update(['sections' => json_encode(array_values($s))]);
                }
            });
        }
    }

    public function down(): void
    {
        // The add-on row and the columns are left in place: removing them would cut a
        // connected shop off from its own Connected POS.
    }
};
