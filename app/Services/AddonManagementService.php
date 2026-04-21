<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AddonManagementService
 *
 * All write operations for addon state. Every state change:
 *   1. Validates the transition is legal
 *   2. Writes the tenant_feature_addons / suppressions row
 *   3. Records an audit log entry
 *   4. Flushes FeatureAccessService cache for the tenant
 *   5. Fires activation/deactivation hooks (e.g. create waitlist settings)
 */
class AddonManagementService
{
    public function __construct(
        protected FeatureAccessService $features,
    ) {}

    public function activate(Tenant $tenant, string $addonCode, array $opts = []): ?object
    {
        $addon = DB::table('addons')->where('code', $addonCode)->first();
        if (! $addon) {
            throw new \InvalidArgumentException("Unknown addon code: {$addonCode}");
        }

        $source = $opts['source'] ?? 'self_serve';
        $actorType = $opts['actor_type'] ?? 'tenant';
        $actorId = $opts['actor_id'] ?? null;
        $actorLabel = $opts['actor_label'] ?? null;
        $reason = $opts['reason'] ?? null;

        return DB::transaction(function () use ($tenant, $addonCode, $source, $actorType, $actorId, $actorLabel, $reason, $opts) {
            DB::table('tenant_addon_suppressions')
                ->where('tenant_id', $tenant->id)
                ->where('addon_code', $addonCode)
                ->whereNull('lifted_at')
                ->update([
                    'lifted_at' => now(),
                    'updated_at' => now(),
                ]);

            $existing = DB::table('tenant_feature_addons')
                ->where('tenant_id', $tenant->id)
                ->where('addon_code', $addonCode)
                ->whereIn('status', ['active', 'canceling', 'failed_payment'])
                ->first();

            if ($existing) {
                DB::table('tenant_feature_addons')
                    ->where('id', $existing->id)
                    ->update([
                        'status' => 'active',
                        'source' => $source,
                        'canceling_at' => null,
                        'stripe_subscription_item_id' => $opts['stripe_subscription_item_id'] ?? $existing->stripe_subscription_item_id,
                        'stripe_price_id' => $opts['stripe_price_id'] ?? $existing->stripe_price_id,
                        'current_period_end' => $opts['current_period_end'] ?? $existing->current_period_end,
                        'metadata' => isset($opts['metadata']) ? json_encode($opts['metadata']) : $existing->metadata,
                        'updated_at' => now(),
                    ]);

                $rowId = $existing->id;
            } else {
                $rowId = DB::table('tenant_feature_addons')->insertGetId([
                    'tenant_id' => $tenant->id,
                    'addon_code' => $addonCode,
                    'source' => $source,
                    'status' => 'active',
                    'stripe_subscription_item_id' => $opts['stripe_subscription_item_id'] ?? null,
                    'stripe_price_id' => $opts['stripe_price_id'] ?? null,
                    'activated_at' => now(),
                    'current_period_end' => $opts['current_period_end'] ?? null,
                    'metadata' => isset($opts['metadata']) ? json_encode($opts['metadata']) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->logAudit($tenant, $addonCode, 'activated', $actorType, $actorId, $actorLabel, $reason, [
                'source' => $source,
                'tenant_addon_id' => $rowId,
            ]);

            $this->features->clearCache($tenant);
            $this->runActivationHooks($tenant, $addonCode);

            return DB::table('tenant_feature_addons')->where('id', $rowId)->first();
        });
    }

    public function cancel(Tenant $tenant, string $addonCode, array $opts = []): ?object
    {
        $actorType = $opts['actor_type'] ?? 'tenant';
        $actorId = $opts['actor_id'] ?? null;
        $actorLabel = $opts['actor_label'] ?? null;
        $reason = $opts['reason'] ?? null;

        return DB::transaction(function () use ($tenant, $addonCode, $actorType, $actorId, $actorLabel, $reason) {
            $row = DB::table('tenant_feature_addons')
                ->where('tenant_id', $tenant->id)
                ->where('addon_code', $addonCode)
                ->whereIn('status', ['active', 'failed_payment'])
                ->first();

            if (! $row) {
                return null;
            }

            $hasBillingCycle = $row->current_period_end !== null
                && $row->source === 'self_serve';

            if ($hasBillingCycle) {
                DB::table('tenant_feature_addons')
                    ->where('id', $row->id)
                    ->update([
                        'status' => 'canceling',
                        'canceling_at' => now(),
                        'updated_at' => now(),
                    ]);

                $action = 'canceled';
                $actionMeta = [
                    'access_until' => $row->current_period_end,
                    'tenant_addon_id' => $row->id,
                ];
            } else {
                DB::table('tenant_feature_addons')
                    ->where('id', $row->id)
                    ->update([
                        'status' => 'expired',
                        'canceling_at' => now(),
                        'expired_at' => now(),
                        'updated_at' => now(),
                    ]);

                $action = 'deactivated';
                $actionMeta = [
                    'immediate' => true,
                    'tenant_addon_id' => $row->id,
                ];

                $this->runDeactivationHooks($tenant, $addonCode);
            }

            $this->logAudit($tenant, $addonCode, $action, $actorType, $actorId, $actorLabel, $reason, $actionMeta);
            $this->features->clearCache($tenant);

            return DB::table('tenant_feature_addons')->where('id', $row->id)->first();
        });
    }

    public function expire(Tenant $tenant, string $addonCode, array $opts = []): void
    {
        $row = DB::table('tenant_feature_addons')
            ->where('tenant_id', $tenant->id)
            ->where('addon_code', $addonCode)
            ->whereIn('status', ['active', 'canceling', 'failed_payment'])
            ->first();

        if (! $row) {
            return;
        }

        DB::table('tenant_feature_addons')
            ->where('id', $row->id)
            ->update([
                'status' => 'expired',
                'expired_at' => now(),
                'updated_at' => now(),
            ]);

        $this->logAudit(
            $tenant,
            $addonCode,
            'expired',
            $opts['actor_type'] ?? 'system',
            $opts['actor_id'] ?? null,
            $opts['actor_label'] ?? 'scheduled job',
            $opts['reason'] ?? 'current_period_end reached',
            ['tenant_addon_id' => $row->id]
        );

        $this->features->clearCache($tenant);
        $this->runDeactivationHooks($tenant, $addonCode);
    }

    public function suppress(Tenant $tenant, string $addonCode, array $opts = []): ?object
    {
        $actorId = $opts['actor_id'] ?? null;
        $actorLabel = $opts['actor_label'] ?? null;
        $reason = $opts['reason'] ?? null;

        return DB::transaction(function () use ($tenant, $addonCode, $actorId, $actorLabel, $reason) {
            $existing = DB::table('tenant_addon_suppressions')
                ->where('tenant_id', $tenant->id)
                ->where('addon_code', $addonCode)
                ->whereNull('lifted_at')
                ->first();

            if ($existing) {
                return $existing;
            }

            $id = DB::table('tenant_addon_suppressions')->insertGetId([
                'tenant_id' => $tenant->id,
                'addon_code' => $addonCode,
                'suppressed_by_user_id' => $actorId,
                'reason' => $reason,
                'suppressed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->logAudit($tenant, $addonCode, 'suppressed', 'staff', $actorId, $actorLabel, $reason, [
                'suppression_id' => $id,
            ]);

            $this->features->clearCache($tenant);
            $this->runDeactivationHooks($tenant, $addonCode);

            return DB::table('tenant_addon_suppressions')->where('id', $id)->first();
        });
    }

    public function liftSuppression(Tenant $tenant, string $addonCode, array $opts = []): void
    {
        $actorId = $opts['actor_id'] ?? null;
        $actorLabel = $opts['actor_label'] ?? null;
        $reason = $opts['reason'] ?? null;

        $suppression = DB::table('tenant_addon_suppressions')
            ->where('tenant_id', $tenant->id)
            ->where('addon_code', $addonCode)
            ->whereNull('lifted_at')
            ->first();

        if (! $suppression) {
            return;
        }

        DB::table('tenant_addon_suppressions')
            ->where('id', $suppression->id)
            ->update([
                'lifted_at' => now(),
                'updated_at' => now(),
            ]);

        $this->logAudit($tenant, $addonCode, 'suppression_lifted', 'staff', $actorId, $actorLabel, $reason, [
            'suppression_id' => $suppression->id,
        ]);

        $this->features->clearCache($tenant);

        if ($this->features->hasAddon($tenant, $addonCode)) {
            $this->runActivationHooks($tenant, $addonCode);
        }
    }

    public function markPaymentFailed(Tenant $tenant, string $addonCode, array $opts = []): void
    {
        $row = DB::table('tenant_feature_addons')
            ->where('tenant_id', $tenant->id)
            ->where('addon_code', $addonCode)
            ->whereIn('status', ['active', 'canceling'])
            ->first();

        if (! $row) {
            return;
        }

        DB::table('tenant_feature_addons')
            ->where('id', $row->id)
            ->update([
                'status' => 'failed_payment',
                'updated_at' => now(),
            ]);

        $this->logAudit($tenant, $addonCode, 'payment_failed', 'webhook', null, 'stripe webhook',
            $opts['reason'] ?? null,
            array_merge(['tenant_addon_id' => $row->id], $opts['metadata'] ?? [])
        );

        $this->features->clearCache($tenant);
    }

    public function markPaymentSucceeded(Tenant $tenant, string $addonCode, array $opts = []): void
    {
        $row = DB::table('tenant_feature_addons')
            ->where('tenant_id', $tenant->id)
            ->where('addon_code', $addonCode)
            ->whereIn('status', ['active', 'failed_payment', 'canceling'])
            ->first();

        if (! $row) {
            return;
        }

        $updates = [
            'status' => 'active',
            'updated_at' => now(),
        ];

        if (! empty($opts['current_period_end'])) {
            $updates['current_period_end'] = $opts['current_period_end'];
        }

        DB::table('tenant_feature_addons')
            ->where('id', $row->id)
            ->update($updates);

        $this->logAudit($tenant, $addonCode, 'payment_succeeded', 'webhook', null, 'stripe webhook',
            $opts['reason'] ?? null,
            array_merge(['tenant_addon_id' => $row->id], $opts['metadata'] ?? [])
        );

        $this->features->clearCache($tenant);
    }

    protected function logAudit(
        Tenant $tenant,
        string $addonCode,
        string $action,
        string $actorType,
        ?int $actorId,
        ?string $actorLabel,
        ?string $reason,
        array $metadata = []
    ): void {
        DB::table('addon_audit_log')->insert([
            'tenant_id' => $tenant->id,
            'addon_code' => $addonCode,
            'action' => $action,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_label' => $actorLabel,
            'reason' => $reason,
            'metadata' => $metadata ? json_encode($metadata) : null,
            'created_at' => now(),
        ]);
    }

    protected function runActivationHooks(Tenant $tenant, string $addonCode): void
    {
        match ($addonCode) {
            'waitlist' => $this->activateWaitlist($tenant),
            default => null,
        };
    }

    protected function runDeactivationHooks(Tenant $tenant, string $addonCode): void
    {
        match ($addonCode) {
            default => null,
        };
    }

    protected function activateWaitlist(Tenant $tenant): void
    {
        if (! Schema::hasTable('tenant_waitlist_settings')) {
            return;
        }

        $exists = DB::table('tenant_waitlist_settings')
            ->where('tenant_id', $tenant->id)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('tenant_waitlist_settings')->insert([
            'tenant_id' => $tenant->id,
            'enabled' => 0,
            'similar_match_rule' => 'exact',
            'exclude_first_time_customers' => 0,
            'include_cancellations' => 1,
            'include_new_openings' => 1,
            'include_manual_offers' => 1,
            'notify_sms' => 1,
            'notify_email' => 1,
            'max_entries_per_customer' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
