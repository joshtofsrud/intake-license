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
 * Implementation strategy:
 *   1. Detect tenant_id column dynamically per table (some tenant_* tables
 *      don't have tenant_id — they scope via their parent's FK)
 *   2. For tenant_id-bearing tables: delete WHERE tenant_id != platform
 *   3. For child tables without tenant_id: delete via parent FK lookup
 *      BEFORE the parent rows are deleted
 *   4. FOREIGN_KEY_CHECKS=0 during the operation so cascade order doesn't
 *      matter and we can delete in any sequence
 *
 * What survives: platform tenant, master admin users, licenses+customers
 * still referenced by platform, changelog/roadmap content, addon catalog.
 */
class WipeTenantsExceptPlatform extends Command
{
    protected $signature = 'tenants:wipe-except-platform
                            {--force : Required. Acknowledges destructive intent.}
                            {--dry-run : Show what would be deleted without deleting.}';

    protected $description = 'Wipe all tenants and their data except the platform tenant. DESTRUCTIVE.';

    /**
     * Child tables without their own tenant_id column — they're scoped
     * through their parent. Format: [child_table => [parent_fk, parent_table]]
     */
    protected array $childTables = [
        'tenant_appointment_addons'        => ['appointment_id',     'tenant_appointments'],
        'tenant_appointment_charges'       => ['appointment_id',     'tenant_appointments'],
        'tenant_appointment_items'         => ['appointment_id',     'tenant_appointments'],
        'tenant_appointment_notes'         => ['appointment_id',     'tenant_appointments'],
        'tenant_appointment_parts'         => ['appointment_id',     'tenant_appointments'],
        'tenant_appointment_responses'     => ['appointment_id',     'tenant_appointments'],
        'tenant_appointment_work_order_responses' => ['appointment_id', 'tenant_appointments'],
        'tenant_campaign_sends'            => ['campaign_id',        'tenant_campaigns'],
        'tenant_inventory_item_vendors'    => ['inventory_item_id',  'tenant_inventory_items'],
        'tenant_item_addons'               => ['service_item_id',    'tenant_service_items'],
        'tenant_service_addons'            => ['service_item_id',    'tenant_service_items'],
        'tenant_special_order_notes'       => ['special_order_id',   'tenant_special_orders'],
    ];

