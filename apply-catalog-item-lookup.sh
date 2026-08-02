#!/usr/bin/env bash
# apply-catalog-item-lookup.sh
# MARKER-CATALOG-LOOKUP — master admin → Distribution → Item lookup.
#
# Answers "what does the distributor actually say about this item" without a
# tinker session. Search by UPC, EAN, part number, variant number, product
# key or name; get every matching catalog row across distributors.
#
# The reason this is worth a page rather than a query: platform_distributor_catalogs
# keeps `source_raw`, the untouched feed row, alongside the mapped canonical
# fields. Nearly every catalog bug this month lived in the gap between those
# two — BTI cost was null because prices() renamed `your_price` before the
# field map read it, and the map itself was correct. Seeing the raw row and
# the resolved value side by side is what makes that visible in seconds.
#
# Three panels once a row is picked:
#   * canonical fields, grouped, with blanks shown rather than hidden — a
#     null cost is exactly the thing you came to look at
#   * the raw feed row as it arrived
#   * which tenants carry it, via tenant_inventory_item_vendors, so a support
#     question about one shop's price has an answer without impersonating them
#
# Read-only by design. Nothing here writes.
set -e

cat <<'PHPEOF' > app/Filament/Pages/CatalogItemLookup.php
<?php

// MARKER-CATALOG-LOOKUP

namespace App\Filament\Pages;

use App\Models\PlatformDistributorCatalog;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Look up one distributor item and see everything recorded about it.
 *
 * Deliberately searches several identifier columns at once rather than
 * making you pick which kind of number you are holding — in practice you
 * have "a number off a box" and do not know whether it is a UPC, an EAN or
 * the distributor's own part number.
 */
