@extends('layouts.tenant.app')

{{-- MARKER-REG-SETTINGS -- register settings tab --}}

@php $pageTitle = 'Register settings'; @endphp

@push('styles')
<style>
  .reg-tabs-bar{
    display:flex;gap:4px;margin:0 0 18px;border-bottom:0.5px solid var(--ia-border);
    flex-wrap:wrap
  }
  .reg-tab-link{
    padding:10px 18px;font-size:13px;font-weight:500;color:var(--ia-text-dim);
    text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-0.5px;
    transition:color var(--ia-t),border-color var(--ia-t)
  }
  .reg-tab-link:hover{color:var(--ia-text)}
  .reg-tab-link.active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}

  .rs-card{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:20px 24px;margin-bottom:16px;max-width:720px}
  .rs-card h2{font-size:13px;font-weight:500;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px}
  .rs-card .rs-desc{font-size:13px;color:var(--ia-text-dim);margin-bottom:14px;line-height:1.55}
  .rs-row{display:flex;align-items:center;gap:12px}
  .rs-row label{font-size:13px;color:var(--ia-text-muted);min-width:110px}
  .rs-links{display:flex;flex-direction:column;gap:8px}
  .rs-links a{font-size:13px;color:var(--ia-text-muted);transition:color var(--ia-t)}
  .rs-links a:hover{color:var(--ia-text)}
</style>
@endpush

@section('content')

<div class="reg-tabs-bar">
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link">Transaction</a>
  <a href="{{ route('tenant.register.history.index') }}" class="reg-tab-link">Transaction History</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link">Quotes</a>
  <a href="{{ route('tenant.register.registers') }}" class="reg-tab-link">Registers</a>
  <a href="{{ route('tenant.register.settings') }}" class="reg-tab-link active">Settings</a>
</div>

@if (session('status'))
  <div class="ia-flash ia-flash--success" style="max-width:720px">{{ session('status') }}</div>
@endif

<form method="POST" action="{{ route('tenant.register.settings.save') }}">
  @csrf

  <div class="rs-card">
    <h2>Draft transactions</h2>
    <div class="rs-desc">
      Drafts are unfinished carts saved at the register. Old drafts with no payments
      are discarded automatically past this age &mdash; the same as pressing Discard,
      so any un-placed special orders they requested are retracted too. Drafts parked
      on an appointment are never touched.
    </div>
    <div class="rs-row">
      <label for="rs-draft">Keep drafts</label>
      <select id="rs-draft" name="register_draft_retention_days" class="ia-input" style="width:auto;min-width:180px">
        <option value="0"  @selected($draftRetention === 0)>Forever</option>
        <option value="7"  @selected($draftRetention === 7)>7 days</option>
        <option value="14" @selected($draftRetention === 14)>14 days</option>
        <option value="30" @selected($draftRetention === 30)>30 days</option>
        <option value="90" @selected($draftRetention === 90)>90 days</option>
      </select>
    </div>
  </div>

  <div class="rs-card">
    <h2>Quotes</h2>
    <div class="rs-desc">
      Quotes are estimates you hand a customer to think over. If you set an age here,
      quotes older than it are discarded the same way. Leave it on Forever if you
      follow up on old quotes.
    </div>
    <div class="rs-row">
      <label for="rs-quote">Keep quotes</label>
      <select id="rs-quote" name="register_quote_retention_days" class="ia-input" style="width:auto;min-width:180px">
        <option value="0"   @selected($quoteRetention === 0)>Forever</option>
        <option value="30"  @selected($quoteRetention === 30)>30 days</option>
        <option value="90"  @selected($quoteRetention === 90)>90 days</option>
        <option value="180" @selected($quoteRetention === 180)>180 days</option>
        <option value="365" @selected($quoteRetention === 365)>1 year</option>
      </select>
    </div>
  </div>

  <div style="max-width:720px;margin-bottom:24px">
    <button type="submit" class="ia-btn ia-btn--primary">Save settings</button>
  </div>
</form>

<div class="rs-card">
  <h2>More register settings</h2>
  <div class="rs-desc">These live in the main settings area:</div>
  <div class="rs-links">
    <a href="{{ route('tenant.settings.index') }}#payments">Payment methods, manual tenders &amp; card payments (Stripe) &rarr;</a>
    <a href="{{ route('tenant.settings.index') }}#tags">Receipt footer &amp; print identity &rarr;</a>
  </div>
</div>

@endsection
