@extends('layouts.tenant.app')
@php $pageTitle = 'Notifications'; @endphp

{{-- MARKER-PATCH-225 — per-user staff alert preferences. --}}

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Notifications</h1>
    <p class="ia-page-subtitle">Choose how you want to hear about what's happening. These settings are just for you.</p>
  </div>
  <a href="{{ route('tenant.settings.index') }}" class="ia-btn">All settings</a>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif

@unless($smsAvailable)
  <div class="ia-flash" style="margin-bottom:16px;background:rgba(127,127,127,.08);font-size:12.5px">
    Text alerts need a business number — set one up on the <a href="{{ route('tenant.settings.messaging') }}">Messaging page</a>. You can still pick in-app alerts below.
  </div>
@endunless

<form method="POST" action="{{ route('tenant.alerts.prefs.save') }}">
  @csrf
  <div class="ia-card" style="padding:0;overflow:hidden">
    <div style="display:grid;grid-template-columns:1fr 80px 80px;gap:10px;padding:12px 18px;border-bottom:.5px solid var(--ia-border);font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.55">
      <span>Event</span><span style="text-align:center">In-app</span><span style="text-align:center">Text</span>
    </div>
    @foreach($rows as $row)
      <div style="display:grid;grid-template-columns:1fr 80px 80px;gap:10px;padding:12px 18px;border-bottom:.5px solid var(--ia-border);align-items:center">
        <span style="font-size:13.5px;font-weight:500">{{ $row['label'] }}</span>
        <span style="text-align:center">
          <input type="checkbox" name="prefs[{{ $row['event'] }}][in_app]" value="1" {{ $row['in_app'] ? 'checked' : '' }}>
        </span>
        <span style="text-align:center">
          <input type="checkbox" name="prefs[{{ $row['event'] }}][sms]" value="1" {{ $row['sms'] ? 'checked' : '' }} {{ $smsAvailable ? '' : 'disabled' }}>
        </span>
      </div>
    @endforeach
  </div>
  <div style="margin-top:16px">
    <button type="submit" class="ia-btn ia-btn--primary">Save preferences</button>
  </div>
  <p style="font-size:11px;opacity:.45;margin-top:10px;line-height:1.5">
    Critical alerts — failed payments, overdue rentals, deposit captures — always reach you in-app regardless of these settings.
  </p>
</form>

@endsection
