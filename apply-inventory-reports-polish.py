#!/usr/bin/env python3
"""Inventory reports: use Intake's real components, and stop printing
negative money.

TWO PROBLEMS, the second more serious than the first.

1. DESIGN — I wrote bespoke .ivr-stat / .ivr-table classes when .ia-stat
   and .ia-table already exist, with different sizes and weights: 26px/300
   against the real 24px/500, 12.5px tables with .06em tracking against
   the real 13px/.07em, and my own .num instead of the existing .ia-num.
   That is exactly the "doesn't match the design language" failure. Now it
   uses the real components and keeps only what genuinely doesn't exist
   yet (the window switch and the sell-through bar).

2. DATA — Ground Control has items with NEGATIVE computed_stock_count
   (sold while never received; oversell is allowed by default). The
   consequences on screen were:
     * "Inventory at cost $0.00 · 0 units · 0 SKUs" while the category
       table listed 5,304 SKUs — valuation filtered stock > 0, so a shelf
       full of negative counts summed to nothing.
     * "At cost $-626.16" — negative stock multiplied by unit cost.
   Negative money is meaningless. Value math now floors on-hand at zero,
   and the negative count is surfaced as its own signal, because
   "sold items you never received" is a real thing a shop needs to fix,
   not something to quietly clamp away.
Run from repo root: python3 apply-inventory-reports-polish.py
"""
import sys

SVC  = 'app/Services/Tenant/InventoryReportService.php'
VIEW = 'resources/views/tenant/inventory/reports.blade.php'

def sub(p, old, new, label):
    s = open(p).read()
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    open(p, 'w').write(s.replace(old, new, 1))
    print(f"OK: {label}")

# ============================================================
# 1) Valuation — floor at zero, and count the negatives separately
# ============================================================
sub(SVC,
    """        $row = DB::table('tenant_inventory_items')
            ->where('tenant_id', $this->tenant->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('computed_stock_count', '>', 0)
            ->selectRaw('COUNT(*) as skus')
            ->selectRaw('SUM(computed_stock_count) as units')
            ->selectRaw('SUM(computed_stock_count * COALESCE(shop_cost_cents, catalog_cost_cents, 0)) as cost')
            ->selectRaw('SUM(computed_stock_count * COALESCE(shop_sell_price_cents, catalog_msrp_cents, 0)) as retail')
            ->first();""",
    """        // MARKER-INV-REPORTS-NEG — oversell is allowed, so an item sold but
        // never received carries a NEGATIVE computed_stock_count. Multiplying
        // that by unit cost produced negative money on screen, which means
        // nothing. Value math floors on-hand at zero; the negatives are
        // reported separately below because they're a real thing to fix.
        $row = DB::table('tenant_inventory_items')
            ->where('tenant_id', $this->tenant->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->selectRaw('SUM(CASE WHEN computed_stock_count > 0 THEN 1 ELSE 0 END) as skus')
            ->selectRaw('SUM(GREATEST(computed_stock_count, 0)) as units')
            ->selectRaw('SUM(GREATEST(computed_stock_count, 0) * COALESCE(shop_cost_cents, catalog_cost_cents, 0)) as cost')
            ->selectRaw('SUM(GREATEST(computed_stock_count, 0) * COALESCE(shop_sell_price_cents, catalog_msrp_cents, 0)) as retail')
            ->selectRaw('SUM(CASE WHEN computed_stock_count < 0 THEN 1 ELSE 0 END) as negative_skus')
            ->first();""",
    "service: valuation floors at zero")

sub(SVC,
    """        return [
            'skus'         => (int) ($row->skus ?? 0),
            'units'        => (int) ($row->units ?? 0),""",
    """        return [
            'skus'          => (int) ($row->skus ?? 0),
            'units'         => (int) ($row->units ?? 0),
            // Sold but never received — worth fixing, so it's shown.
            'negative_skus' => (int) ($row->negative_skus ?? 0),""",
    "service: expose negative count")

# ============================================================
# 2) Category rollup — same flooring
# ============================================================
sub(SVC,
    """            $rows[$key]['units']      += (int) $it->computed_stock_count;
            $rows[$key]['cost_cents'] += (int) $it->computed_stock_count * (int) $it->unit_cost;""",
    """            // MARKER-INV-REPORTS-NEG — floor here too, or a category with
            // oversold items reports negative units and negative cost.
            $onHand = max(0, (int) $it->computed_stock_count);
            $rows[$key]['units']      += $onHand;
            $rows[$key]['cost_cents'] += $onHand * (int) $it->unit_cost;""",
    "service: category floors at zero")

