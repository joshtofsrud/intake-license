@extends('layouts.tenant.app')
@php
  $pageTitle  = 'Settings';
  $activeTab  = request('tab', 'general');
  $s          = $currentTenant->settings ?? [];
  $currencies = ['USD'=>'$','CAD'=>'CA$','GBP'=>'£','EUR'=>'€','AUD'=>'A$','NZD'=>'NZ$'];
@endphp

@push('styles')
<style>
.set-tabs{display:flex;gap:0;border-bottom:0.5px solid var(--ia-border);margin-bottom:28px}
.set-tab{padding:10px 20px;font-size:13px;color:var(--ia-text-muted);cursor:pointer;border-bottom:2px solid transparent;text-decoration:none;transition:all .12s}
.set-tab:hover{color:var(--ia-text)}
.set-tab.active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}
.set-section{max-width:640px}
.provider-card{border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:20px;margin-bottom:16px;transition:border-color .12s}
.provider-card.enabled{border-color:var(--ia-accent)}
.provider-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:0}
.provider-toggle{display:none}
.provider-fields{margin-top:16px;padding-top:16px;border-top:0.5px solid var(--ia-border);display:none}
.provider-card.enabled .provider-fields{display:block}
.prov-toggle-btn{width:38px;height:22px;background:var(--ia-border);border-radius:11px;position:relative;cursor:pointer;border:none;outline:none;transition:background .12s;flex-shrink:0}
.prov-toggle-btn.on{background:var(--ia-accent)}
.prov-toggle-btn::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:white;transition:transform .12s}
.prov-toggle-btn.on::after{transform:translateX(16px)}
.domain-badge{font-size:11px;padding:3px 10px;border-radius:20px;font-weight:500;margin-left:8px}
.domain-badge.basic{background:var(--ia-surface-2);color:var(--ia-text-muted)}
.domain-badge.branded{background:#EEEDFE;color:#534AB7}
.domain-badge.custom{background:#EAF3DE;color:#3B6D11}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Settings</h1>
    <p class="ia-page-subtitle">Configure your shop's operational preferences.</p>
  </div>
</div>

<div class="set-tabs">
  @foreach(['general'=>'General','booking'=>'Booking','payments'=>'Payments','domain'=>'Domain'] as $tab => $label)
    <a href="?tab={{ $tab }}" class="set-tab {{ $activeTab === $tab ? 'active' : '' }}">{{ $label }}</a>
  @endforeach
</div>

{{-- ============================================================ General ============================================================ --}}
@if($activeTab === 'general')
<form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section">
  @csrf @method('PATCH')
  <input type="hidden" name="tab" value="general">

  <div class="ia-card" style="margin-bottom:24px">
    <div class="ia-card-head"><span class="ia-card-title">Currency</span></div>
    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label">Currency code</label>
        <select name="currency" class="ia-input">
          @foreach($currencies as $code => $sym)
            <option value="{{ $code }}" @selected($currentTenant->currency === $code)>{{ $code }} ({{ $sym }})</option>
          @endforeach
        </select>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Currency symbol</label>
        <input type="text" name="currency_symbol" class="ia-input"
          value="{{ old('currency_symbol', $currentTenant->currency_symbol) }}" maxlength="5">
      </div>
    </div>
  </div>

  <button type="submit" class="ia-btn ia-btn--primary">Save general settings</button>
</form>
@endif

{{-- ============================================================ Booking ============================================================ --}}
@if($activeTab === 'booking')
<form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section">
  @csrf @method('PATCH')
  <input type="hidden" name="tab" value="booking">

  <div class="ia-card" style="margin-bottom:24px">
    <div class="ia-card-head"><span class="ia-card-title">Booking window</span></div>
    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label">How far ahead can customers book? (days)</label>
        <input type="number" name="booking_window_days" class="ia-input" min="1" max="365"
          value="{{ old('booking_window_days', $currentTenant->booking_window_days ?? 60) }}">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Minimum notice required (hours)</label>
        <input type="number" name="min_notice_hours" class="ia-input" min="0" max="168"
          value="{{ old('min_notice_hours', $currentTenant->min_notice_hours ?? 24) }}">
        <p style="font-size:11px;opacity:.4;margin-top:4px">0 = same-day bookings allowed</p>
      </div>
    </div>
  </div>

  <button type="submit" class="ia-btn ia-btn--primary">Save booking settings</button>
</form>
@endif

{{-- ============================================================ Payments ============================================================ --}}
@if($activeTab === 'payments')
<form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section">
  @csrf @method('PATCH')
  <input type="hidden" name="tab" value="payments">

  {{-- Stripe --}}
  <div class="provider-card {{ ($s['stripe_enabled'] ?? false) ? 'enabled' : '' }}" id="stripe-card">
    <div class="provider-header">
      <div>
        <div style="font-size:15px;font-weight:500">Stripe</div>
        <div style="font-size:12px;opacity:.5;margin-top:2px">Credit and debit cards</div>
      </div>
      <button type="button" class="prov-toggle-btn {{ ($s['stripe_enabled'] ?? false) ? 'on' : '' }}"
        id="stripe-toggle" onclick="toggleProvider('stripe')"></button>
      <input type="hidden" name="stripe_enabled" id="stripe-enabled-val" value="{{ ($s['stripe_enabled'] ?? false) ? '1' : '0' }}">
    </div>
    <div class="provider-fields" id="stripe-fields">
      <div class="ia-form-group">
        <label class="ia-form-label">Mode</label>
        <select name="stripe_mode" class="ia-input" style="width:auto">
          <option value="test" @selected(($s['stripe_mode'] ?? 'test') === 'test')>Test</option>
          <option value="live" @selected(($s['stripe_mode'] ?? 'test') === 'live')>Live</option>
        </select>
      </div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Test publishable key</label>
          <input type="text" name="stripe_test_pk" class="ia-input ia-mono" value="{{ $s['stripe_test_pk'] ?? '' }}" placeholder="pk_test_…">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Test secret key</label>
          <input type="password" name="stripe_test_sk" class="ia-input ia-mono" value="{{ $s['stripe_test_sk'] ?? '' }}" placeholder="sk_test_…">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Live publishable key</label>
          <input type="text" name="stripe_live_pk" class="ia-input ia-mono" value="{{ $s['stripe_live_pk'] ?? '' }}" placeholder="pk_live_…">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Live secret key</label>
          <input type="password" name="stripe_live_sk" class="ia-input ia-mono" value="{{ $s['stripe_live_sk'] ?? '' }}" placeholder="sk_live_…">
        </div>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Webhook secret</label>
        <input type="password" name="stripe_webhook_secret" class="ia-input ia-mono" value="{{ $s['stripe_webhook_secret'] ?? '' }}" placeholder="whsec_…">
      </div>
    </div>
  </div>

  {{-- PayPal --}}
  <div class="provider-card {{ ($s['paypal_enabled'] ?? false) ? 'enabled' : '' }}" id="paypal-card">
    <div class="provider-header">
      <div>
        <div style="font-size:15px;font-weight:500">PayPal</div>
        <div style="font-size:12px;opacity:.5;margin-top:2px">PayPal, Venmo, Pay Later</div>
      </div>
      <button type="button" class="prov-toggle-btn {{ ($s['paypal_enabled'] ?? false) ? 'on' : '' }}"
        id="paypal-toggle" onclick="toggleProvider('paypal')"></button>
      <input type="hidden" name="paypal_enabled" id="paypal-enabled-val" value="{{ ($s['paypal_enabled'] ?? false) ? '1' : '0' }}">
    </div>
    <div class="provider-fields" id="paypal-fields">
      <div class="ia-form-group">
        <label class="ia-form-label">Mode</label>
        <select name="paypal_mode" class="ia-input" style="width:auto">
          <option value="sandbox" @selected(($s['paypal_mode'] ?? 'sandbox') === 'sandbox')>Sandbox</option>
          <option value="live"    @selected(($s['paypal_mode'] ?? 'sandbox') === 'live')>Live</option>
        </select>
      </div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Sandbox client ID</label>
          <input type="text" name="paypal_test_client_id" class="ia-input ia-mono" value="{{ $s['paypal_test_client_id'] ?? '' }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Sandbox secret</label>
          <input type="password" name="paypal_test_secret" class="ia-input ia-mono" value="{{ $s['paypal_test_secret'] ?? '' }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Live client ID</label>
          <input type="text" name="paypal_live_client_id" class="ia-input ia-mono" value="{{ $s['paypal_live_client_id'] ?? '' }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Live secret</label>
          <input type="password" name="paypal_live_secret" class="ia-input ia-mono" value="{{ $s['paypal_live_secret'] ?? '' }}">
        </div>
      </div>
    </div>
  </div>

  <button type="submit" class="ia-btn ia-btn--primary" style="margin-top:8px">Save payment settings</button>
</form>
@endif

{{-- ============================================================ Domain ============================================================ --}}
@if($activeTab === 'domain')
<form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section">
  @csrf @method('PATCH')
  <input type="hidden" name="tab" value="domain">

  <div class="ia-card" style="margin-bottom:20px">
    <div class="ia-card-head"><span class="ia-card-title">Your booking URL</span></div>
    <div style="font-size:13px;margin-bottom:4px">
      <strong>{{ $currentTenant->publicUrl() }}</strong>
    </div>
    <div style="font-size:12px;opacity:.5">This is where customers go to book with you.</div>
  </div>

  <div class="ia-card" style="margin-bottom:24px">
    <div class="ia-card-head">
      <span class="ia-card-title">
        Custom domain
        <span class="domain-badge {{ $currentTenant->plan_tier }}">{{ ucfirst($currentTenant->plan_tier) }}</span>
      </span>
    </div>

    @if(in_array($currentTenant->plan_tier, ['branded', 'custom']))
      <p style="font-size:13px;opacity:.5;margin-bottom:16px">
        Point a CNAME record from your domain to <code style="font-family:var(--ia-font-mono);font-size:12px">intake.works</code>,
        then enter it here.
      </p>
      <div class="ia-form-group">
        <label class="ia-form-label">Custom domain</label>
        <input type="text" name="custom_domain" class="ia-input"
          value="{{ old('custom_domain', $currentTenant->custom_domain) }}"
          placeholder="book.yourshop.com">
      </div>
      <button type="submit" class="ia-btn ia-btn--primary">Save domain</button>
    @else
      <p style="font-size:13px;opacity:.5">
        Custom domains are available on the Branded and Custom plans.
        <a href="{{ route('tenant.settings.index') }}?tab=billing" style="color:var(--ia-accent)">Upgrade →</a>
      </p>
    @endif
  </div>
</form>
@endif

@endsection

@push('scripts')
<script>
function toggleProvider(name) {
  var card     = document.getElementById(name + '-card');
  var toggle   = document.getElementById(name + '-toggle');
  var valInput = document.getElementById(name + '-enabled-val');
  var enabled  = toggle.classList.toggle('on');
  card.classList.toggle('enabled', enabled);
  valInput.value = enabled ? '1' : '0';
}
</script>
@endpush
