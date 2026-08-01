#!/bin/bash
# item-page-sourcing — Option A: one sourcing table, honest identity.
#
#   The page assumed one distributor in three places at once:
#
#     · $hlcSrc = first vendor whose distributor_code === 'HLC' — hardcoded,
#       so a BTI-sourced item showed a blank cost and blank availability
#       while the Identity card said "Source BTI"
#     · a row literally labelled "Available (HLC)"
#     · one unattributed MPN and one SKU, which on a matched item are
#       different distributors' numbers sitting in fields that don't say
#       whose they are
#
#   Option A: every vendor question moves into one Sourcing card — who has
#   it, your cost, how many, where, their part number, their MSRP. Identity
#   keeps only what belongs to the PRODUCT: brand, category, barcode, your
#   own SKU. Availability and part numbers are properties of a source, so
#   they only ever appear inside one.
#
#   The old "Sourced from" tab is removed: it answered a subset of the same
#   question from a hidden tab, and leaving both would let them disagree.
#
#   Sorted cheapest first, with two badges that mark different things —
#   CHEAPEST is where to buy, INFO is which feed supplies the name and specs
#   (your distributor order decides that, not price). A shop shouldn't have
#   to infer one from the other.
#
#   Also repairs BTI's description: group_text ships \n as literal
#   characters, so the specs card rendered "\n - Redesigned Trail…". Fixed at
#   display for now — the durable fix is an unescape transform in the field
#   map, which is a separate change.
# NO MIGRATION. Server: view:clear
set -e
if grep -q "MARKER-ITEM-SOURCING" resources/views/tenant/inventory/show.blade.php; then
  echo "item-page-sourcing already applied — aborting."; exit 1
fi

python3 - <<'IPS_0_EOF'
import io
p = 'resources/views/tenant/inventory/show.blade.php'
s = io.open(p, encoding='utf-8').read()

# ------------------------------------------------ 1. per-source derivation
old = """  // --- live HLC cost/avail ---
  $hlcSrc = $item->vendors->first(fn ($v) => ($v->pivot->distributor_code ?? null) === 'HLC');
  $liveCost = $hlcSrc?->pivot?->live_cost_cents;
  $liveAvail = $hlcSrc?->pivot?->live_avail;
  $liveCheckedRaw = $hlcSrc?->pivot?->live_checked_at;
  $liveChecked = $liveCheckedRaw ? \\Illuminate\\Support\\Carbon::parse($liveCheckedRaw) : null;"""
assert s.count(old) == 1, ('derivation', s.count(old))
new = """  // MARKER-ITEM-SOURCING — every distributor that can supply this item.
  //
  // This used to pick the FIRST vendor whose distributor_code was 'HLC' and
  // treat it as the item's cost and availability. On a BTI item that found
  // nothing, so the page showed blanks while Identity said "Source BTI".
  $infoCatalogId = $item->distributor_catalog_id;

  $sources = $item->vendors
      ->filter(fn ($v) => filled($v->pivot->distributor_code ?? null))
      ->map(function ($v) use ($infoCatalogId) {
          $cost = $v->pivot->live_cost_cents ?? $v->pivot->unit_cost_cents;
          return (object) [
              'vendor'    => $v,
              'code'      => $v->pivot->distributor_code,
              'sku'       => $v->pivot->vendor_sku,
              'cost'      => $cost,
              'avail'     => $v->pivot->live_avail,
              'lead'      => $v->pivot->lead_time_days,
              'checked'   => $v->pivot->live_checked_at
                  ? \\Illuminate\\Support\\Carbon::parse($v->pivot->live_checked_at) : null,
              // Which source supplies the name, description and specs.
              'is_info'   => $infoCatalogId && $v->pivot->distributor_catalog_id === $infoCatalogId,
              // A source that has never been synced is not the same as one
              // reporting nothing.
              'synced'    => $v->pivot->live_checked_at !== null,
          ];
      })
      // Cheapest first; a source with no cost sorts last rather than as free.
      ->sortBy(fn ($r) => $r->cost ?? PHP_INT_MAX)
      ->values();

  $bestCost = $sources->pluck('cost')->filter()->min();

  // Kept so the rest of the page still renders while it references them.
  $liveCost = $bestCost;
  $liveAvail = $sources->pluck('avail')->filter(fn ($a) => $a !== null)->max();
  $liveChecked = $sources->pluck('checked')->filter()->max();"""
s = s.replace(old, new)

# ------------------------------------------------ 2. drop the per-vendor rows
old = """          <tr><td>Your dealer cost (live)</td><td>{{ $money($liveCost) }}</td></tr>
          <tr><td>Available (HLC)</td><td>{{ $liveAvail !== null ? $liveAvail : '—' }}</td></tr>"""
assert s.count(old) == 1, ('catalog rows', s.count(old))
new = """          {{-- MARKER-ITEM-SOURCING — cost and availability belong to a source,
               not to the item, so they live in the Sourcing card. --}}"""
s = s.replace(old, new)

# ------------------------------------------------ 3. identity = the product
old = """          <tr><td>MPN</td><td>{{ $mpn ?: '—' }}</td></tr>
          <tr><td>SKU</td><td><code>{{ $item->sku }}</code></td></tr>
          <tr><td>UPC</td><td><code>{{ $item->catalog_upc ?? '—' }}</code></td></tr>
          <tr><td>Source</td><td>{{ $item->distributor_catalog_id ? ($item->distributorCatalog?->distributor_name ?? 'distributor') : 'manual' }}</td></tr>"""