# ============================================================
# 3) The view — real components
# ============================================================
sub(VIEW,
    """<div class="ivr-stats">
  <div class="ivr-stat">
    <div class="ivr-stat-label">Inventory at cost</div>
    <div class="ivr-stat-value">{{ format_money($valuation['cost_cents']) }}</div>
    <div class="ivr-stat-note">{{ number_format($valuation['units']) }} units · {{ number_format($valuation['skus']) }} SKUs</div>
  </div>
  <div class="ivr-stat">
    <div class="ivr-stat-label">Retail value</div>
    <div class="ivr-stat-value">{{ format_money($valuation['retail_cents']) }}</div>
    <div class="ivr-stat-note">
      @if($valuation['margin_pct'] !== null){{ $valuation['margin_pct'] }}% margin on hand @else no prices set @endif
    </div>
  </div>
  <div class="ivr-stat">
    <div class="ivr-stat-label">Turns · trailing 12mo</div>
    <div class="ivr-stat-value">
      @if($turns['turns'] !== null){{ $turns['turns'] }}&times;@else &mdash; @endif
    </div>
    {{-- Said plainly: there's no inventory history to average, so this is
         against stock as it stands today. --}}
    <div class="ivr-stat-note">{{ format_money($turns['cogs_12mo_cents']) }} cost of goods sold, vs stock on hand now</div>
  </div>
  <div class="ivr-stat {{ $dead['cost_cents'] > 0 ? 'is-warn' : '' }}">
    <div class="ivr-stat-label">Dead stock</div>
    <div class="ivr-stat-value">{{ format_money($dead['cost_cents']) }}</div>
    <div class="ivr-stat-note">{{ number_format($dead['skus']) }} SKUs · nothing sold in {{ $dead['days'] }}d</div>
  </div>
</div>""",
    """{{-- MARKER-INV-REPORTS-POLISH — .ia-stats-grid / .ia-stat / .ia-stat-label
     / .ia-stat-value / .ia-stat-delta are the app's real components. The
     first version invented its own and drifted on size, weight and
     tracking. --}}
<div class="ia-stats-grid">
  <div class="ia-stat">
    <div class="ia-stat-label">Inventory at cost</div>
    <div class="ia-stat-value">{{ format_money($valuation['cost_cents']) }}</div>
    <div class="ia-stat-delta">{{ number_format($valuation['units']) }} units · {{ number_format($valuation['skus']) }} SKUs</div>
  </div>
  <div class="ia-stat">
    <div class="ia-stat-label">Retail value</div>
    <div class="ia-stat-value">{{ format_money($valuation['retail_cents']) }}</div>
    <div class="ia-stat-delta">
      @if($valuation['margin_pct'] !== null){{ $valuation['margin_pct'] }}% margin on hand @else no prices set @endif
    </div>
  </div>
  <div class="ia-stat">
    <div class="ia-stat-label">Turns · trailing 12mo</div>
    <div class="ia-stat-value">
      @if($turns['turns'] !== null){{ $turns['turns'] }}&times;@else &mdash; @endif
    </div>
    {{-- Said plainly: there's no inventory history to average, so this is
         measured against stock as it stands today. --}}
    <div class="ia-stat-delta">{{ format_money($turns['cogs_12mo_cents']) }} sold at cost, vs stock now</div>
  </div>
  <div class="ia-stat">
    <div class="ia-stat-label">Dead stock</div>
    <div class="ia-stat-value">{{ format_money($dead['cost_cents']) }}</div>
    <div class="ia-stat-delta">{{ number_format($dead['skus']) }} SKUs · nothing sold in {{ $dead['days'] }}d</div>
  </div>
</div>

@if(($valuation['negative_skus'] ?? 0) > 0)
  {{-- Sold but never received. Not a display quirk — the stock figures
       can't be right until these are reconciled. --}}
  <div class="ia-flash ia-flash--info" style="margin-bottom:16px">
    {{ number_format($valuation['negative_skus']) }}
    {{ Str::plural('item', $valuation['negative_skus']) }} show negative stock — sold without ever being received.
    Value totals below count them as zero, not as negative stock.
  </div>
@endif""",
    "view: real stat components")

# Tables
sub(VIEW,
    """    <table class="ia-table ivr-table">
      <thead>
        <tr><th>Category</th><th class="num">SKUs</th><th class="num">On hand</th><th class="num">At cost</th><th class="num">Sold</th><th class="num">Sell-through</th></tr>
      </thead>""",
    """    <table class="ia-table">
      <thead>
        <tr><th>Category</th><th class="ia-num">SKUs</th><th class="ia-num">On hand</th><th class="ia-num">At cost</th><th class="ia-num">Sold</th><th class="ia-num">Sell-through</th></tr>
      </thead>""",
    "view: category table")

for old, new, label in [
    ('<td class="num">{{ number_format($c[\'skus\']) }}</td>',
     '<td class="ia-num">{{ number_format($c[\'skus\']) }}</td>', 'cat skus'),
    ('<td class="num">{{ number_format($c[\'units\']) }}</td>',
     '<td class="ia-num">{{ number_format($c[\'units\']) }}</td>', 'cat units'),
    ('<td class="num">{{ format_money($c[\'cost_cents\']) }}</td>',
     '<td class="ia-num">{{ format_money($c[\'cost_cents\']) }}</td>', 'cat cost'),
    ('<td class="num">{{ rtrim(rtrim(number_format($c[\'sold_units\'], 2), \'0\'), \'.\') }}</td>',
     '<td class="ia-num">{{ rtrim(rtrim(number_format($c[\'sold_units\'], 2), \'0\'), \'.\') }}</td>', 'cat sold'),
    ('<td class="num">\n              @if($c[\'sell_through_pct\'] !== null)',
     '<td class="ia-num">\n              @if($c[\'sell_through_pct\'] !== null)', 'cat sell-through'),
]:
    sub(VIEW, old, new, 'view: ' + label)

