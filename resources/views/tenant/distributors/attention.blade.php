@extends('layouts.tenant.app')
@php $pageTitle = 'Catalog Attention'; @endphp

{{-- MARKER-PATCH-HLC7C --}}

@push('styles')
<style>
.at-card{background:var(--ia-surface);border:.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:20px;margin-bottom:18px}
.at-chips{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}
.at-chip{background:var(--ia-surface-2);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:11px 16px;min-width:120px}
.at-chip .v{font-size:22px;font-weight:700;font-family:var(--ia-mono)}
.at-chip .k{font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;margin-top:2px}
.at-tbl{width:100%;border-collapse:collapse;font-size:13px}
.at-tbl th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;padding:8px 10px;border-bottom:1px solid var(--ia-border)}
.at-tbl td{padding:11px 10px;border-bottom:.5px solid var(--ia-border);vertical-align:middle}
.at-mono{font-family:var(--ia-mono)}
.at-badge{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px}
.at-b-map{background:rgba(226,75,74,.16);color:#f0a3a3}
.at-b-msrp{background:rgba(239,159,39,.16);color:#f0c78a}
.at-b-van{background:rgba(120,140,170,.16);color:#aebbcf}
.at-b-title{background:rgba(190,242,100,.15);color:#cde98a}
.at-filter{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.at-sel{padding:7px 10px;border-radius:var(--ia-r-md);font-size:13px;border:1px solid var(--ia-border-strong);background:var(--ia-surface-2);color:var(--ia-text)}
.at-seg{display:inline-flex;border:1px solid var(--ia-border-strong);border-radius:var(--ia-r-md);overflow:hidden;margin-bottom:14px}
.at-segbtn{padding:8px 16px;font-size:13px;font-weight:600;color:var(--ia-text-dim);text-decoration:none;border-right:1px solid var(--ia-border-strong)}
.at-segbtn:last-child{border-right:0}
.at-segbtn.active{background:var(--ia-accent);color:var(--ia-accent-text)}
.at-bar{position:sticky;bottom:0;background:var(--ia-surface);border-top:1px solid var(--ia-border);padding:14px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.at-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:var(--ia-r-md);font-size:13px;font-weight:600;cursor:pointer;border:1px solid var(--ia-border-strong);background:var(--ia-surface-2);color:var(--ia-text)}
.at-btn.primary{background:var(--ia-accent);color:var(--ia-accent-text);border-color:var(--ia-accent)}
.at-banner{padding:11px 15px;border-radius:var(--ia-r-md);font-size:13px;margin-bottom:16px;border:1px solid}
.at-ok{background:rgba(99,153,34,.15);border-color:rgba(99,153,34,.4);color:#cfe6ab}
.at-empty{text-align:center;padding:48px 20px;color:var(--ia-text-dim)}
.at-empty .big{font-size:34px;margin-bottom:8px}
.at-dim{color:var(--ia-text-dim)}
.at-toggle{font-size:12px;color:var(--ia-text-dim)}
.at-toggle a{color:var(--ia-accent);text-decoration:none}
</style>
@endpush

@section('content')
@php
  $fmt = fn($c) => $c !== null ? '$' . number_format($c/100, 2) : '—';
  $badge = function($r){
    return match($r){
      'below_map'     => ['at-b-map','Below MAP'],
      'off_msrp'      => ['at-b-msrp','Off MSRP'],
      'title_changed' => ['at-b-title','Title changed'],
      default         => ['at-b-van', str_replace('_',' ', $r)],
    };
  };
@endphp
<div style="max-width:980px">
  <h1 style="font-size:20px;font-weight:600;margin-bottom:14px">HLC Catalog</h1>
  @include('layouts.tenant._inventory-tabs')

  {{-- MARKER-PATCH-555 — manual sync controls + last-run visibility --}}
  <div style="display:flex;align-items:center;gap:12px;margin:2px 0 14px;flex-wrap:wrap">
    <div style="font-size:12.5px;color:var(--ia-text-muted)">
      @if(!empty($lastSyncRun))
        Last sync: {{ tlocal($lastSyncRun->started_at, 'M j, g:i a') }}
        · {{ $lastSyncRun->dry_run ? 'dry run' : ($lastSyncRun->trigger === 'schedule' ? 'nightly' : 'manual') }}
        @if($lastSyncRun->finished_at)
          @php $st = json_decode($lastSyncRun->stats ?? '[]', true) ?: []; @endphp
          @if($lastSyncRun->error)
            · <span style="color:#f0a3a3">failed: {{ \Illuminate\Support\Str::limit($lastSyncRun->error, 80) }}</span>
          @else
            · {{ collect($st)->except('errors')->map(fn($v,$k) => is_numeric($v) ? "$k $v" : null)->filter()->take(4)->implode(' · ') ?: 'no changes' }}
          @endif
        @else
          · <span style="color:var(--ia-accent)">running…</span>
        @endif
      @else
        Tenant pricing sync has never run.
      @endif
    </div>
    <div style="margin-left:auto;display:flex;gap:8px">
      <form method="POST" action="{{ route('tenant.distributors.attention.sync') }}">@csrf
        <input type="hidden" name="mode" value="dry">
        <button class="ia-btn ia-btn--ghost ia-btn--sm">Dry run</button>
      </form>
      <form method="POST" action="{{ route('tenant.distributors.attention.sync') }}">@csrf
        <button class="ia-btn ia-btn--primary ia-btn--sm">Sync now</button>
      </form>
    </div>
  </div>

  <div class="at-chips">
    <div class="at-chip"><div class="v">{{ $counts['total'] }}</div><div class="k">Open</div></div>
    <div class="at-chip"><div class="v" style="color:#cde98a">{{ $counts['title'] ?? 0 }}</div><div class="k">Titles</div></div>
    <div class="at-chip"><div class="v" style="color:#f0a3a3">{{ $counts['below_map'] }}</div><div class="k">Below MAP</div></div>
    <div class="at-chip"><div class="v" style="color:#f0c78a">{{ $counts['off_msrp'] }}</div><div class="k">Off MSRP</div></div>
    <div class="at-chip"><div class="v" style="color:#aebbcf">{{ $counts['vanished'] }}</div><div class="k">Vanished</div></div>
  </div>

  <form method="GET" action="{{ route('tenant.distributors.attention') }}" class="at-filter">
    @if($stock !== 'all')<input type="hidden" name="stock" value="{{ $stock }}">@endif
    <select name="brand" class="at-sel">
      <option value="">All brands</option>
      @foreach(($brandOptions ?? []) as $b)<option value="{{ $b }}" @selected(($filters['brand'] ?? null)===$b)>{{ $b }}</option>@endforeach
    </select>
    <select name="category" class="at-sel">
      <option value="">All categories</option>
      @foreach(($categoryOptions ?? []) as $c)<option value="{{ $c }}" @selected(($filters['category'] ?? null)===$c)>{{ $c }}</option>@endforeach
    </select>
    <select name="reason" class="at-sel">
      <option value="">All reasons</option>
      <option value="title_changed" @selected(($filters['reason'] ?? null)==='title_changed')>Title changed</option>
      <option value="below_map" @selected(($filters['reason'] ?? null)==='below_map')>Below MAP</option>
      <option value="off_msrp" @selected(($filters['reason'] ?? null)==='off_msrp')>Off MSRP</option>
    </select>
    <button class="at-btn primary" type="submit">Filter</button>
    @if(($filters['brand'] ?? null) || ($filters['category'] ?? null) || ($filters['reason'] ?? null))
      <a class="at-btn" href="{{ route('tenant.distributors.attention', $stock !== 'all' ? ['stock' => $stock] : []) }}">Clear</a>
    @endif
  </form>

  @php
    $segLink = fn ($s) => route('tenant.distributors.attention', array_filter([
        'stock'    => $s === 'all' ? null : $s,
        'brand'    => $filters['brand'] ?? null,
        'category' => $filters['category'] ?? null,
        'reason'   => $filters['reason'] ?? null,
    ]));
  @endphp
  <div class="at-seg">
    <a class="at-segbtn {{ $stock === 'all' ? 'active' : '' }}" href="{{ $segLink('all') }}">All ({{ $counts['total'] }})</a>
    <a class="at-segbtn {{ $stock === 'in' ? 'active' : '' }}" href="{{ $segLink('in') }}">In stock ({{ $counts['in'] ?? 0 }})</a>
    <a class="at-segbtn {{ $stock === 'out' ? 'active' : '' }}" href="{{ $segLink('out') }}">Out of stock ({{ $counts['out'] ?? 0 }})</a>
  </div>

  @if($flags->isEmpty())
    <div class="at-card"><div class="at-empty"><div class="big">✓</div>All clear — no pricing attention needed right now.</div></div>
  @else
    <form method="POST" action="{{ route('tenant.distributors.attention.resolve') }}">
      @csrf
      <input type="hidden" name="action" id="at-action" value="">
      <input type="hidden" name="f_brand" value="{{ $filters['brand'] ?? '' }}">
      <input type="hidden" name="f_category" value="{{ $filters['category'] ?? '' }}">
      <input type="hidden" name="f_reason" value="{{ $filters['reason'] ?? '' }}">
      <input type="hidden" name="f_stock" value="{{ $stock }}">
      <script>function setAct(a){document.getElementById('at-action').value=a;}</script>
      <div class="at-card" style="padding:6px 14px">
        <table class="at-tbl">
          <thead><tr>
            <th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.at-cb').forEach(c=>c.checked=this.checked)"></th>
            <th>Item</th><th>Reason</th><th>Your price</th><th>MAP</th><th>MSRP</th><th>Stock</th>
          </tr></thead>
          <tbody>
          @foreach($flags as $f)
            @php [$bc,$bl] = $badge($f->reason); $item = $f->item; $isTitle = $f->reason === 'title_changed'; @endphp
            <tr>
              <td><input class="at-cb" type="checkbox" name="flag_ids[]" value="{{ $f->id }}"></td>
              @if($isTitle)
                <td><div style="font-weight:600">{{ $item->name ?? '—' }}</div><div class="at-dim at-mono" style="font-size:11px">{{ $item->sku ?? '' }}</div></td>
                <td><span class="at-badge {{ $bc }}">{{ $bl }}</span></td>
                <td colspan="4" class="at-dim">→ <span style="color:var(--ia-text);font-weight:600">{{ $f->detail['new'] ?? ($item->distributorCatalog->display_name ?? '—') }}</span></td>
                <td class="at-mono">{{ $item->computed_stock_count ?? 0 }}</td>
              @else
                <td><div style="font-weight:600">{{ $item->name ?? '—' }}</div><div class="at-dim at-mono" style="font-size:11px">{{ $item->sku ?? '' }}</div></td>
                <td><span class="at-badge {{ $bc }}">{{ $bl }}</span></td>
                <td class="at-mono">{{ $fmt($item->shop_sell_price_cents ?? null) }}</td>
                <td class="at-mono">{{ $fmt($item->catalog_map_cents ?? ($f->detail['prev_map_cents'] ?? null)) }}</td>
                <td class="at-mono">{{ $fmt($item->catalog_msrp_cents ?? ($f->detail['prev_msrp_cents'] ?? null)) }}</td>
                <td class="at-mono">{{ $item->computed_stock_count ?? 0 }}</td>
              @endif
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>

      <div class="at-bar">
        <span class="at-dim" style="font-size:12px">With selected:</span>
        <button class="at-btn primary" type="submit" onclick="setAct('adopt_title')">Adopt new title</button>
        <button class="at-btn" type="submit" onclick="setAct('keep_title')">Keep mine</button>
        <span class="at-dim" style="opacity:.4">|</span>
        <button class="at-btn primary" type="submit" onclick="setAct('raise_map')">Raise to MAP</button>
        <button class="at-btn" type="submit" onclick="setAct('match_msrp')">Match MSRP</button>
        <button class="at-btn" type="submit" onclick="setAct('acknowledge')">Dismiss</button>
        <label class="at-dim" style="font-size:12px;margin-left:auto;cursor:pointer">
          <input type="checkbox" name="select_all" value="1"> apply to all {{ $flags->count() }} matching the filter
        </label>
      </div>
    </form>
  @endif
</div>
@endsection
