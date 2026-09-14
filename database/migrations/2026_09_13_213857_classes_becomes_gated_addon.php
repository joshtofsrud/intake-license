<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-CLASSES-ADDON — classes gets a plan floor it never had.
 *
 * Before this, classes_enabled was a tenant flag with no plan check anywhere,
 * so a Starter could turn on a Branded feature from Settings and keep it.
 *
 * Included with Branded and up; purchasable on Starter, which is why this is
 * self-serve rather than the hard floor the online store uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // The addon itself. updateOrInsert so a re-run cannot duplicate it.
        DB::table('addons')->updateOrInsert(
            ['code' => 'classes'],
            [
                'name'                   => 'Group classes',
                'category'               => 'operations',
                'description'            => 'Run scheduled group sessions with their own capacity, registrations and waitlist. Included with Branded and Scale.',
                'tooltip'                => 'Class templates, sessions, per-session capacity, registrations and cancellations.',
                'price_cents'            => 0,
                'billing_cadence'        => 'monthly',
                'price_display_override' => 'Included',
                'included_in_plans'      => json_encode(['branded', 'scale', 'custom']),
                'sort_order'             => 120,
                'status'                 => 'active',
                'is_self_serve'          => 1,
                'is_new'                 => 1,
                'updated_at'             => $now,
                'created_at'             => $now,
            ]
        );

        // Grandfather anyone already using it. A shop with classes running has
        // customers booked onto them; taking the feature away because the
        // billing model changed would cancel real bookings.
        $existing = DB::table('tenants')
            ->where('classes_enabled', true)
            ->pluck('id');

        $granted = 0;

        foreach ($existing as $tenantId) {
            $already = DB::table('tenant_feature_addons')
                ->where('tenant_id', $tenantId)
                ->where('addon_code', 'classes')
                ->exists();

            if ($already) {
                continue;
            }

            DB::table('tenant_feature_addons')->insert([
                'tenant_id'    => $tenantId,
                'addon_code'   => 'classes',
                'source'       => 'grandfathered',
                'status'       => 'active',
                'activated_at' => $now,
                'metadata'     => json_encode([
                    'note' => 'Had classes_enabled before classes became a gated addon.',
                ]),
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            $granted++;
        }

        if ($granted > 0) {
            echo "  MARKER-CLASSES-ADDON: grandfathered {$granted} tenant(s) already running classes\n";
        }
    }

    public function down(): void
    {
        DB::table('tenant_feature_addons')
            ->where('addon_code', 'classes')
            ->where('source', 'grandfathered')
            ->delete();

        DB::table('addons')->where('code', 'classes')->delete();
    }
};
