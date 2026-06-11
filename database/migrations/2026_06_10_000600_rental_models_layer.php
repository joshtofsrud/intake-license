<?php
// MARKER-PATCH-226 — model layer between category and unit + leasing axis.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // 1. category size axis (label only; per-unit values stay on units).
        Schema::table('tenant_rental_categories', function (Blueprint $t) {
            $t->string('size_axis', 40)->nullable()->after('name'); // "length (cm)", "Mondopoint", …
        });

        // 2. the model layer — carries the rate card, deposit, checklist.
        Schema::create('tenant_rental_models', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            $t->foreignUuid('category_id')->constrained('tenant_rental_categories')->onDelete('restrict');
            $t->string('name');
            $t->string('subtitle')->nullable();          // "all-mountain", "junior", …
            // Rate card (moved up from units). Null = not offered at that mode.
            $t->unsignedInteger('hourly_rate_cents')->nullable();
            $t->unsignedInteger('daily_rate_cents')->nullable();
            $t->unsignedInteger('weekend_rate_cents')->nullable();
            $t->unsignedInteger('seasonal_rate_cents')->nullable(); // MARKER-PATCH-226 — 4th mode
            $t->unsignedInteger('deposit_cents')->default(0);
            $t->foreignUuid('condition_template_id')->nullable()
              ->constrained('tenant_rental_condition_templates')->nullOnDelete();
            $t->unsignedSmallInteger('sort_order')->default(100);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'category_id', 'archived_at'], 'trm_tenant_cat_active');
        });

        // 3. units gain model_id (nullable during backfill, then enforced
        //    in app logic — we don't add the FK-not-null until rows exist).
        Schema::table('tenant_rental_units', function (Blueprint $t) {
            $t->foreignUuid('model_id')->nullable()->after('category_id')
              ->constrained('tenant_rental_models')->nullOnDelete();
        });

        // 4. BACKFILL — every existing unit gets a model. Group by
        //    (category_id, name): identically-named units in a category share
        //    one model carrying their rates (they were identical by
        //    construction in the pre-model world). Unique units each get a
        //    single-unit model.
        $units = DB::table('tenant_rental_units')->get();
        $modelByKey = []; // "tenant|cat|name" => model_id

        foreach ($units as $u) {
            $key = $u->tenant_id . '|' . $u->category_id . '|' . $u->name;

            if (!isset($modelByKey[$key])) {
                $modelId = (string) Str::uuid();
                DB::table('tenant_rental_models')->insert([
                    'id'                    => $modelId,
                    'tenant_id'             => $u->tenant_id,
                    'category_id'           => $u->category_id,
                    'name'                  => $u->name,
                    'subtitle'              => null,
                    'hourly_rate_cents'     => $u->hourly_rate_cents,
                    'daily_rate_cents'      => $u->daily_rate_cents,
                    'weekend_rate_cents'    => $u->weekend_rate_cents,
                    'seasonal_rate_cents'   => null,
                    'deposit_cents'         => $u->deposit_cents ?? 0,
                    'condition_template_id' => $u->condition_template_id,
                    'sort_order'            => 100,
                    'archived_at'           => $u->archived_at,
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ]);
                $modelByKey[$key] = $modelId;
            }

            DB::table('tenant_rental_units')->where('id', $u->id)
              ->update(['model_id' => $modelByKey[$key]]);
        }

        // NOTE: old rate/deposit/condition columns are intentionally LEFT on
        // tenant_rental_units for one release so a rollback is trivial. A
        // later cleanup migration drops them once 227/228 are proven.
    }

    public function down(): void
    {
        Schema::table('tenant_rental_units', function (Blueprint $t) {
            $t->dropForeign(['model_id']);
            $t->dropColumn('model_id');
        });
        Schema::dropIfExists('tenant_rental_models');
        Schema::table('tenant_rental_categories', function (Blueprint $t) {
            $t->dropColumn('size_axis');
        });
    }
};
