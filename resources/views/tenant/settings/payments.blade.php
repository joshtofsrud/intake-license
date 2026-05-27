@extends('layouts.tenant.app')
@php $pageTitle = 'Payments'; @endphp

{{-- MARKER-PATCH-168 — Stripe Connect Session A. --}}

@push('styles')
<style>
.pay-card { background: var(--ia-surface); border: 0.5px solid var(--ia-border); border-radius: var(--ia-r-lg); padding: 24px; margin-bottom: 20px; }
.pay-card-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; margin-bottom: 16px; }
.pay-card-title { font-size: 15px; font-weight: 600; letter-spacing: -0.01em; margin: 0; }
.pay-card-sub { font-size: 12px; color: var(--ia-text-dim); font-weight: 400; margin-top: 4px; }

.pay-hero { display: flex; gap: 28px; align-items: center; padding: 8px; }
.pay-hero-icon { width: 56px; height: 56px; background: var(--ia-accent-soft); border-radius: var(--ia-r-lg); display: flex; align-items: center; justify-content: center; color: var(--ia-accent); flex-shrink: 0; }
.pay-hero-body { flex: 1; }
.pay-hero-title { font-size: 18px; font-weight: 600; letter-spacing: -0.01em; margin: 0 0 6px; }
.pay-hero-text { font-size: 13px; color: var(--ia-text-muted); margin: 0 0 16px; line-height: 1.6; max-width: 540px; }
.pay-features { list-style: none; padding: 0; margin: 14px 0 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px 18px; max-width: 540px; }
.pay-features li { font-size: 12.5px; color: var(--ia-text-muted); display: flex; align-items: flex-start; gap: 8px; }
.pay-features svg { width: 14px; height: 14px; color: var(--ia-accent); flex-shrink: 0; margin-top: 3px; }

.pay-status-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin: 8px 0 22px; }
.pay-status-item { background: var(--ia-surface-2); border: 0.5px solid var(--ia-border); border-radius: var(--ia-r-md); padding: 14px 16px; }
.pay-status-item-label { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--ia-text-dim); margin-bottom: 8px; }
.pay-status-item-value { font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px; }

