<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Route windows: one-date extra windows, closed dates, an optional name,
// and a "customers can book it" switch. Additive only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_route_windows', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_route_windows', 'on_date')) {
                // set = runs on this one date only; days is ignored
                $table->date('on_date')->nullable()->after('days');
            }
            if (! Schema::hasColumn('tenant_route_windows', 'name')) {
                // optional; when empty the label is built from the times
                $table->string('name', 40)->nullable()->after('label');
            }
            if (! Schema::hasColumn('tenant_route_windows', 'customers_can_book')) {
                $table->boolean('customers_can_book')->default(true)->after('max_stops');
            }
        });

        if (! Schema::hasTable('tenant_route_closures')) {
            Schema::create('tenant_route_closures', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
                $table->date('date');
                // null = the whole day is closed; set = just that window on that date
                $table->uuid('window_id')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'date'], 'trc_tenant_date');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_route_closures');
        Schema::table('tenant_route_windows', function (Blueprint $table) {
            foreach (['on_date', 'name', 'customers_can_book'] as $c) {
                if (Schema::hasColumn('tenant_route_windows', $c)) $table->dropColumn($c);
            }
        });
    }
};