class CatalogItemLookup extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-magnifying-glass';
    protected static ?string $navigationLabel = 'Item lookup';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 21;
    protected static ?string $title = 'Catalog item lookup';

    protected static string $view = 'filament.pages.catalog-item-lookup';

    public string $q        = '';
    public string $code     = '';
    public ?string $selected = null;

    public function updatedQ(): void    { $this->selected = null; }
    public function updatedCode(): void { $this->selected = null; }

    public function select(string $id): void
    {
        $this->selected = $id;
    }

    /** @return \Illuminate\Support\Collection */
    public function getResultsProperty()
    {
        $q = trim($this->q);
        if (mb_strlen($q) < 3) {
            return collect();
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        return PlatformDistributorCatalog::query()
            ->when($this->code !== '', fn ($b) => $b->where('distributor_code', $this->code))
            ->where(function ($b) use ($q, $like) {
                // Exact on the identifier columns first — a scanned number is
                // exact, and a LIKE on it would drag in every longer barcode
                // that happens to contain it.
                $b->where('upc', $q)
                  ->orWhere('ean', $q)
                  ->orWhere('manufacturer_sku', $q)
                  ->orWhere('distributor_product_no', $q)
                  ->orWhere('distributor_variant_no', $q)
                  ->orWhere('product_key', $q)
                  ->orWhere('name', 'like', $like)
                  ->orWhere('manufacturer_sku', 'like', $like);
            })
            ->orderBy('distributor_code')
            ->orderBy('name')
            ->limit(50)
            ->get();
    }

    public function getRowProperty(): ?PlatformDistributorCatalog
    {
        return $this->selected
            ? PlatformDistributorCatalog::find($this->selected)
            : null;
    }

    /** Which shops carry this exact catalog row, and at what cost. */
    public function getCarriersProperty(): array
    {
        if (! $this->selected) {
            return [];
        }

        return DB::table('tenant_inventory_item_vendors as iv')
            ->join('tenant_inventory_items as i', 'i.id', '=', 'iv.inventory_item_id')
            ->join('tenants as t', 't.id', '=', 'i.tenant_id')
            ->leftJoin('tenant_vendors as v', 'v.id', '=', 'iv.vendor_id')
            ->where('iv.distributor_catalog_id', $this->selected)
            ->select(
                't.subdomain', 't.name as tenant_name', 'i.name as item_name',
                'i.sku', 'v.name as vendor_name', 'iv.live_cost_cents',
                'iv.unit_cost_cents', 'iv.live_avail', 'iv.vendor_sku'
            )
            ->orderBy('t.subdomain')
            ->limit(100)
            ->get()
            ->all();
    }

    /** Distributor codes present, for the filter. */
    public function getCodesProperty(): array
    {
        return PlatformDistributorCatalog::query()
            ->select('distributor_code')->distinct()
            ->orderBy('distributor_code')->pluck('distributor_code')->all();
    }

    /**
     * Canonical fields, grouped. Blanks are rendered, not hidden — a null
     * cost or a missing UPC is usually the thing being investigated.
     */
    public function fieldGroups(PlatformDistributorCatalog $r): array
    {
        return [
            'Identity' => [
                'distributor_code' => $r->distributor_code,
                'distributor_name' => $r->distributor_name,
                'product_key'      => $r->product_key,
                'distributor_product_no' => $r->distributor_product_no,
                'distributor_variant_no' => $r->distributor_variant_no,
                'manufacturer_sku' => $r->manufacturer_sku,
                'upc'              => $r->upc,
                'ean'              => $r->ean,
            ],
            'Naming' => [
                'name'             => $r->name,
                'display_name'     => $r->display_name,
                'display_subtitle' => $r->display_subtitle,
                'manufacturer'     => $r->manufacturer,
                'brand_id'         => $r->brand_id,
                'description'      => $r->description,
            ],
            'Pricing' => [
                'cost_cents'      => $r->cost_cents,
                'msrp_cents'      => $r->msrp_cents,
                'map_cents'       => $r->map_cents,
                'prev_cost_cents' => $r->prev_cost_cents,
                'alt_prices'      => $r->alt_prices,
                'taxable'         => $r->taxable,
            ],
            'Classification' => [
                'category'      => $r->category,
                'category_id'   => $r->category_id,
                'category_path' => $r->category_path,
                'item_group'    => $r->item_group,
                'size_id'       => $r->size_id,
                'color_id'      => $r->color_id,
                'config'        => $r->config,
            ],
            'Physical & shipping' => [
                'uom'            => $r->uom,
                'case_quantity'  => $r->case_quantity,
                'weight'         => $r->weight,
                'dimensions'     => $r->dimensions,
                'ground_only'    => $r->ground_only,
                'hazmat_type'    => $r->hazmat_type,
                'freight_class'  => $r->freight_class,
                'dropship_fulfillable' => $r->dropship_fulfillable,
            ],
            'Status' => [
                'canonical_status'   => $r->canonical_status,
                'source_status_id'   => $r->source_status_id,
                'source_status_label'=> $r->source_status_label,
                'is_sellable'        => $r->is_sellable,
                'is_active'          => $r->is_active,
                'last_synced_at'     => $r->last_synced_at,
                'source_modified_at' => $r->source_modified_at,
            ],
        ];
    }
}
PHPEOF
echo "created app/Filament/Pages/CatalogItemLookup.php"