    public function handle(): int
    {
        if (!$this->option('force')) {
            $this->error('This command is destructive. Pass --force to acknowledge.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        // ── Locate the platform tenant ─────────────────────────────────────
        $platform = Tenant::where('is_platform', true)->first();
        if (!$platform) {
            $this->error('No platform tenant found (is_platform = true). Aborting — refusing to wipe everything.');
            return self::FAILURE;
        }

        $platformId = $platform->id;
        $this->info("Platform tenant: {$platform->name} ({$platform->subdomain}) — id={$platformId}");
        $this->newLine();

        // ── Enumerate tenants to delete ────────────────────────────────────
        $tenantsToDelete = Tenant::where('is_platform', '!=', true)
            ->orWhereNull('is_platform')
            ->get(['id', 'name', 'subdomain', 'license_id', 'is_active']);

        if ($tenantsToDelete->isEmpty()) {
            $this->info('No non-platform tenants found. Nothing to wipe.');
            return self::SUCCESS;
        }

        $this->info("Found {$tenantsToDelete->count()} tenant(s) to wipe:");
        $this->table(
            ['Subdomain', 'Name', 'Active'],
            $tenantsToDelete->map(fn($t) => [
                $t->subdomain ?? '(none)',
                $t->name,
                $t->is_active ? 'yes' : 'no',
            ])->toArray()
        );
        $this->newLine();

        // ── Plan: enumerate row counts per table ───────────────────────────
        $allTables    = $this->tenantScopedTables();
        $childTables  = $this->childTables;
        $directTables = array_diff($allTables, array_keys($childTables));

        $this->info('Direct tables (tenant_id column present):');
        $perTable = [];
        foreach ($directTables as $table) {
            if (!Schema::hasTable($table)) continue;
            if (!Schema::hasColumn($table, 'tenant_id')) {
                $this->warn("  $table — listed as direct but no tenant_id column found. Skipping.");
                continue;
            }
            $count = DB::table($table)->where('tenant_id', '!=', $platformId)->count();
            if ($count > 0) {
                $perTable[$table] = $count;
            }
        }
        if ($perTable) {
            $this->table(['Table', 'Rows'], collect($perTable)->map(fn($n, $t) => [$t, number_format($n)])->values()->toArray());
        }

        $this->info('Child tables (scoped via parent FK):');
        $childCounts = [];
        foreach ($childTables as $child => [$fk, $parent]) {
            if (!Schema::hasTable($child) || !Schema::hasTable($parent)) continue;
            $count = DB::table($child)
                ->whereIn($fk, function ($q) use ($parent, $platformId) {
                    $q->select('id')->from($parent)->where('tenant_id', '!=', $platformId);
                })
                ->count();
            if ($count > 0) {
                $childCounts[$child] = $count;
            }
        }
        if ($childCounts) {
            $this->table(['Child Table (via parent)', 'Rows'], collect($childCounts)->map(fn($n, $t) => [$t, number_format($n)])->values()->toArray());
        }

        $totalRows = array_sum($perTable) + array_sum($childCounts);
        $this->info("Total tenant data rows to delete: " . number_format($totalRows));
        $this->newLine();

        // ── Auxiliary tables ───────────────────────────────────────────────
        $auxCounts = $this->countAuxiliary($platformId);
        if (array_sum($auxCounts) > 0) {
            $this->info('Auxiliary rows to delete:');
            $this->table(['Table', 'Rows'], collect($auxCounts)->map(fn($n, $t) => [$t, number_format($n)])->values()->toArray());
            $this->newLine();
        }

        if ($dryRun) {
            $this->info('Dry run complete. No data deleted. Re-run without --dry-run to execute.');
            return self::SUCCESS;
        }

        // ── Confirmation ───────────────────────────────────────────────────
        $this->warn('This action cannot be undone. Take a mysqldump backup first if you have not.');
        $confirm = $this->ask('Type WIPE ALL TENANTS (exactly) to proceed');
        if ($confirm !== 'WIPE ALL TENANTS') {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        // ── Execute ────────────────────────────────────────────────────────
        $this->info('Starting wipe…');
        $start = microtime(true);
        $deleted = [];

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            // Phase 1: child tables FIRST (parent rows still exist for FK lookup).
            foreach ($childTables as $child => [$fk, $parent]) {
                if (!Schema::hasTable($child) || !Schema::hasTable($parent)) continue;
                $n = DB::table($child)
                    ->whereIn($fk, function ($q) use ($parent, $platformId) {
                        $q->select('id')->from($parent)->where('tenant_id', '!=', $platformId);
                    })
                    ->delete();
                if ($n > 0) {
                    $deleted[$child] = $n;
                }
            }

            // Phase 2: all direct tables.
            foreach ($directTables as $table) {
                if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'tenant_id')) continue;
                $n = DB::table($table)->where('tenant_id', '!=', $platformId)->delete();
                if ($n > 0) {
                    $deleted[$table] = $n;
                }
            }

            // Phase 3: auxiliary tenant-scoped tables.
            foreach (['support_conversations', 'quiz_completions', 'debug_logs'] as $table) {
                if (!Schema::hasTable($table)) continue;
                if (!Schema::hasColumn($table, 'tenant_id')) continue;
                $n = DB::table($table)
                    ->where(function ($q) use ($platformId) {
                        $q->where('tenant_id', '!=', $platformId)
                          ->orWhereNull('tenant_id');
                    })
                    ->delete();
                if ($n > 0) {
                    $deleted[$table] = $n;
                }
            }

            // Phase 4: the tenants table itself.
            $n = DB::table('tenants')
                ->where(function ($q) {
                    $q->where('is_platform', '!=', true)->orWhereNull('is_platform');
                })
                ->delete();
            if ($n > 0) {
                $deleted['tenants'] = $n;
            }

            // Phase 5: orphan cleanup — licenses, customers, license_events, activations.
            $stillRefLicenseIds = DB::table('tenants')->whereNotNull('license_id')->pluck('license_id')->toArray();
            if (Schema::hasTable('licenses')) {
                $n = DB::table('licenses')
                    ->whereNotIn('id', $stillRefLicenseIds ?: ['__never_match__'])
                    ->delete();
                if ($n > 0) {
                    $deleted['licenses (orphaned)'] = $n;
                }
            }

            if (Schema::hasTable('license_events')) {
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

            if (Schema::hasTable('customers')) {
                $stillRefCustomerIds = DB::table('licenses')->whereNotNull('customer_id')->pluck('customer_id')->toArray();
                $n = DB::table('customers')
                    ->whereNotIn('id', $stillRefCustomerIds ?: ['__never_match__'])
                    ->delete();
                if ($n > 0) {
                    $deleted['customers (orphaned upstream)'] = $n;
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $elapsed = round(microtime(true) - $start, 2);

        // ── Report ─────────────────────────────────────────────────────────
        $this->newLine();
        $this->info("Wipe complete in {$elapsed}s.");
        if (!empty($deleted)) {
            $this->table(
                ['Table', 'Rows deleted'],
                collect($deleted)->map(fn($n, $t) => [$t, number_format($n)])->values()->toArray()
            );
        }

        // ── Verify ─────────────────────────────────────────────────────────
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

    protected function tenantScopedTables(): array
    {
        return [
            // Direct (tenant_id column)
            'tenant_addon_suppressions', 'tenant_addons',
            'tenant_appointment_payments', 'tenant_appointments',
            'tenant_calendar_breaks',
            'tenant_campaign_images', 'tenant_campaigns',
            'tenant_capacity_rules',
            'tenant_class_membership_products', 'tenant_class_pack_products',
            'tenant_class_registrations', 'tenant_class_sessions', 'tenant_class_templates',
            'tenant_customer_memberships', 'tenant_customer_notes',
            'tenant_customer_packs', 'tenant_customers',
            'tenant_distributor_catalog_subscriptions',
            'tenant_email_templates', 'tenant_feature_addons',
            'tenant_form_fields', 'tenant_form_sections',
            'tenant_inventory_categories', 'tenant_inventory_item_locations',
            'tenant_inventory_items', 'tenant_inventory_movements',
            'tenant_inventory_receive_shipment_items', 'tenant_inventory_receive_shipments',
            'tenant_item_tier_prices',
            'tenant_locations', 'tenant_nav_items', 'tenant_notification_log',
            'tenant_page_sections', 'tenant_pages',
            'tenant_receiving_methods', 'tenant_resources',
            'tenant_sale_counters', 'tenant_sale_items', 'tenant_sales',
            'tenant_service_categories', 'tenant_service_items',
            'tenant_service_resource_eligibility', 'tenant_service_tiers',
            'tenant_special_order_counters', 'tenant_special_orders',
            'tenant_tags', 'tenant_transfer_requests', 'tenant_trusted_devices',
            'tenant_user_locations', 'tenant_users', 'tenant_vendors',
            'tenant_waitlist_entries', 'tenant_waitlist_offers',
            'tenant_waitlist_settings', 'tenant_waitlist_similar_map',
            'tenant_walkin_holds', 'tenant_work_order_fields',
            'theme_settings_audits',
            // Child (no tenant_id — scoped via parent)
            'tenant_appointment_addons', 'tenant_appointment_charges',
            'tenant_appointment_items', 'tenant_appointment_notes',
            'tenant_appointment_parts', 'tenant_appointment_responses',
            'tenant_appointment_work_order_responses',
            'tenant_campaign_sends',
            'tenant_inventory_item_vendors',
            'tenant_item_addons', 'tenant_service_addons',
            'tenant_special_order_notes',
        ];
    }

    protected function countAuxiliary(string $platformId): array
    {
        $counts = [];
        foreach (['support_conversations', 'quiz_completions', 'debug_logs'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            $counts[$table] = DB::table($table)
                ->where(function ($q) use ($platformId) {
                    $q->where('tenant_id', '!=', $platformId)->orWhereNull('tenant_id');
                })
                ->count();
        }
        return $counts;
    }
}
