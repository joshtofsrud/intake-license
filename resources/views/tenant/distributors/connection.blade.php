@extends('layouts.tenant.app')
@php $pageTitle = 'Distributors'; @endphp

{{-- MARKER-PATCH-HLC7A --}}

@push('styles')
<style>
.dc-card{background:var(--ia-surface);border:.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:22px;margin-bottom:18px}
.dc-h{font-size:15px;font-weight:600;margin:0 0 4px}
.dc-sub{font-size:12.5px;color:var(--ia-text-dim);margin-bottom:16px;line-height:1.5}
.dc-row{display:flex;gap:16px;flex-wrap:wrap}
.dc-field{flex:1;min-width:200px;margin-bottom:14px}
.dc-field label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;margin-bottom:6px}
.dc-input{width:100%;background:var(--ia-input-bg);border:1px solid var(--ia-border);border-radius:var(--ia-r-md);padding:9px 11px;color:var(--ia-text);font-size:13px;font-family:var(--ia-mono)}
.dc-input:focus{outline:none;border-color:var(--ia-accent)}
.dc-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 15px;border-radius:var(--ia-r-md);font-size:13px;font-weight:600;cursor:pointer;border:1px solid var(--ia-border-strong);background:var(--ia-surface-2);color:var(--ia-text)}
.dc-btn.primary{background:var(--ia-accent);color:var(--ia-accent-text);border-color:var(--ia-accent)}
.dc-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:16px}
.dc-stat{background:var(--ia-surface-2);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:13px 14px}
.dc-stat .k{font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600}
.dc-stat .v{font-size:20px;font-weight:700;font-family:var(--ia-mono);margin-top:3px}
.dc-banner{padding:11px 15px;border-radius:var(--ia-r-md);font-size:13px;margin-bottom:16px;border:1px solid}
.dc-ok{background:rgba(99,153,34,.15);border-color:rgba(99,153,34,.4);color:#cfe6ab}
.dc-err{background:rgba(226,75,74,.12);border-color:rgba(226,75,74,.4);color:#f0a3a3}
.dc-note{font-size:12px;color:var(--ia-text-dim);background:var(--ia-accent-soft);border:1px solid rgba(190,242,100,.2);border-radius:var(--ia-r-md);padding:10px 13px;margin-bottom:16px;line-height:1.5}
.dc-unlock{display:flex;gap:9px;align-items:flex-start;margin-bottom:9px;font-size:12.5px;color:var(--ia-text-muted)}
.dc-unlock b{color:var(--ia-text)}
.dc-dim{color:var(--ia-text-dim)}
</style>
@endpush

@section('content')
<div style="max-width:880px">
  <h1 style="font-size:20px;font-weight:600;margin-bottom:14px">HLC Catalog</h1>
  @include('layouts.tenant._inventory-tabs')
  <p class="dc-sub">Connect your own HLC account to unlock your cost and live availability.</p>

  <div class="dc-note">Browsing and importing the catalog works without a key. Your <b>own</b> HLC key unlocks <b>your cost</b> and <b>live availability</b> — per-account, never shared between shops.</div>

  <div class="dc-card">
    <h2 class="dc-h">Your HLC account</h2>
    <p class="dc-sub">Stored encrypted. Used only for your shop's cost &amp; availability.</p>
    <form method="POST" action="{{ route('tenant.distributors.connection.key') }}">
      @csrf
      <div class="dc-row">
        <div class="dc-field"><label>Your API Key</label>
          <input class="dc-input" type="text" name="api_key" placeholder="{{ $hasKey ? $maskedKey : 'paste your HLC catalog key' }}" autocomplete="off"></div>
        <div class="dc-field" style="max-width:180px"><label>Account #</label>
          <input class="dc-input" type="text" name="account_number" value="{{ $accountNo }}" placeholder="optional"></div>
      </div>
      <div style="display:flex;gap:10px;margin-top:4px">
        <button class="dc-btn primary" type="submit">Save key</button>
        <button class="dc-btn" type="submit" formaction="{{ route('tenant.distributors.connection.test') }}">Test connection</button>
      </div>
    </form>
  </div>

  <div class="dc-card">
    {{-- MARKER-PATCH-559 — sync status + manual runs moved to Catalog attention,
         the surface staff actually watch. Connection is credentials only. --}}
    <div style="font-size:12.5px;color:var(--ia-text-muted);padding:6px 0 2px">
      Looking for sync status or a manual refresh? That lives on
      <a href="{{ route('tenant.distributors.attention') }}" style="color:var(--ia-accent)">Catalog attention</a> now.
    </div>
  </div>

  <div class="dc-card">
    <h2 class="dc-h">What your key unlocks</h2>
    <div class="dc-unlock"><span style="color:var(--ia-accent)">✓</span><div><b>Your dealer cost</b> — per-account Base pricing on every linked item.</div></div>
    <div class="dc-unlock"><span style="color:var(--ia-accent)">✓</span><div><b>Live availability</b> — per-warehouse stock on the item.</div></div>
    <div class="dc-unlock"><span style="color:var(--ia-accent)">✓</span><div><b>Pricing attention</b> — vanished cost/MAP/MSRP flags on items you stock.</div></div>
    <div class="dc-unlock"><span class="dc-dim">○</span><div class="dc-dim">Without a key: catalog, MAP and MSRP are visible, but cost, availability and flags stay hidden.</div></div>
  </div>
</div>
@endsection
