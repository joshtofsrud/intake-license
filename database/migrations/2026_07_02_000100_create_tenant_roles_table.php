<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// MARKER-PATCH-490 — tenant_roles + tenant_users.role_id + backfill
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // JSON array of SectionRegistry keys this role can open.
            // NULL means "all sections" (Owner, and safe default).
            $table->json('sections')->nullable();
            // System roles seeded from the legacy enum. Owner is locked
            // (full access, not editable); Manager/Staff are editable
            // starting points that tenants can rename or trim.
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('tenant_users', function (Blueprint $table) {
            $table->foreignUuid('role_id')->nullable()
                  ->after('role')
                  ->constrained('tenant_roles')->nullOnDelete();
        });

        // Backfill: one system role per legacy enum value, per tenant.
        // All three start with full access (sections = NULL) so deploy
        // changes nothing for anyone — trimming happens in the UI.
        $now = now();
        $tenants = DB::table('tenants')->pluck('id');
        foreach ($tenants as $tenantId) {
            $map = [];
            foreach (['owner' => 'Owner', 'manager' => 'Manager', 'staff' => 'Staff'] as $enum => $name) {
                $existing = DB::table('tenant_roles')
                    ->where('tenant_id', $tenantId)->where('name', $name)->value('id');
                if ($existing) { $map[$enum] = $existing; continue; }
                $id = (string) Str::uuid();
                DB::table('tenant_roles')->insert([
                    'id' => $id, 'tenant_id' => $tenantId, 'name' => $name,
                    'sections' => null, 'is_system' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $map[$enum] = $id;
            }
            foreach ($map as $enum => $roleId) {
                DB::table('tenant_users')
                    ->where('tenant_id', $tenantId)->where('role', $enum)
                    ->whereNull('role_id')
                    ->update(['role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
        Schema::dropIfExists('tenant_roles');
    }
};