sub(VIEW,
    """      <table class="ia-table ivr-table">
        <thead><tr><th>Item</th><th class="num">Sold</th><th class="num">On hand</th></tr></thead>""",
    """      <table class="ia-table">
        <thead><tr><th>Item</th><th class="ia-num">Sold</th><th class="ia-num">On hand</th></tr></thead>""",
    "view: movers table")

sub(VIEW,
    """              <td class="num">{{ rtrim(rtrim(number_format($m['units'], 2), '0'), '.') }}</td>
              <td class="num">{{ number_format($m['on_hand']) }}</td>""",
    """              <td class="ia-num">{{ rtrim(rtrim(number_format($m['units'], 2), '0'), '.') }}</td>
              <td class="ia-num">{{ number_format($m['on_hand']) }}</td>""",
    "view: movers cells")

sub(VIEW,
    """      <table class="ia-table ivr-table">
        <thead><tr><th>Item</th><th class="num">On hand</th><th class="num">Tied up</th></tr></thead>""",
    """      <table class="ia-table">
        <thead><tr><th>Item</th><th class="ia-num">On hand</th><th class="ia-num">Tied up</th></tr></thead>""",
    "view: stuck table")

sub(VIEW,
    """              <td class="num">{{ number_format($d->computed_stock_count) }}</td>
              <td class="num">{{ format_money($d->tied_cents) }}</td>""",
    """              <td class="ia-num">{{ number_format($d->computed_stock_count) }}</td>
              <td class="ia-num">{{ format_money($d->tied_cents) }}</td>""",
    "view: stuck cells")

# ============================================================
# 4) Styles — keep only what the app doesn't already provide
# ============================================================
s = open(VIEW).read()
start = s.index('  .ivr-window{')
end   = s.index('</style>')
KEEP = """  /* MARKER-INV-REPORTS-POLISH — only what base.css doesn't already have.
     Stat cards and tables now use .ia-stat / .ia-table, so their type,
     weights and spacing come from the app rather than from here. */
  .ivr-window{display:inline-flex;background:var(--ia-surface);border:1px solid var(--ia-border);border-radius:9px;padding:3px}
  .ivr-window a{padding:5px 11px;font-size:12px;font-weight:600;border-radius:6px;color:var(--ia-text-dim);text-decoration:none}
  .ivr-window a.on{background:var(--ia-surface-2);color:var(--ia-text)}
  .ia-table tbody tr{cursor:default}
  .ivr-hint{font-size:11.5px;color:var(--ia-text-muted)}
  .ivr-sku{font-size:11px;color:var(--ia-text-muted);font-family:ui-monospace,Menlo,monospace;margin-top:1px}
  .ivr-bar{display:inline-block;width:52px;height:5px;border-radius:99px;background:rgba(127,127,127,.2);
    overflow:hidden;vertical-align:middle;margin-right:7px}
  .ivr-bar>span{display:block;height:100%;background:var(--ia-accent)}
  .ivr-empty{padding:28px 16px;text-align:center;color:var(--ia-text-muted);font-size:13px}
  .ivr-two{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  @media (max-width:1000px){ .ivr-two{grid-template-columns:1fr} }
"""
open(VIEW, 'w').write(s[:start] + KEEP + s[end:])
print("OK: styles reduced to what the app lacks")

# ============================================================
# 5) .ia-flash--info exists only in the DARK theme
# ============================================================
# base.css defines --success and --error; theme-c adds --info for dark.
# Nothing defines it for the light theme, so the notice above would render
# as unstyled text on Light Premium — the same trap as the undefined
# .ia-banner class flagged earlier. Add the light default beside its
# siblings so theme-c keeps overriding it for dark.
CSS = 'public/css/tenant/base.css'
c = open(CSS).read()
if '.ia-flash--info' in c:
    print("SKIP (already applied): ia-flash--info light default")
else:
    old = ".ia-flash--error   { background: #FCEBEB; color: #A32D2D; }"
    if old not in c:
        print("FAIL: .ia-flash--error anchor not found"); sys.exit(1)
    open(CSS, 'w').write(c.replace(
        old,
        old + "\n.ia-flash--info    { background: #E8F0F8; color: #1F4E79; } /* MARKER-INV-REPORTS-POLISH — light default; theme-c overrides for dark */",
        1))
    print("OK: ia-flash--info light default")


print("\\nDone. No migration needed. view:clear after deploy.")
