#!/usr/bin/env bash
set -euo pipefail
# apply-ecommerce-as-addon.sh — MARKER-ECOMADDON
# Ecommerce becomes a real add-on instead of something bundled with a plan.
#
# NO CODE CHANGES ARE NEEDED — the gating already exists and already works:
#   Tenant::getOnlineStoreEnabledAttribute() = hasAddon($tenant,'online_store')
#   nav entries (_nav-items, _more-drawer) carry gate => 'online_store_enabled'
#   StorefrontSettingsController + OrdersController abort_unless on it
#   StorefrontController / CartController / CheckoutController check the addon
# The only reason Storefront is visible today is that the addon row says it is
# INCLUDED with branded + scale, so the gate always answered yes.
#
# THIS IS A DATA CHANGE, and it is what actually turns it off:
#   included_in_plans      ['branded','scale'] -> null   (no plan grants it)
#   price_display_override 'Included'          -> null   (stop claiming it)
#   min_plan_tier          null                -> 'branded'
#     ^ the original migration's stated floor was "never available on Starter".
#       That floor was previously implied by included_in_plans; with inclusion
#       gone it has to be explicit, or a Starter tenant could be granted it.
#
# EFFECT ON DEPLOY: every tenant loses Storefront — nav entry, settings page,
# public store, cart, checkout and Orders — until granted the add-on
# explicitly. That is the intent (Josh: "I want it to be removed, let's turn it
# off, then I'll add it to grndctrl"), so this is NOT a silent regression.
#
# PRICE IS DELIBERATELY UNCHANGED (still price_cents 0). Josh hasn't set one,
# and inventing a number in a migration would be worse than leaving it. Setting
# it later is a one-line UPDATE — no schema work, no code.
#
# The FeatureAccessService cache is request-scoped only, so this takes effect
# immediately with no cache flush.

MIG=database/migrations/2026_08_09_170000_online_store_becomes_paid_addon.php

[ -d database/migrations ] || { echo "MISSING database/migrations — run from the repo root"; exit 1; }

if [ -f "$MIG" ]; then
  echo "Already applied (migration present) — no-op."
  exit 0
fi

cat <<'EOF' > "$MIG"
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-ECOMADDON — online_store stops being bundled with a plan.
 *
 * The gate (Tenant::online_store_enabled -> hasAddon) already governs the nav
 * entry, the settings page, Orders, and the whole public storefront. Removing
 * the plan inclusion is therefore the entire change: no tenant gets ecommerce
 * unless the add-on is granted to them.
 *
 * min_plan_tier is set explicitly because the original decision was "never on
 * Starter", and that floor used to be implied by included_in_plans.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('addons')->where('code', 'online_store')->update([
            'included_in_plans'      => null,
            'price_display_override' => null,
            'min_plan_tier'          => 'branded',
            'is_self_serve'          => 0,
            'updated_at'             => now(),
        ]);
    }

    public function down(): void
    {
        // Restore the previous bundling exactly.
        DB::table('addons')->where('code', 'online_store')->update([
            'included_in_plans'      => json_encode(['branded', 'scale']),
            'price_display_override' => 'Included',
            'min_plan_tier'          => null,
            'updated_at'             => now(),
        ]);
    }
};
EOF

echo "ok   migration created — online_store no longer included in any plan"

echo ""
echo "SUCCESS — apply-ecommerce-as-addon applied."
echo "After deploy, Storefront disappears for every tenant until the add-on is"
echo "granted. Grant it to Ground Control from master admin."