mkdir -p resources/views/filament/pages
cat <<'BLADEEOF' > resources/views/filament/pages/catalog-item-lookup.blade.php
{{-- MARKER-CATALOG-LOOKUP --}}
<x-filament-panels::page>

  <x-filament::section>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <div style="flex:1;min-width:280px">
        <label style="display:block;font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;opacity:.6;margin-bottom:5px">
          UPC, EAN, part number, variant number, product key or name
        </label>
        <input type="text" wire:model.live.debounce.400ms="q" autofocus
               placeholder="e.g. 4717784012292 or TB29478000 or Holy Roller"
               style="width:100%;padding:9px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);color:inherit;font-size:13.5px">
      </div>
      <div style="min-width:150px">
        <label style="display:block;font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;opacity:.6;margin-bottom:5px">Distributor</label>
        <select wire:model.live="code"
                style="width:100%;padding:9px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);color:inherit;font-size:13.5px">
          <option value="">All</option>
          @foreach($this->codes as $c)
            <option value="{{ $c }}">{{ $c }}</option>
          @endforeach
        </select>
      </div>
    </div>

    @if(strlen(trim($q)) > 0 && strlen(trim($q)) < 3)
      <p style="font-size:12px;opacity:.6;margin-top:10px">Keep typing — three characters minimum.</p>
    @endif
  </x-filament::section>

  @php $results = $this->results; @endphp

  @if($results->count())
    <x-filament::section :heading="$results->count() . ' match' . ($results->count() === 1 ? '' : 'es')">
      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <thead>
            <tr style="text-align:left;opacity:.6;font-size:10.5px;letter-spacing:.06em;text-transform:uppercase">
              <th style="padding:8px 10px">Dist</th>
              <th style="padding:8px 10px">Name</th>
              <th style="padding:8px 10px">Part no.</th>
              <th style="padding:8px 10px">UPC</th>
              <th style="padding:8px 10px;text-align:right">Cost</th>
              <th style="padding:8px 10px"></th>
            </tr>
          </thead>
          <tbody>
            @foreach($results as $r)
              <tr style="border-top:1px solid rgba(255,255,255,.07);{{ $selected === $r->id ? 'background:rgba(217,164,65,.10)' : '' }}">
                <td style="padding:9px 10px;font-weight:600">{{ $r->distributor_code }}</td>
                <td style="padding:9px 10px">{{ \Illuminate\Support\Str::limit($r->name, 60) }}</td>
                <td style="padding:9px 10px;opacity:.75">{{ $r->manufacturer_sku ?: '—' }}</td>
                <td style="padding:9px 10px;opacity:.75;font-variant-numeric:tabular-nums">{{ $r->upc ?: ($r->ean ? $r->ean . ' (EAN)' : '—') }}</td>
                <td style="padding:9px 10px;text-align:right;font-variant-numeric:tabular-nums">
                  {{ $r->cost_cents !== null ? '$' . number_format($r->cost_cents / 100, 2) : '—' }}
                </td>
                <td style="padding:9px 10px;text-align:right">
                  <x-filament::button size="xs" color="gray" wire:click="select('{{ $r->id }}')">
                    {{ $selected === $r->id ? 'Showing' : 'Open' }}
                  </x-filament::button>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </x-filament::section>
  @elseif(strlen(trim($q)) >= 3)
    <x-filament::section>
      <p style="font-size:13px;opacity:.7">
        Nothing matches “{{ $q }}”@if($code) in {{ $code }}@endif.
        Identifier columns are matched exactly, so a partial UPC won't hit —
        names are matched loosely.
      </p>
    </x-filament::section>
  @endif

  @if($this->row)
    @php $r = $this->row; @endphp

    <x-filament::section :heading="$r->distributor_code . ' · ' . $r->name">
      @foreach($this->fieldGroups($r) as $group => $fields)
        <div style="margin-bottom:18px">
          <div style="font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;opacity:.5;margin-bottom:8px">{{ $group }}</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.07);border-radius:8px;overflow:hidden">
            @foreach($fields as $k => $v)
              <div style="background:rgba(0,0,0,.25);padding:8px 12px;display:flex;gap:10px;font-size:12.5px">
                <span style="opacity:.55;min-width:150px;flex:none">{{ $k }}</span>
                <span style="word-break:break-word">
                  @if(is_null($v) || $v === '')
                    <em style="opacity:.35">null</em>
                  @elseif(is_bool($v))
                    {{ $v ? 'true' : 'false' }}
                  @elseif(is_array($v))
                    <code style="font-size:11.5px">{{ \Illuminate\Support\Str::limit(json_encode($v), 120) }}</code>
                  @else
                    {{ \Illuminate\Support\Str::limit((string) $v, 160) }}
                  @endif
                </span>
              </div>
            @endforeach
          </div>
        </div>
      @endforeach
    </x-filament::section>

    <x-filament::section heading="Raw feed row" collapsible collapsed>
      <p style="font-size:12px;opacity:.6;margin-bottom:10px">
        Exactly as the distributor sent it, before the field map. When a mapped
        value looks wrong, the answer is usually the difference between this and
        the fields above.
      </p>
      <pre style="font-size:11.5px;line-height:1.6;overflow:auto;max-height:420px;padding:12px;border-radius:8px;background:rgba(0,0,0,.35)">{{ $r->source_raw ? json_encode(is_string($r->source_raw) ? json_decode($r->source_raw, true) : $r->source_raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'No raw row stored for this item.' }}</pre>
    </x-filament::section>

    <x-filament::section :heading="'Carried by ' . count($this->carriers) . ' tenant item' . (count($this->carriers) === 1 ? '' : 's')">
      @if(count($this->carriers))
        <div style="overflow:auto">
          <table style="width:100%;border-collapse:collapse;font-size:13px">
            <thead>
              <tr style="text-align:left;opacity:.6;font-size:10.5px;letter-spacing:.06em;text-transform:uppercase">
                <th style="padding:8px 10px">Tenant</th>
                <th style="padding:8px 10px">Item</th>
                <th style="padding:8px 10px">Vendor</th>
                <th style="padding:8px 10px">Vendor SKU</th>
                <th style="padding:8px 10px;text-align:right">Live cost</th>
                <th style="padding:8px 10px;text-align:right">Avail</th>
              </tr>
            </thead>
            <tbody>
              @foreach($this->carriers as $c)
                <tr style="border-top:1px solid rgba(255,255,255,.07)">
                  <td style="padding:9px 10px"><strong>{{ $c->subdomain }}</strong></td>
                  <td style="padding:9px 10px">{{ \Illuminate\Support\Str::limit($c->item_name, 40) }} <span style="opacity:.5">{{ $c->sku }}</span></td>
                  <td style="padding:9px 10px;opacity:.75">{{ $c->vendor_name ?: '—' }}</td>
                  <td style="padding:9px 10px;opacity:.75">{{ $c->vendor_sku ?: '—' }}</td>
                  <td style="padding:9px 10px;text-align:right;font-variant-numeric:tabular-nums">
                    {{ $c->live_cost_cents !== null ? '$' . number_format($c->live_cost_cents / 100, 2) : ($c->unit_cost_cents !== null ? '$' . number_format($c->unit_cost_cents / 100, 2) . ' (manual)' : '—') }}
                  </td>
                  <td style="padding:9px 10px;text-align:right;font-variant-numeric:tabular-nums">{{ $c->live_avail ?? '—' }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @else
        <p style="font-size:13px;opacity:.7">No tenant has imported this item.</p>
      @endif
    </x-filament::section>
  @endif

</x-filament-panels::page>
BLADEEOF
echo "created resources/views/filament/pages/catalog-item-lookup.blade.php"

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
f = 'resources/views/filament/pages/catalog-item-lookup.blade.php'
s = re.sub(r'\{\{--.*?--\}\}', '', io.open(f, encoding='utf-8').read(), flags=re.S)
print('glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|else|elseif|unless|php|endphp)\b', s)))
for a, b in [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp')]:
    o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
    print(' ', a, o, b, c, 'OK' if o == c else 'MISMATCH')
PY

echo "--- php balance ---"
python3 - <<'PY'
import io
s = io.open('app/Filament/Pages/CatalogItemLookup.php', encoding='utf-8').read()
i, n, d, par, brk = 0, len(s), 0, 0, 0
while i < n:
    c = s[i]
    if c == '#' or (c == '/' and i+1 < n and s[i+1] == '/'):
        while i < n and s[i] != '\n': i += 1
    elif c == '/' and i+1 < n and s[i+1] == '*':
        i += 2
        while i+1 < n and not (s[i] == '*' and s[i+1] == '/'): i += 1
        i += 2
    elif c in '"\'':
        q = c; i += 1
        while i < n and s[i] != q:
            if s[i] == '\\': i += 1
            i += 1
        i += 1
    else:
        if c == '{': d += 1
        elif c == '}': d -= 1
        elif c == '(': par += 1
        elif c == ')': par -= 1
        elif c == '[': brk += 1
        elif c == ']': brk -= 1
        i += 1
print('CatalogItemLookup braces', d, 'parens', par, 'brackets', brk)
PY

echo
echo "apply-catalog-item-lookup: OK"
