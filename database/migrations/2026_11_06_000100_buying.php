<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-BUYING: the Buying add-on.
 *
 *  - addons row 'buying': included with Scale, shown to other shops as
 *    "ask about it" until a price is set in master admin (Add-ons)
 *  - tenant_buying_searches: register and online-store searches that found
 *    nothing in stock, one row per query per day per place, so they can be
 *    counted over a window and pruned by date
 *  - tenant_buying_goals: Open to buy goals, per category per location
 *  - roles that can open Purchase orders can open Buying
 *
 * Additive throughout.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        if (Schema::hasTable('addons')) {
            $exists = DB::table('addons')->where('code', 'buying')->exists();
            if (! $exists) {
                DB::table('addons')->insert([
                    'code'                   => 'buying',
                    'name'                   => 'Buying',
                    'category'               => 'operations',
                    'description'            => 'See which items are really selling, how long your stock will last counting what\'s on order, what your distributors have left, and what to order. Includes searches that found nothing and Open to buy. Included with Scale.',
                    'tooltip'                => 'Moving items, days of stock, on order, distributor stock, unanswered searches, Open to buy, add to a PO.',
                    'price_cents'            => 0,
                    'billing_cadence'        => 'monthly',
                    'price_display_override' => null,
                    'included_in_plans'      => json_encode(['scale', 'custom']),
                    'sort_order'             => 135,
                    'status'                 => 'active',
                    'is_self_serve'          => 0,
                    // shops see it and ask until a price is set; then switch to self-serve in master admin
                    'visibility'             => 'ask',
                    'is_new'                 => 1,
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ]);
            }
        }

        if (! Schema::hasTable('tenant_buying_searches')) {
            Schema::create('tenant_buying_searches', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id');
                $t->string('source', 16);              // register | online
                $t->string('location_id', 36)->default(''); // register's location; '' online (unique keys ignore NULLs)
                $t->string('query', 120);              // as typed (last spelling seen)
                $t->string('query_key', 120);          // lower-cased, single-spaced
                $t->date('day');
                $t->unsignedInteger('times')->default(0);
                $t->uuid('closest_item_id')->nullable();
                $t->integer('closest_stock')->nullable();
                $t->timestamp('last_at')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'source', 'location_id', 'query_key', 'day'], 'tbs_unique');
                $t->index(['tenant_id', 'day'], 'tbs_tenant_day_idx');
            });
        }

        if (! Schema::hasTable('tenant_buying_dismissals')) {
            Schema::create('tenant_buying_dismissals', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id');
                $t->string('query_key', 120);
                $t->timestamp('dismissed_at');
                $t->uuid('dismissed_by_user_id')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'query_key'], 'tbd_unique');
            });
        }

        if (! Schema::hasTable('tenant_buying_goals')) {
            Schema::create('tenant_buying_goals', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id');
                $t->uuid('location_id');
                $t->string('category_id', 36)->default(''); // '' = uncategorized (unique keys ignore NULLs)
                $t->unsignedBigInteger('goal_cents')->default(0);
                $t->uuid('updated_by_user_id')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'location_id', 'category_id'], 'tbg_unique');
            });
        }

        if (Schema::hasTable('tenant_roles')) {
            DB::table('tenant_roles')->whereNotNull('sections')->orderBy('id')->chunk(500, function ($roles) {
                foreach ($roles as $r) {
                    $s = json_decode($r->sections, true);
                    if (! is_array($s) || ! in_array('purchase_orders', $s, true) || in_array('buying', $s, true)) {
                        continue;
                    }
                    $s[] = 'buying';
                    DB::table('tenant_roles')->where('id', $r->id)->update(['sections' => json_encode(array_values($s))]);
                }
            });
        }
    }

    public function down(): void
    {
        // additive; nothing removed
    }
};
