<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wipe all non-platform tenants and their related data.
 *
 * DESTRUCTIVE. Cannot be undone. Requires:
 *   - --force flag
 *   - Typing the literal string "WIPE ALL TENANTS" at the prompt
 *
 * What gets deleted:
 *   - Every row in every tenant_* table where tenant_id != platform tenant id
 *   - Every row in tenants where is_platform != true
 *   - Tenant-scoped rows in: support_conversations, quiz_completions, debug_logs
 *   - Orphaned licenses (license rows no tenant points at, except platform's)
 *   - Orphaned upstream customers (customer rows no license points at)
 *
 * What gets preserved:
 *   - Platform tenant row + all its tenant_* data
 *   - Platform's license + upstream customer
 *   - Master admin users (users table — never touched)
 *   - changelog_entries, roadmap_entries — platform-level content
 *   - addons catalog
 *   - Schema, migrations history
 *
 * Run only after taking a manual mysqldump backup.
 *
 * Usage:
 *   php artisan tenants:wipe-except-platform --force
 *   php artisan tenants:wipe-except-platform --force --dry-run    (show only)
 */
class WipeTenantsExceptPlatform extends Command
{
    protected $signature = 'tenants:wipe-except-platform
                            {--force : Required. Acknowledges destructive intent.}
                            {--dry-run : Show what would be deleted without deleting.}';

    protected $description = 'Wipe all tenants and their data except the platform tenant. DESTRUCTIVE.';

    public function handle(): int
    {
        if (!$this->option('force')) {
            $this->error('This command is destructive. Pass --force to acknowledge.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        // ── Step 1: locate the platform tenant ─────────────────────────────
        $platform = Tenant::where('is_platform', true)->first();
        if (!$platform) {
            $this->error('No platform tenant found (is_platform = true). Aborting — refusing to wipe everything.');
            return self::FAILURE;
        }

        $platformId = $platform->id;
        $platformLicenseId = $platform->license_id;

        $this->info("Platform tenant: {$platform->name} ({$platform->subdomain}) — id={$platformId}");
        $this->info("Platform license: {$platformLicenseId}");
        $this->newLine();

        // ── Step 2: enumerate what will be deleted ─────────────────────────
        $tenantsToDelete = Tenant::where('is_platform', '!=', true)
            ->orWhereNull('is_platform')
            ->get(['id', 'name', 'subdomain', 'license_id', 'is_active']);

        if ($tenantsToDelete->isEmpty()) {
            $this->info('No non-platform tenants found. Nothing to wipe.');
            return self::SUCCESS;
        }

        $this->info("Found {$tenantsToDelete->count()} tenant(s) to wipe:");
        $this->table(
            ['Subdomain', 'Name', 'Active', 'License ID'],
            $tenantsToDelete->map(fn($t) => [
                $t->subdomain ?? '(none)',
                $t->name,
                $t->is_active ? 'yes' : 'no',
                $t->license_id ? substr($t->license_id, 0, 8) . '…' : '(none)',
            ])->toArray()
        );
        $this->newLine();

        // Per-table row counts for transparency
        $tenantTables = $this->tenantScopedTables();
        $this->info('Row counts to be deleted by table (tenant_id != platform):');
        $perTable = [];
        foreach ($tenantTables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $count = DB::table($table)->where('tenant_id', '!=', $platformId)->count();
            if ($count > 0) {
                $perTable[$table] = $count;
            }
        }
        $totalRows = array_sum($perTable);
        $rows = collect($perTable)->map(fn($n, $t) => [$t, number_format($n)])->values()->toArray();
        if ($rows) {
            $this->table(['Table', 'Rows'], $rows);
        }
        $this->info("Total tenant_* rows to delete: " . number_format($totalRows));
        $this->newLine();

        // Auxiliary tables
        $auxCounts = $this->countAuxiliary($platformId);
        if (array_sum($auxCounts) > 0) {
            $this->info('Auxiliary rows to be deleted:');
            $auxRows = collect($auxCounts)->map(fn($n, $t) => [$t, number_format($n)])->values()->toArray();
            $this->table(['Table', 'Rows'], $auxRows);
            $this->newLine();
        }

        if ($dryRun) {
            $this->info('Dry run complete. No data deleted. Re-run without --dry-run to execute.');
            return self::SUCCESS;
        }

        // ── Step 3: explicit confirmation ──────────────────────────────────
        $this->warn('This action cannot be undone. Take a mysqldump backup first if you have not.');
        $confirm = $this->ask('Type WIPE ALL TENANTS (exactly) to proceed');
        if ($confirm !== 'WIPE ALL TENANTS') {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        // ── Step 4: execute ────────────────────────────────────────────────
        $this->info('Starting wipe…');
        $start = microtime(true);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::transaction(function () use ($platformId, $tenantTables, &$deleted) {
                $deleted = [];

                // 4a. Delete from every tenant_* table.
                foreach ($tenantTables as $table) {
                    if (!Schema::hasTable($table)) {
                        continue;
                    }
                    $n = DB::table($table)->where('tenant_id', '!=', $platformId)->delete();
                    if ($n > 0) {
                        $deleted[$table] = $n;
                    }
                }

                // 4b. Auxiliary tenant-scoped tables.
                foreach (['support_conversations', 'quiz_completions', 'debug_logs'] as $table) {
                    if (!Schema::hasTable($table)) {
                        continue;
                    }
                    $n = DB::table($table)
                        ->where(function ($q) use ($platformId) {
                            $q->where('tenant_id', '!=', $platformId)
                              ->orWhereNull('tenant_id'); // debug_logs allows null tenant_id
                        })
                        ->delete();
                    if ($n > 0) {
                        $deleted[$table] = $n;
                    }
                }

                // 4c. Delete the non-platform tenant rows themselves.
                $n = DB::table('tenants')
                    ->where(function ($q) {
                        $q->where('is_platform', '!=', true)
                          ->orWhereNull('is_platform');
                    })
                    ->delete();
                if ($n > 0) {
                    $deleted['tenants'] = $n;
                }

                // 4d. Orphaned licenses — any license not pointed at by a tenant.
                $stillReferencedLicenseIds = DB::table('tenants')
                    ->whereNotNull('license_id')
                    ->pluck('license_id')
                    ->toArray();

                $n = DB::table('licenses')
                    ->whereNotIn('id', $stillReferencedLicenseIds ?: ['__never_match__'])
                    ->delete();
                if ($n > 0) {
                    $deleted['licenses (orphaned)'] = $n;
                }

                // 4e. Orphaned upstream customers (separate from tenant_customers).
                if (Schema::hasTable('customers')) {
                    $stillReferencedCustomerIds = DB::table('licenses')
                        ->whereNotNull('customer_id')
                        ->pluck('customer_id')
                        ->toArray();

                    $n = DB::table('customers')
                        ->whereNotIn('id', $stillReferencedCustomerIds ?: ['__never_match__'])
                        ->delete();
                    if ($n > 0) {
                        $deleted['customers (orphaned upstream)'] = $n;
                    }
                }

                // 4f. Orphaned activations + license_events.
                if (Schema::hasTable('license_events')) {
                    // license_events cascades on license delete via FK — but the
                    // FK_CHECKS=0 in step 4 means cascades skipped. Clean now.
                    $remainingLicenseIds = DB::table('licenses')->pluck('id')->toArray();
                    $n = DB::table('license_events')
                        ->whereNotIn('license_id', $remainingLicenseIds ?: ['__never_match__'])
                        ->delete();
                    if ($n > 0) {
                        $deleted['license_events (orphaned)'] = $n;
                    }
                }
                if (Schema::hasTable('activations')) {
                    $remainingLicenseIds = $remainingLicenseIds ?? DB::table('licenses')->pluck('id')->toArray();
                    $n = DB::table('activations')
                        ->whereNotNull('license_id')
                        ->whereNotIn('license_id', $remainingLicenseIds ?: ['__never_match__'])
                        ->delete();
                    if ($n > 0) {
                        $deleted['activations (orphaned)'] = $n;
                    }
                }
            });
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $elapsed = round(microtime(true) - $start, 2);

        // ── Step 5: report ─────────────────────────────────────────────────
        $this->newLine();
        $this->info("Wipe complete in {$elapsed}s.");
        if (!empty($deleted)) {
            $reportRows = collect($deleted)->map(fn($n, $t) => [$t, number_format($n)])->values()->toArray();
            $this->table(['Table', 'Rows deleted'], $reportRows);
        }

        // ── Step 6: sanity verify ──────────────────────────────────────────
        $remainingTenants = Tenant::count();
        $platformStillThere = Tenant::where('is_platform', true)->exists();
        $this->info("Tenants remaining: {$remainingTenants}");
        $this->info('Platform tenant preserved: ' . ($platformStillThere ? 'yes' : 'NO — ERROR'));

        if (!$platformStillThere) {
            $this->error('CRITICAL: platform tenant is gone. Restore from backup.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Return the list of tenant-scoped table names. Centralised so the
     * confirmation enumeration matches the delete loop exactly.
     *
     * Order doesn't matter — FK checks are disabled during the wipe.
     */
    protected function tenantScopedTables(): array
    {
        return [
            'tenant_addon_suppressions',
            'tenant_addons',
            'tenant_appointment_addons',
            'tenant_appointment_charges',
            'tenant_appointment_items',
            'tenant_appointment_notes',
            'tenant_appointment_parts',
            'tenant_appointment_payments',
            'tenant_appointment_responses',
            'tenant_appointment_work_order_responses',
            'tenant_appointments',
            'tenant_calendar_breaks',
            'tenant_campaign_images',
            'tenant_campaign_sends',
            'tenant_campaigns',
            'tenant_capacity_rules',
            'tenant_class_membership_products',
            'tenant_class_pack_products',
            'tenant_class_registrations',
            'tenant_class_sessions',
            'tenant_class_templates',
            'tenant_customer_memberships',
            'tenant_customer_notes',
            'tenant_customer_packs',
            'tenant_customers',
            'tenant_distributor_catalog_subscriptions',
            'tenant_email_templates',
            'tenant_feature_addons',
            'tenant_form_fields',
            'tenant_form_sections',
            'tenant_inventory_categories',
            'tenant_inventory_item_locations',
            'tenant_inventory_item_vendors',
            'tenant_inventory_items',
            'tenant_inventory_movements',
            'tenant_inventory_receive_shipment_items',
            'tenant_inventory_receive_shipments',
            'tenant_item_addons',
            'tenant_item_tier_prices',
            'tenant_locations',
            'tenant_nav_items',
            'tenant_notification_log',
            'tenant_page_sections',
            'tenant_pages',
            'tenant_receiving_methods',
            'tenant_resources',
            'tenant_sale_counters',
            'tenant_sale_items',
            'tenant_sales',
            'tenant_service_addons',
            'tenant_service_categories',
            'tenant_service_items',
            'tenant_service_resource_eligibility',
            'tenant_service_tiers',
            'tenant_special_order_counters',
            'tenant_special_order_notes',
            'tenant_special_orders',
            'tenant_tags',
            'tenant_transfer_requests',
            'tenant_trusted_devices',
            'tenant_user_locations',
            'tenant_users',
            'tenant_vendors',
            'tenant_waitlist_entries',
            'tenant_waitlist_offers',
            'tenant_waitlist_settings',
            'tenant_waitlist_similar_map',
            'tenant_walkin_holds',
            'tenant_work_order_fields',
            'theme_settings_audits',
        ];
    }

    protected function countAuxiliary(string $platformId): array
    {
        $counts = [];
        foreach (['support_conversations', 'quiz_completions', 'debug_logs'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $counts[$table] = DB::table($table)
                ->where(function ($q) use ($platformId) {
                    $q->where('tenant_id', '!=', $platformId)
                      ->orWhereNull('tenant_id');
                })
                ->count();
        }
        return $counts;
    }
}
