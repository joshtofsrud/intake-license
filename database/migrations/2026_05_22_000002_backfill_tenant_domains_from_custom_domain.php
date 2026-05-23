<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfill tenant_domains from tenants.custom_domain.
 *
 * For every tenant with a non-null custom_domain, create a corresponding
 * tenant_domains row marked active + primary + role='both'.
 *
 * Tenants without a custom_domain get no row — they're only on subdomain.
 *
 * Safe to re-run: skips tenants that already have a row for that hostname.
 */
return new class extends Migration {
    public function up(): void
    {
        $rows = DB::table('tenants')
            ->whereNotNull('custom_domain')
            ->select('id', 'custom_domain')
            ->get();

        $now = now();
        foreach ($rows as $tenant) {
            $hostname = strtolower(trim($tenant->custom_domain));
            if ($hostname === '') {
                continue;
            }

            // Skip if a tenant_domains row already exists for this hostname.
            $exists = DB::table('tenant_domains')
                ->where('hostname', $hostname)
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('tenant_domains')->insert([
                'id'                 => (string) Str::uuid(),
                'tenant_id'          => $tenant->id,
                'hostname'           => $hostname,
                'is_primary'         => true,
                'role'               => 'both',
                'alias_mode'         => 'redirect',
                // Assume existing custom domains are already working —
                // they were live before this migration. If any aren't,
                // the polling loop in patch 118 will catch and re-state them.
                'status'             => 'active',
                'verification_token' => Str::random(32),
                'verified_at'        => $now,
                'activated_at'       => $now,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Reverse: delete only the rows that match a tenant's current
        // custom_domain (the ones this migration created). Anything
        // added through the new feature post-migration is left alone.
        $rows = DB::table('tenants')
            ->whereNotNull('custom_domain')
            ->select('id', 'custom_domain')
            ->get();

        foreach ($rows as $tenant) {
            DB::table('tenant_domains')
                ->where('tenant_id', $tenant->id)
                ->where('hostname', strtolower(trim($tenant->custom_domain)))
                ->delete();
        }
    }
};
