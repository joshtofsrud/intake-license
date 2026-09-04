<?php
// MARKER-PLAN-PRICING

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->string('tier', 32);
            $table->unsignedInteger('price_cents');
            // The date this price starts applying. Several rows per tier are
            // expected: the current one, and any scheduled ahead of it.
            $table->date('effective_from');
            $table->string('created_by', 191)->nullable();
            $table->timestamps();

            $table->unique(['tier', 'effective_from']);
            $table->index(['tier', 'effective_from']);
        });

        // Seed from config so nothing changes the day this ships.
        $today = now()->toDateString();
        foreach ((array) config('intake.plan_prices', []) as $tier => $cents) {
            DB::table('plan_prices')->insert([
                'tier'           => $tier,
                'price_cents'    => (int) $cents,
                'effective_from' => '2020-01-01',   // long past: it has always been this
                'created_by'     => 'migration',
                'created_at'     => $today,
                'updated_at'     => $today,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
