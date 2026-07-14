#!/bin/bash
# register-recon-display hotfix — tenants/tenant_locations use UUID primary keys;
# the registers migration used bigint FKs and failed. This rewrites the migration
# with foreignUuid columns and a dropIfExists guard for the half-created table.
set -e
cd "$(git rev-parse --show-toplevel)"
cat > database/migrations/2026_07_14_000001_create_tenant_registers_table.php <<'RRD_FIX_EOF'
<?php

// MARKER-REGISTER-RECON-DISPLAY — physical register entities + customer displays.
// Registers are the sticky anchor for pay-station displays: each register owns
// a permanent display_token; an iPad pairs once by opening the token URL and
// then mirrors whatever cart is active on that register.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard: a prior failed run can leave the table without its FKs
        // (MySQL creates the table, then fails on the ALTER). Clean slate.
        Schema::dropIfExists('tenant_registers');

        Schema::create('tenant_registers', function (Blueprint $t) {
            $t->id();
            // tenants + tenant_locations use UUID primary keys
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('location_id')->nullable()->constrained('tenant_locations')->nullOnDelete();
            $t->unsignedSmallInteger('number');
            $t->string('name', 80);
            $t->string('display_token', 64)->unique();
            $t->json('display_cart')->nullable();      // latest mirrored snapshot
            $t->timestamp('cart_updated_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
        });

        // tenant_sales has a legacy varchar register_id from the original table
        // design that nothing ever wrote or read — replace it with a real FK.
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->dropColumn('register_id');
        });
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->foreignId('register_id')->nullable()->after('location_id')
              ->constrained('tenant_registers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->dropConstrainedForeignId('register_id');
        });
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->string('register_id', 50)->nullable();
        });
        Schema::dropIfExists('tenant_registers');
    }
};
RRD_FIX_EOF
echo "hotfix applied — commit, push, then migrate on the server"
