<?php
// MARKER-TOWN-LIST: the town list behind Find shops (GeoNames, CC BY 4.0: US places
// over 1,000 people), and where each search was centered so coverage can be shown.
// Older searches are placed on the map by matching their town and state to the list.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('sales_towns')) {
            Schema::create('sales_towns', function (Blueprint $t) {
                $t->id();
                $t->char('state', 2);
                $t->string('name', 120);
                $t->unsignedInteger('population')->default(0);
                $t->decimal('lat', 9, 6);
                $t->decimal('lng', 9, 6);
                $t->index(['state', 'population']);
            });
        }
        Schema::table('sales_places_searches', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_places_searches', 'channel_id')) $t->string('channel_id', 36)->nullable();
            if (! Schema::hasColumn('sales_places_searches', 'lat'))        $t->decimal('lat', 9, 6)->nullable();
            if (! Schema::hasColumn('sales_places_searches', 'lng'))        $t->decimal('lng', 9, 6)->nullable();
        });
        try { Schema::table('sales_places_searches', fn (Blueprint $t) => $t->index('channel_id', 'sales_places_searches_channel_index')); } catch (\Throwable $e) {}

        if (DB::table('sales_towns')->count() === 0 && is_readable($f = database_path('data/us-towns.csv'))) {
            $h = fopen($f, 'r');
            fgetcsv($h); // header
            $batch = [];
            while (($r = fgetcsv($h)) !== false) {
                if (count($r) < 5) continue;
                $batch[] = ['state' => $r[0], 'name' => mb_substr($r[1], 0, 120), 'population' => (int) $r[2], 'lat' => (float) $r[3], 'lng' => (float) $r[4]];
                if (count($batch) >= 500) { DB::table('sales_towns')->insert($batch); $batch = []; }
            }
            if ($batch) DB::table('sales_towns')->insert($batch);
            fclose($h);
        }

        // earlier searches: industry by name, center by town
        $channels = DB::table('sales_channels')->pluck('id', 'name')->all();
        foreach (DB::table('sales_places_searches')->whereNull('lat')->get(['id', 'industry', 'place']) as $s) {
            $loc = \App\Services\Sales\TownCoverage::locate((string) $s->place);
            DB::table('sales_places_searches')->where('id', $s->id)->update([
                'channel_id' => $channels[$s->industry] ?? null,
                'lat' => $loc['lat'] ?? null,
                'lng' => $loc['lng'] ?? null,
            ]);
        }
    }

    public function down(): void
    {
        try { Schema::table('sales_places_searches', fn (Blueprint $t) => $t->dropIndex('sales_places_searches_channel_index')); } catch (\Throwable $e) {}
        Schema::table('sales_places_searches', function (Blueprint $t) {
            foreach (['channel_id', 'lat', 'lng'] as $c) if (Schema::hasColumn('sales_places_searches', $c)) $t->dropColumn($c);
        });
        Schema::dropIfExists('sales_towns');
    }
};
