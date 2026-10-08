<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Industries (sales_channels) become the one list.
 * places_query is what Find shops searches Google for. The industries that
 * used to be hard-coded in Find shops are added as drafts so none disappear.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_channels', 'places_query')) {
            Schema::table('sales_channels', function (Blueprint $t) {
                $t->string('places_query', 120)->nullable()->after('best_ask');
            });
        }

        $wanted = [
            'Bike shops'        => 'bike shop',
            'Salons'            => 'hair salon',
            'Ski & snowboard'   => 'ski and snowboard shop',
            'Fitness studios'   => 'fitness studio',
            'Motorcycle'        => 'motorcycle repair shop',
            'Outdoor specialty' => 'outdoor gear shop',
            'Paddle & kayak'    => 'kayak and paddleboard shop',
        ];

        foreach ($wanted as $name => $query) {
            $row = DB::table('sales_channels')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->orWhere('slug', Str::slug($name))
                ->first();

            if ($row) {
                if (blank($row->places_query ?? null)) {
                    DB::table('sales_channels')->where('id', $row->id)->update(['places_query' => $query]);
                }
                continue;
            }

            DB::table('sales_channels')->insert([
                'id'           => (string) Str::uuid(),
                'name'         => $name,
                'slug'         => Str::slug($name),
                'status'       => 'draft',
                'places_query' => $query,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_channels', 'places_query')) {
            Schema::table('sales_channels', fn (Blueprint $t) => $t->dropColumn('places_query'));
        }
    }
};
