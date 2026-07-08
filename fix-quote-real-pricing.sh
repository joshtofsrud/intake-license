#!/usr/bin/env bash
# fix-quote-real-pricing.sh — swap the quote engine onto REAL pricing sources.
#
# The deployed quote engine (first campaigns deploy) prices from a hardcoded
# config/sales_quoting.php ($249/$329/$389 mockup numbers). This swaps it to:
#   tiers   -> config('intake.plan_prices')   (starter/branded/scale, cents)
#   add-ons -> `addons` DB table              (price_cents, included_in_plans;
#              an add-on included in the chosen tier prices at +$0)
# and removes config/sales_quoting.php. Master admin stays the single source
# of pricing truth — change a price there and new quotes pick it up.
#
# Run from the repo root:  bash fix-quote-real-pricing.sh
# Idempotent: skips when no sales_quoting references remain.
set -euo pipefail

[ -f artisan ] || { echo "ERROR: run from the Laravel repo root."; exit 1; }
if ! grep -q "sales_quoting" app/Models/SalesProspect.php; then
  echo "fix-quote-real-pricing.sh: already applied — skipping."; exit 0
fi

echo "Swapping quote engine to live pricing …"

python3 - <<'PYEOF'
def rd(p):
    with open(p, encoding="utf-8") as f: return f.read()
def wr(p, s):
    with open(p, "w", encoding="utf-8") as f: f.write(s)
def edit(p, old, new):
    s = rd(p); n = s.count(old)
    assert n == 1, f"ANCHOR count={n} in {p} (expected 1) for: {old[:70]!r}"
    wr(p, s.replace(old, new, 1)); print(f"  edited {p}")

M = "app/Models/SalesProspect.php"

# DB facade import for addon pricing lookups
edit(M,
"use Illuminate\\Database\\Eloquent\\Relations\\HasMany;",
"use Illuminate\\Database\\Eloquent\\Relations\\HasMany;\nuse Illuminate\\Support\\Facades\\DB;")

# swap the config-based engine for the real-source one (+ COMMISSION_YEAR1,
# which the rep panel's fallback references)
edit(M,
"""    /** Quote = tier base + add-ons, priced from config/sales_quoting.php. */
    public function computeQuoteMonthly(): ?int
    {
        if (! $this->quote_tier) {
            return null;
        }
        $tiers  = config('sales_quoting.tiers', []);
        $addons = config('sales_quoting.addons', []);
        $base   = $tiers[$this->quote_tier]['monthly'] ?? null;
        if ($base === null) {
            return null;
        }
        foreach ((array) $this->quote_addons as $key) {
            $base += $addons[$key]['monthly'] ?? 0;
        }
        return (int) $base;
    }
""",
"""    // MARKER-QUOTE-REALPRICING — priced from the platform's real sources.
    /** Reference rate; per-agency rates on sales_agencies supersede this. */
    public const COMMISSION_YEAR1 = 0.25;

    /**
     * Quote = tier base + add-ons, in whole dollars. Tiers come from
     * config('intake.plan_prices') (cents); add-ons from the `addons` table.
     * An add-on whose included_in_plans covers the chosen tier prices at +$0
     * — same rule FeatureAccessService applies.
     */
    public function computeQuoteMonthly(): ?int
    {
        if (! $this->quote_tier) {
            return null;
        }
        $plans = config('intake.plan_prices', []);
        if (! isset($plans[$this->quote_tier])) {
            return null;
        }
        $sum = (int) round(((int) $plans[$this->quote_tier]) / 100);

        $selected = (array) $this->quote_addons;
        if ($selected !== []) {
            $rows = DB::table('addons')
                ->whereIn('code', $selected)
                ->get(['code', 'price_cents', 'included_in_plans']);
            foreach ($rows as $a) {
                $included = in_array(
                    $this->quote_tier,
                    (array) json_decode($a->included_in_plans ?? '[]', true),
                    true
                );
                if (! $included) {
                    $sum += (int) round(((int) $a->price_cents) / 100);
                }
            }
        }
        return $sum;
    }
""")

R = "app/Filament/Resources/SalesProspectResource.php"

