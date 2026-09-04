<?php
// MARKER-ADDON-CATALOG

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addon_prices', function (Blueprint $table) {
            $table->id();
            $table->string('addon_code', 64);
            $table->unsignedInteger('price_cents');
            $table->date('effective_from');
            $table->string('created_by', 191)->nullable();
            $table->timestamps();

            $table->unique(['addon_code', 'effective_from']);
            $table->index(['addon_code', 'effective_from']);
        });

        // Seed from what each add-on charges today, dated far enough back that
        // it reads as "it has always been this".
        foreach (DB::table('addons')->get(['code', 'price_cents']) as $addon) {
            DB::table('addon_prices')->insert([
                'addon_code'     => $addon->code,
                'price_cents'    => (int) $addon->price_cents,
                'effective_from' => '2020-01-01',
                'created_by'     => 'migration',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_prices');
    }
};
