<?php
// MARKER-RECEIVED-COST

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->integer('received_cost_cents')->nullable()->after('shop_cost_cents');
            $table->unsignedInteger('received_cost_units')->default(0)->after('received_cost_cents');
            $table->timestamp('received_cost_at')->nullable()->after('received_cost_units');
            $table->string('received_cost_source', 24)->nullable()->after('received_cost_at'); // seed | receive | adjustment | manual
        });

        // Seed: a typed shop cost was the shop stating its cost. It becomes the
        // starting received cost with zero units behind it, so the first real
        // shipment's average is weighted entirely by real units.
        $n = DB::table('tenant_inventory_items')
            ->whereNotNull('shop_cost_cents')
            ->whereNull('received_cost_cents')
            ->update([
                'received_cost_cents'  => DB::raw('shop_cost_cents'),
                'received_cost_units'  => 0,
                'received_cost_source' => 'seed',
                'received_cost_at'     => now(),
            ]);

        Log::info("MARKER-RECEIVED-COST: seeded received cost from shop_cost_cents on {$n} item(s)");
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->dropColumn(['received_cost_cents', 'received_cost_units', 'received_cost_at', 'received_cost_source']);
        });
    }
};