# description
edit(R,
"                ->description('Proposed subscription \u2014 tier + add-ons. Monthly total snapshots on save from config/sales_quoting.php.')",
"                ->description('Proposed subscription \u2014 tier + add-ons. Priced from plan_prices and the addons table; monthly total snapshots on save.')")

# tier options: plan_prices (cents), $0 tiers (custom) excluded
edit(R,
"                        ->options(collect(config('sales_quoting.tiers', []))->map(fn ($t) => $t['label'] . ' \u2014 $' . $t['monthly'] . '/mo')->all())",
"""                        ->options(fn () => collect(config('intake.plan_prices', []))
                            ->filter(fn ($cents) => (int) $cents > 0)
                            ->map(fn ($cents, $key) => ucfirst($key) . ' \u2014 $' . number_format($cents / 100) . '/mo')
                            ->all())""")

# addon options: live addons table, tier-aware "included" labels
edit(R,
"                        ->options(collect(config('sales_quoting.addons', []))->map(fn ($a) => $a['label'] . ' (+$' . $a['monthly'] . ')')->all()),",
"""                        ->options(function (\\Filament\\Forms\\Get $get) {
                            $tier = $get('quote_tier');
                            return \\Illuminate\\Support\\Facades\\DB::table('addons')
                                ->where('status', 'active')
                                ->orderBy('sort_order')
                                ->get(['code', 'name', 'price_cents', 'included_in_plans'])
                                ->mapWithKeys(function ($a) use ($tier) {
                                    $included = $tier && in_array($tier, (array) json_decode($a->included_in_plans ?? '[]', true), true);
                                    $label = $a->name . ($included
                                        ? ' \u2014 included in tier'
                                        : ' (+$' . number_format($a->price_cents / 100) . '/mo)');
                                    return [$a->code => $label];
                                })->all();
                        }),""")

# placeholder body
edit(R,
"""                            $tiers  = config('sales_quoting.tiers', []);
                            $addons = config('sales_quoting.addons', []);
                            $tier   = $get('quote_tier');
                            if (! $tier || ! isset($tiers[$tier])) {
                                return '\u2014';
                            }
                            $sum = $tiers[$tier]['monthly'];
                            foreach ((array) $get('quote_addons') as $key) {
                                $sum += $addons[$key]['monthly'] ?? 0;
                            }
                            $rate = (float) config('sales_quoting.commission_year1', 0.25);""",
"""                            $plans = config('intake.plan_prices', []);
                            $tier  = $get('quote_tier');
                            if (! $tier || empty($plans[$tier])) {
                                return '\u2014';
                            }
                            $sum = (int) round(((int) $plans[$tier]) / 100);
                            $selected = (array) $get('quote_addons');
                            if ($selected !== []) {
                                $rows = \\Illuminate\\Support\\Facades\\DB::table('addons')
                                    ->whereIn('code', $selected)
                                    ->get(['code', 'price_cents', 'included_in_plans']);
                                foreach ($rows as $a) {
                                    $included = in_array($tier, (array) json_decode($a->included_in_plans ?? '[]', true), true);
                                    if (! $included) {
                                        $sum += (int) round(((int) $a->price_cents) / 100);
                                    }
                                }
                            }
                            $rate = \\App\\Models\\SalesProspect::COMMISSION_YEAR1;""")

print("All anchored edits applied.")
PYEOF

# retire the hardcoded price list
if [ -f config/sales_quoting.php ]; then
  rm config/sales_quoting.php
  echo "  removed config/sales_quoting.php"
fi

echo ""
echo "Done. Deploy notes:"
echo "  - config change removed a file: run optimize:clear (config cache)."
echo "  - Any quotes saved with the OLD tier keys (core/standard/advanced) no"
echo "    longer resolve. Reset them on the server after deploy:"
echo "      php artisan tinker --execute=\"echo \\App\\Models\\SalesProspect::whereIn('quote_tier',['core','standard','advanced'])->update(['quote_tier'=>null,'quote_addons'=>null,'quote_monthly'=>null]);\""
echo "    (prints how many were reset — likely 0 or a handful)"