.pay-warn-banner { display: flex; gap: 12px; align-items: flex-start; padding: 14px 16px; border-radius: var(--ia-r-md); margin-bottom: 18px; }
.pay-warn-banner--warning { background: rgba(251,191,36,.10); border: 0.5px solid rgba(251,191,36,.25); }
.pay-warn-banner--danger { background: rgba(248,113,113,.10); border: 0.5px solid rgba(248,113,113,.25); }
.pay-warn-banner svg { flex-shrink: 0; margin-top: 2px; }
.pay-warn-banner--warning svg { color: #fbbf24; }
.pay-warn-banner--danger svg { color: #f87171; }
.pay-warn-banner-title { font-size: 13px; font-weight: 500; color: var(--ia-text); margin: 0 0 4px; }
.pay-warn-banner-body { font-size: 12.5px; color: var(--ia-text-muted); margin: 0; }

.ia-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; font-size: 11px; font-weight: 500; border-radius: 999px; background: var(--ia-surface-2); color: var(--ia-text-muted); border: 0.5px solid var(--ia-border); }
.ia-pill--success { background: rgba(74,222,128,.10); color: #4ade80; border-color: rgba(74,222,128,.25); }
.ia-pill--warning { background: rgba(251,191,36,.10); color: #fbbf24; border-color: rgba(251,191,36,.25); }
.ia-pill--danger { background: rgba(248,113,113,.10); color: #f87171; border-color: rgba(248,113,113,.25); }
.ia-pill-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

.pay-reqs { background: var(--ia-surface-2); border-radius: var(--ia-r-md); padding: 14px 16px; margin: 8px 0 18px; }
.pay-reqs-title { font-size: 13px; font-weight: 500; margin-bottom: 8px; }
.pay-reqs ul { margin: 0; padding-left: 18px; font-size: 12.5px; color: var(--ia-text-muted); line-height: 1.7; }

.pay-disconnect-form { display: inline; }
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Settings</h1>
    <p class="ia-page-subtitle">Configure your shop, payments, and team.</p>
  </div>
</div>

{{-- Settings sub-nav. For now Payments is a sibling route. Other tabs still live on /settings. --}}
<div style="display:flex;gap:2px;border-bottom:0.5px solid var(--ia-border);margin-bottom:28px">
  <a href="{{ route('tenant.settings.index') }}" style="padding:10px 14px;font-size:13px;color:var(--ia-text-muted);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-0.5px">Business &amp; other</a>
  <a href="{{ route('tenant.settings.payments.index') }}" style="padding:10px 14px;font-size:13px;color:var(--ia-text);text-decoration:none;border-bottom:2px solid var(--ia-accent);margin-bottom:-0.5px;font-weight:500">Payments</a>
</div>

@if(session('error'))
  <div class="pay-warn-banner pay-warn-banner--danger">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <div>
      <p class="pay-warn-banner-title">{{ session('error') }}</p>
    </div>
  </div>
@endif

@if($connectStatus === 'not_connected')
  {{-- ================================================================
       STATE: NOT CONNECTED
       ================================================================ --}}
  <div class="pay-card">
    <div class="pay-hero">
      <div class="pay-hero-icon">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <rect x="2" y="6" width="20" height="14" rx="2"/>
          <path d="M2 11h20"/>
          <path d="M6 16h2"/>
        </svg>
      </div>
      <div class="pay-hero-body">
        <h2 class="pay-hero-title">Accept card payments at the register</h2>
        <p class="pay-hero-text">Connect Stripe to take Visa, Mastercard, Amex, and Discover directly from the register. Funds are deposited into your bank account on Fridays. Setup takes about 5 minutes.</p>
        <ul class="pay-features">
          <li><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l3 3 7-7"/></svg> Hand-key card numbers at checkout</li>
          <li><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l3 3 7-7"/></svg> Issue refunds with one click</li>
          <li><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l3 3 7-7"/></svg> Brand &amp; last-4 on every receipt</li>
          <li><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l3 3 7-7"/></svg> 2.9% + 30¢ per transaction</li>
        </ul>
        <form method="POST" action="{{ route('tenant.settings.payments.connect') }}" style="display:inline">
          @csrf
          <button type="submit" class="ia-btn ia-btn--primary" style="padding:11px 18px;font-size:14px">Connect Stripe</button>
        </form>
        <span style="font-size:12px;color:var(--ia-text-dim);margin-left:12px">Powered by Stripe Connect</span>
      </div>
    </div>
  </div>

@elseif($connectStatus === 'onboarding')
  {{-- ================================================================
       STATE: ONBOARDING IN PROGRESS
       ================================================================ --}}
  <div class="pay-warn-banner pay-warn-banner--warning">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
      <line x1="12" y1="9" x2="12" y2="13"/>
      <line x1="12" y1="17" x2="12.01" y2="17"/>
    </svg>
    <div>
      <p class="pay-warn-banner-title">Finish setup with Stripe</p>
      <p class="pay-warn-banner-body">Stripe needs a few more details before you can accept payments. This usually takes 2–3 minutes.</p>
    </div>
  </div>

  <div class="pay-card">
    <div class="pay-card-head">
      <div>
        <h3 class="pay-card-title">Stripe account</h3>
        <p class="pay-card-sub">Onboarding in progress</p>
      </div>
      <span class="ia-pill ia-pill--warning"><span class="ia-pill-dot"></span> Onboarding</span>
    </div>

    <div class="pay-status-grid">
      <div class="pay-status-item">
        <div class="pay-status-item-label">Account ID</div>
        <div class="pay-status-item-value" style="font-family:var(--ia-font-mono);font-size:12.5px;color:var(--ia-text-muted)">{{ $tenant->stripe_connect_account_id }}</div>
      </div>
      <div class="pay-status-item">
        <div class="pay-status-item-label">Country</div>
        <div class="pay-status-item-value">{{ $tenant->stripe_connect_country }}</div>
      </div>
      <div class="pay-status-item">
        <div class="pay-status-item-label">Charges enabled</div>
        <div class="pay-status-item-value"><span class="ia-pill ia-pill--warning" style="padding:2px 8px;font-size:10.5px">Not yet</span></div>
      </div>
      <div class="pay-status-item">
        <div class="pay-status-item-label">Payouts enabled</div>
        <div class="pay-status-item-value"><span class="ia-pill ia-pill--warning" style="padding:2px 8px;font-size:10.5px">Not yet</span></div>
      </div>
    </div>

    <div style="display:flex;gap:12px;align-items:center">
      <form method="POST" action="{{ route('tenant.settings.payments.resume') }}" style="display:inline">
        @csrf
        <button type="submit" class="ia-btn ia-btn--primary">Continue setup in Stripe</button>
      </form>
      <form method="POST" action="{{ route('tenant.settings.payments.disconnect') }}" style="display:inline" onsubmit="return confirm('This will undo your in-progress Stripe setup. You can reconnect anytime.');">
        @csrf
        <input type="hidden" name="confirm" value="yes">
        <button type="submit" class="ia-btn" style="color:#f87171;border-color:rgba(248,113,113,.30)">Disconnect</button>
      </form>
    </div>
  </div>

@elseif($connectStatus === 'restricted')
  {{-- ================================================================
       STATE: RESTRICTED (Stripe paused the account)
       ================================================================ --}}
  <div class="pay-warn-banner pay-warn-banner--danger">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="10"/>
      <line x1="12" y1="8" x2="12" y2="12"/>
      <line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>
    <div>
      <p class="pay-warn-banner-title">Stripe needs more information</p>
      <p class="pay-warn-banner-body">Your account is paused until you provide updated business details. Card payments are disabled in the register.</p>
    </div>
  </div>

  <div class="pay-card">
    <div class="pay-card-head">
      <div>
        <h3 class="pay-card-title">Stripe account</h3>
        <p class="pay-card-sub">Restricted{{ count($requirements) ? ' · ' . count($requirements) . ' requirement' . (count($requirements) === 1 ? '' : 's') . ' due' : '' }}</p>
      </div>
      <span class="ia-pill ia-pill--danger"><span class="ia-pill-dot"></span> Restricted</span>
    </div>

    <div class="pay-status-grid">
      <div class="pay-status-item">
        <div class="pay-status-item-label">Account ID</div>
        <div class="pay-status-item-value" style="font-family:var(--ia-font-mono);font-size:12.5px;color:var(--ia-text-muted)">{{ $tenant->stripe_connect_account_id }}</div>
      </div>
      <div class="pay-status-item">
        <div class="pay-status-item-label">Charges</div>
        <div class="pay-status-item-value"><span class="ia-pill ia-pill--danger" style="padding:2px 8px;font-size:10.5px">Paused</span></div>
      </div>
    </div>

    @if(count($requirements))
      <div class="pay-reqs">
        <div class="pay-reqs-title">Requirements due</div>
        <ul>
          @foreach($requirements as $req)
            <li>{{ str_replace('_', ' ', $req) }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <div style="display:flex;gap:12px;align-items:center">
      <form method="POST" action="{{ route('tenant.settings.payments.resume') }}" style="display:inline">
        @csrf
        <button type="submit" class="ia-btn ia-btn--primary">Resolve requirements in Stripe</button>
      </form>
    </div>
  </div>

@else
  {{-- ================================================================
       STATE: LIVE (connected, charges enabled)
       ================================================================ --}}
  <div class="pay-card">
    <div class="pay-card-head">
      <div>
        <h3 class="pay-card-title">Stripe account</h3>
        <p class="pay-card-sub">Connected · accepting payments</p>
      </div>
      <span class="ia-pill ia-pill--success"><span class="ia-pill-dot"></span> Live</span>
    </div>

    <div class="pay-status-grid">
      <div class="pay-status-item">
        <div class="pay-status-item-label">Account ID</div>
        <div class="pay-status-item-value" style="font-family:var(--ia-font-mono);font-size:12.5px;color:var(--ia-text-muted)">{{ $tenant->stripe_connect_account_id }}</div>
      </div>
      <div class="pay-status-item">
        <div class="pay-status-item-label">Connected on</div>
        <div class="pay-status-item-value">{{ optional($tenant->stripe_connect_details_submitted_at)->format('M j, Y') }}</div>
      </div>
      <div class="pay-status-item">
        <div class="pay-status-item-label">Charges</div>
        <div class="pay-status-item-value"><span class="ia-pill ia-pill--success" style="padding:2px 8px;font-size:10.5px">Enabled</span></div>
      </div>
      <div class="pay-status-item">
        <div class="pay-status-item-label">Payouts</div>
        <div class="pay-status-item-value"><span class="ia-pill ia-pill--success" style="padding:2px 8px;font-size:10.5px">{{ $tenant->stripe_connect_payouts_enabled ? 'Weekly · Fridays' : 'Not yet enabled' }}</span></div>
      </div>
    </div>

    <div style="display:flex;gap:12px;align-items:center">
      <form method="POST" action="{{ route('tenant.settings.payments.resume') }}" style="display:inline">
        @csrf
        <button type="submit" class="ia-btn">Open Stripe dashboard ↗</button>
      </form>
      <form method="POST" action="{{ route('tenant.settings.payments.disconnect') }}" style="display:inline" onsubmit="return confirm('Disconnect Stripe?\nCard payments will stop immediately in your register. Cash, check, and store credit will keep working.\nYou can reconnect to the same Stripe account anytime.');">
        @csrf
        <input type="hidden" name="confirm" value="yes">
        <button type="submit" class="ia-btn" style="color:#f87171;border-color:rgba(248,113,113,.30)">Disconnect</button>
      </form>
    </div>
  </div>

  <div class="pay-card">
    <div class="pay-card-head">
      <div>
        <h3 class="pay-card-title">Activity</h3>
        <p class="pay-card-sub">Card transactions through Stripe</p>
      </div>
    </div>
    <p style="font-size:13px;color:var(--ia-text-muted);margin:0">Card transactions will appear here once Session B (register integration) ships. For now you can view activity directly in your Stripe dashboard.</p>
  </div>
@endif

@endsection