assert s.count(old) == 1, ('identity', s.count(old))
new = """          {{-- MARKER-ITEM-SOURCING — only what belongs to the PRODUCT. A part
               number is per distributor and now sits on that distributor's row
               in Sourcing; showing one unlabelled MPN meant it was right for
               at most one of them. --}}
          <tr><td>Your SKU</td><td><code>{{ $item->sku }}</code></td></tr>
          <tr><td>UPC</td><td><code>{{ $item->catalog_upc ?: ($item->distributorCatalog?->upc ?: '—') }}</code></td></tr>
          @if($item->distributorCatalog?->ean)
            <tr><td>EAN</td><td><code>{{ $item->distributorCatalog->ean }}</code></td></tr>
          @endif"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('derivation + identity ok')
IPS_0_EOF

# ------------------------------------------------ 4. the sourcing card
python3 - <<'IPS_1_EOF'
import io
p = 'resources/views/tenant/inventory/show.blade.php'
s = io.open(p, encoding='utf-8').read()

# Replace the hidden "Sourced from" tab panel with nothing, and put a real
# card above the tab strip instead.
old_start = s.index("  {{-- Sourced from --}}")
old_end = s.index("@include('tenant.special-orders._drawer'")
old_block = s[old_start:old_end]
assert 'data-panel="src"' in old_block, 'sourced-from panel not found'
s = s[:old_start] + "</div>\n\n" + s[old_end:]

# the tab button goes too
old_tab = """    @if($item->vendors->count() > 0)<button type="button" class="ia-tab" data-tab="src">Sourced from</button>@endif"""
assert s.count(old_tab) == 1, ('tab button', s.count(old_tab))
s = s.replace(old_tab, """    {{-- MARKER-ITEM-SOURCING — the Sourced from tab is now a card above. --}}""")

# insert the card just before the tab strip
anchor = """  {{-- Sourced from --}}"""
card = """  {{-- MARKER-ITEM-SOURCING — one table answering every vendor question:
       who has it, your cost, how many, where, and their part number for
       the purchase order. --}}
  @if($sources->count())
  <div class="ia-card" style="margin-bottom:18px">
    <div class="ia-card-head">
      <span class="ia-card-title">Sourcing</span>
      <span style="margin-left:auto;font-size:11.5px;color:var(--ia-text-dim)">
        {{ $sources->count() }} distributor{{ $sources->count() === 1 ? '' : 's' }} carry this
        @if($liveChecked) · checked {{ $liveChecked->diffForHumans() }} @endif
      </span>
    </div>
    <table class="ia-table">
      <thead>
        <tr>
          <th>Distributor</th><th>Their part no.</th>
          <th style="text-align:right">Your cost</th>
          <th style="text-align:right">Available</th>
          <th>Lead time</th><th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($sources as $src)
          <tr>
            <td>
              <strong>{{ $src->code }}</strong>
              @if($src->cost !== null && $src->cost === $bestCost && $sources->count() > 1)
                <span class="ia-badge ia-badge--accent" style="margin-left:6px">Cheapest</span>
              @endif
              @if($src->is_info)
                <span class="ia-badge" style="margin-left:6px">Info</span>
              @endif
            </td>
            <td style="color:var(--ia-text-muted);font-family:var(--ia-mono);font-size:12px">
              {{ $src->sku ?: '—' }}
            </td>
            <td style="text-align:right">
              @if($src->cost !== null){{ format_money($src->cost) }}
              @else<span style="color:var(--ia-text-muted)">—</span>@endif
            </td>
            <td style="text-align:right">
              @if($src->avail !== null){{ $src->avail }}
              @elseif(! $src->synced)<span style="color:var(--ia-text-muted)">not synced</span>
              @else<span style="color:var(--ia-text-muted)">unknown</span>@endif
            </td>
            <td>@if($src->lead !== null){{ $src->lead }}d @else<span style="color:var(--ia-text-muted)">—</span>@endif</td>
            <td style="text-align:right">
              <a class="ia-btn ia-btn--ghost ia-btn--sm"
                 href="{{ route('tenant.vendors.show', ['id' => $src->vendor->id]) }}">Vendor</a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    <div class="ia-card-body" style="border-top:.5px solid var(--ia-border);padding-top:11px">
      <div style="font-size:11.5px;color:var(--ia-text-dim);line-height:1.55">
        <b>Info</b> marks the distributor supplying this item's name, description and specs — set by
        your distributor order on Connection &amp; sync, not by price. Availability is each
        distributor's last reported figure, not live.
      </div>
    </div>
  </div>
  @endif

"""
# put the card where the tab panel used to begin, i.e. before the closing div
idx = s.index("""    {{-- MARKER-ITEM-SOURCING — the Sourced from tab is now a card above. --}}""")
# find the start of the tab strip container to insert before it
strip = s.rindex("<div", 0, idx)
line_start = s.rindex("\n", 0, strip) + 1
s = s[:line_start] + card + s[line_start:]

io.open(p, 'w', encoding='utf-8').write(s)
print('sourcing card ok')
IPS_1_EOF

# ------------------------------------------------ 5. repair BTI's \n
python3 - <<'IPS_2_EOF'
import io
p = 'resources/views/tenant/inventory/show.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """  $catDesc   = $item->distributorCatalog?->description;"""
assert s.count(old) == 1, ('desc', s.count(old))
new = """  // MARKER-ITEM-SOURCING — BTI ships \\n in group_text as LITERAL characters,
  // so the specs card rendered "\\n - Redesigned Trail…". Repaired at display;
  // the durable fix is an unescape transform in the field map.
  $catDesc   = $item->distributorCatalog?->description;
  if ($catDesc) {
      $catDesc = str_replace(['\\\\n', '\\\\r'], "\\n", $catDesc);
  }"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('description unescape ok')
IPS_2_EOF

echo
echo "item-page-sourcing applied."
