@extends('layouts.tenant.app')
@php $pageTitle = 'Storefront'; @endphp

@section('content')
{{-- MARKER-PATCH-569 — storefront settings: master switch, delivery,
     install offer, bulk publish. --}}
<style>
  .sf-card{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:12px;padding:18px 20px;margin-bottom:14px;max-width:640px}
  .sf-card h3{font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--ia-text-muted);margin-bottom:12px;font-weight:600}
  .sf-row{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:10px 0;border-bottom:0.5px solid var(--ia-border)}
  .sf-row:last-child{border-bottom:0}
  .sf-row .t{font-size:13.5px;font-weight:600}
  .sf-row .d{font-size:12px;color:var(--ia-text-muted);margin-top:2px;max-width:46ch}
  .sf-tgl{position:relative;width:40px;height:22px;flex:none}
  .sf-tgl input{opacity:0;width:0;height:0}
  .sf-tgl span{position:absolute;inset:0;background:rgba(255,255,255,.12);border-radius:99px;transition:.15s;cursor:pointer}
  .sf-tgl span:before{content:'';position:absolute;width:16px;height:16px;border-radius:50%;background:#fff;top:3px;left:3px;transition:.15s}
  .sf-tgl input:checked + span{background:var(--ia-accent)}
  .sf-tgl input:checked + span:before{transform:translateX(18px)}
  .sf-fee{display:flex;align-items:center;gap:6px}
  .sf-fee input{width:90px;font:inherit;font-size:13px;padding:7px 10px;background:var(--ia-bg);border:0.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text)}
  .sf-stat{font-size:13px;color:var(--ia-text-muted);margin-bottom:12px}
  .sf-stat b{color:var(--ia-text)}
  .sf-bulk{display:flex;gap:8px;flex-wrap:wrap}
</style>

<div class="ia-page-head">
  <div class="ia-page-head-left"><h1 class="ia-page-title">Storefront</h1></div>
  <a class="ia-btn ia-btn--ghost ia-btn--sm" href="/shop" target="_blank">View your store ↗</a>
</div>

@if(session('success'))
  <div class="ia-banner ia-banner--success" style="margin-bottom:14px;max-width:640px">{{ session('success') }}</div>
@endif

<form method="POST" action="{{ route('tenant.storefront.settings.update') }}">
  @csrf
  <div class="sf-card">
    <h3>Store</h3>
    <div class="sf-row">
      <div><div class="t">Store is open</div>
        <div class="d">Turn off to hide /shop entirely — carts and checkout included. Nothing is deleted.</div></div>
      <label class="sf-tgl"><input type="checkbox" name="enabled" value="1" @checked($cfg['enabled'])><span></span></label>
    </div>
    <div class="sf-row">
      <div><div class="t">Offer installation at checkout</div>
        <div class="d">Adds an "I'd like this installed" option — requests show on the order for you to schedule.</div></div>
      <label class="sf-tgl"><input type="checkbox" name="install_offer" value="1" @checked($cfg['install_offer'])><span></span></label>
    </div>
  </div>

  <div class="sf-card">
    <h3>Local delivery</h3>
    <div class="sf-row">
      <div><div class="t">Offer local delivery</div>
        <div class="d">Customers can choose delivery at checkout and leave an address.</div></div>
      <label class="sf-tgl"><input type="checkbox" name="local_delivery" value="1" @checked($cfg['local_delivery'])><span></span></label>
    </div>
    <div class="sf-row">
      <div><div class="t">Delivery fee</div>
        <div class="d">Added to delivery orders. Zero means free delivery.</div></div>
      <div class="sf-fee">$ <input type="number" step="0.01" min="0" name="delivery_fee" value="{{ $cfg['delivery_fee'] }}"></div>
    </div>
  </div>

  <button class="ia-btn ia-btn--primary">Save settings</button>
</form>

<div class="sf-card" style="margin-top:22px">
  <h3>What's published</h3>
  <div class="sf-stat"><b>{{ number_format($counts['online']) }}</b> of {{ number_format($counts['active']) }} active items are visible in the store
    ({{ number_format($counts['linkable']) }} are catalog-linked).</div>
  <div class="sf-bulk">
    <form method="POST" action="{{ route('tenant.storefront.settings.bulk') }}">@csrf
      <input type="hidden" name="op" value="publish_catalog">
      <button class="ia-btn ia-btn--primary ia-btn--sm">Publish all catalog-linked</button>
    </form>
    <form method="POST" action="{{ route('tenant.storefront.settings.bulk') }}">@csrf
      <input type="hidden" name="op" value="publish_all">
      <button class="ia-btn ia-btn--ghost ia-btn--sm">Publish everything</button>
    </form>
    <form method="POST" action="{{ route('tenant.storefront.settings.bulk') }}" onsubmit="return confirm('Unpublish every item? The store will be empty until you publish again.')">@csrf
      <input type="hidden" name="op" value="unpublish_all">
      <button class="ia-btn ia-btn--ghost ia-btn--sm">Unpublish everything</button>
    </form>
  </div>
  <div class="sf-stat" style="margin:12px 0 0;font-size:12px">Per-item control lives on each inventory item page.</div>
</div>
@endsection
