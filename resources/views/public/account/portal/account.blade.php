@extends('public.account._shell')
@php $pageTitle = 'Account'; @endphp
{{-- MARKER-PORTAL-V2 --}}
@push('styles')
  @include('public.account.portal._portal-css')
@endpush

@section('content')
@include('public.account.portal._nav', ['active' => 'account'])

@if(session('success'))
  <div class="ac-flash ac-flash--success">{{ session('success') }}</div>
@endif

@php $assetPlural = $currentTenant->asset_label_plural ?: 'items'; @endphp
@if($assets->isNotEmpty())
  <div class="ac-section-title">Your {{ $assetPlural }}</div>
  <div class="ac-list">
    @foreach($assets as $a)
      <div class="ac-list-row">
        <div><div class="ac-list-name">{{ $a->name }}</div>
          @if($a->identifier)<div class="ac-list-meta">{{ $a->identifier }}</div>@endif</div>
      </div>
    @endforeach
  </div>
@endif

<div class="ac-section-title">Profile</div>
<div class="ac-card" style="padding:20px;margin-bottom:22px">
  <form method="POST" action="{{ route('tenant.customer.portal.profile') }}">
    @csrf
    <div class="ac-row">
      <div class="ac-field"><label class="ac-label">First name</label>
        <input class="ac-input" name="first_name" value="{{ old('first_name', $customer->first_name) }}" required></div>
      <div class="ac-field"><label class="ac-label">Last name</label>
        <input class="ac-input" name="last_name" value="{{ old('last_name', $customer->last_name) }}" required></div>
    </div>
    <div class="ac-field"><label class="ac-label">Phone</label>
      <input class="ac-input" name="phone" value="{{ old('phone', $customer->phone) }}"></div>
    <div class="ac-field" style="margin-bottom:18px"><label class="ac-label">Email</label>
      <input class="ac-input" value="{{ $customer->email }}" disabled style="opacity:.55">
      <div style="font-size:12px;opacity:.45;margin-top:5px">Your email is your sign-in &mdash; message us to change it.</div></div>
    <button type="submit" class="ac-btn ac-btn--primary" style="max-width:200px;padding:11px">Save changes</button>
  </form>
</div>

<div class="ac-section-title">Notifications</div>
<div class="ac-card" style="padding:20px">
  <form method="POST" action="{{ route('tenant.customer.portal.notifications') }}">
    @csrf
    {{-- MARKER-EMAIL-CONSENT --}}
    <div class="ac-check-row" style="margin-bottom:14px">
      <input type="checkbox" name="email_marketing" value="1" id="n-em" {{ $customer->emailMarketingMailable() ? 'checked' : '' }}>
      <label for="n-em">Email me news and offers</label>
    </div>
    <div style="font-size:12.5px;opacity:.55;margin:-6px 0 14px">Receipts and booking confirmations always come through &mdash; this only controls marketing.</div>
    <div class="ac-check-row" style="margin-bottom:14px">
      <input type="checkbox" name="sms" value="1" id="n-sms" {{ $customer->sms_opt_out_at ? '' : 'checked' }}>
      <label for="n-sms">Text me confirmations and reminders</label>
    </div>
    @if($customer->sms_opt_out_at)
      <div style="font-size:12.5px;opacity:.55;margin:-6px 0 14px">You texted STOP &mdash; reply START to our last text to turn these back on. Carriers require it come from your phone.</div>
    @endif
    <button type="submit" class="ac-btn ac-btn--ghost" style="max-width:200px;padding:10px;font-size:13.5px">Save notifications</button>
  </form>
</div>
@endsection
