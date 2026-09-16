<?php
// MARKER-SALES-FIND — additive only (expand/contract rule).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_settings')) {
            Schema::create('sales_settings', function (Blueprint $t) {
                $t->string('key', 80)->primary();
                $t->text('value')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('sales_territories')) {
            Schema::create('sales_territories', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('name', 120);
                $t->uuid('agency_id')->nullable();
                $t->uuid('sales_rep_id')->nullable();
                $t->json('states');                               // ['WA','OR']
                $t->decimal('lat_min', 9, 6)->nullable();         // only shops NORTH of this latitude
                $t->decimal('lat_max', 9, 6)->nullable();         // only shops SOUTH of this latitude
                $t->unsignedSmallInteger('priority')->default(100); // lower wins when two rules match
                $t->boolean('is_active')->default(true);
                $t->text('notes')->nullable();
                $t->timestamps();
                $t->index(['is_active', 'priority']);
                $t->foreign('agency_id', 'sales_territories_agency_fk')->references('id')->on('sales_agencies')->nullOnDelete();
                $t->foreign('sales_rep_id', 'sales_territories_rep_fk')->references('id')->on('sales_reps')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('sales_places_searches')) {
            Schema::create('sales_places_searches', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('industry', 80);
                $t->string('place', 191);
                $t->unsignedSmallInteger('radius_miles')->default(25);
                $t->unsignedSmallInteger('requests')->default(0);  // billable Places calls
                $t->unsignedSmallInteger('found')->default(0);
                $t->unsignedSmallInteger('new_count')->default(0);
                $t->unsignedInteger('cost_cents')->default(0);
                $t->timestamps();
                $t->index('created_at');
            });
        }

        Schema::table('sales_prospects', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_prospects', 'postcode')) {
                $t->string('postcode', 20)->nullable()->after('state');
            }
            if (! Schema::hasColumn('sales_prospects', 'territory_id')) {
                $t->uuid('territory_id')->nullable()->after('sales_rep_id');
            }
            if (! Schema::hasColumn('sales_prospects', 'hours')) {
                $t->string('hours', 255)->nullable()->after('phone');
            }
            if (! Schema::hasColumn('sales_prospects', 'enriched_at')) {
                $t->timestamp('enriched_at')->nullable()->after('hours');
            }
        });
        Schema::table('sales_prospects', function (Blueprint $t) {
            try { $t->index('territory_id', 'sales_prospects_territory_index'); } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        // Additive migration: dropping waits for a later release.
    }
};
