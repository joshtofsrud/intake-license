@extends('layouts.tenant.app')
@php $pageTitle = 'Pricing Attention'; @endphp

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
      'below_map' => ['at-b-map','Below MAP'],
      'off_msrp'  => ['at-b-msrp','Off MSRP'],
      default     => ['at-b-van', str_replace('_',' ', $r)],
    };
  };
@endphp
<div style="max-width:980px">
  <h1 style="font-size:20px;font-weight:600;margin-bottom:14px">HLC Catalog</h1>
  @include('tenant.distributors._tabs')

  @if(session('success'))<div class="at-banner at-ok">{{ session('success') }}</div>@endif

  <div class="at-chips">
    <div class="at-chip"><div class="v">{{ $counts['total'] }}</div><div class="k">Open</div></div>
    <div class="at-chip"><div class="v" style="color:#f0a3a3">{{ $counts['below_map'] }}</div><div class="k">Below MAP</div></div>
    <div class="at-chip"><div class="v" style="color:#f0c78a">{{ $counts['off_msrp'] }}</div><div class="k">Off MSRP</div></div>
    <div class="at-chip"><div class="v" style="color:#aebbcf">{{ $counts['vanished'] }}</div><div class="k">Vanished</div></div>
  </div>

  <div class="at-toggle" style="margin-bottom:12px">
    @if($inStockOnly)
      Showing in-stock items only · <a href="{{ route('tenant.distributors.attention', ['all' => 1]) }}">show all</a>
    @else
      Showing all flagged items · <a href="{{ route('tenant.distributors.attention') }}">in-stock only</a>
    @endif
  </div>

  @if($flags->isEmpty())
    <div class="at-card"><div class="at-empty"><div class="big">✓</div>All clear — no pricing attention needed right now.</div></div>
  @else
    <form method="POST" action="{{ route('tenant.distributors.attention.resolve') }}">
      @csrf
      <input type="hidden" name="action" id="at-action" value="">
      <div class="at-card" style="padding:6px 14px">
        <table class="at-tbl">
          <thead><tr>
            <th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.at-cb').forEach(c=>c.checked=this.checked)"></th>
            <th>Item</th><th>Reason</th><th>Your price</th><th>MAP</th><th>MSRP</th><th>Stock</th>
          </tr></thead>
          <tbody>
          @foreach($flags as $f)
            @php [$bc,$bl] = $badge($f->reason); $item = $f->item; @endphp
            <tr>
              <td><input class="at-cb" type="checkbox" name="flag_ids[]" value="{{ $f->id }}"></td>
              <td><div style="font-weight:600">{{ $item->name ?? '—' }}</div><div class="at-dim at-mono" style="font-size:11px">{{ $item->sku ?? '' }}</div></td>
              <td><span class="at-badge {{ $bc }}">{{ $bl }}</span></td>
              <td class="at-mono">{{ $fmt($item->shop_sell_price_cents ?? null) }}</td>
              <td class="at-mono">{{ $fmt($item->catalog_map_cents ?? ($f->detail['prev_map_cents'] ?? null)) }}</td>
              <td class="at-mono">{{ $fmt($item->catalog_msrp_cents ?? ($f->detail['prev_msrp_cents'] ?? null)) }}</td>
              <td class="at-mono">{{ $item->computed_stock_count ?? 0 }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>

      <div class="at-bar">
        <span class="at-dim" style="font-size:12px">With selected:</span>
        <button class="at-btn primary" type="submit" onclick="document.getElementById('at-action').value='raise_map'">Raise to MAP</button>
        <button class="at-btn" type="submit" onclick="document.getElementById('at-action').value='match_msrp'">Match MSRP</button>
        <button class="at-btn" type="submit" onclick="document.getElementById('at-action').value='acknowledge'">Acknowledge</button>
      </div>
    </form>
  @endif
</div>
@endsection
