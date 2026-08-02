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
