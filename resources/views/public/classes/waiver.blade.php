@extends('public.account._shell')
@php $pageTitle = 'Waiver'; @endphp
@push('styles')
  @include('public.classes._online-css')
@endpush

@section('content')
{{-- MARKER-CLASSES-P9: read and sign the studio's waiver from an emailed link --}}
<div style="padding:8px 0">
  <h1 style="font-size:22px;font-weight:700;font-family:var(--p-font-heading);margin:0 0 6px">{{ $currentTenant->name }} waiver</h1>

  @if($state === 'none')
    <p style="opacity:.6">There's nothing to sign right now.</p>
  @elseif($state === 'signed')
    <div class="ac-flash ac-flash--success">Thanks, {{ $customer->first_name ?: 'you' }}. You've signed the waiver.</div>
    <p><a class="ac-link" href="{{ route('tenant.customer.classes') }}">See the class schedule</a></p>
  @else
    <p style="opacity:.6;margin:0 0 14px">Hi {{ $customer->first_name ?: 'there' }}, please read this and sign before your next class.</p>
    @if($errors->any())<div class="ac-flash ac-flash--error">{{ $errors->first() }}</div>@endif
    <div style="font-size:14px;line-height:1.65;white-space:pre-line;padding:14px 16px;background:var(--p-surface);border-radius:var(--p-r-lg);margin-bottom:16px">{{ $waiver->body }}</div>
    <form method="POST" action="{{ request()->fullUrl() }}">
      @csrf
      <label class="co-check"><input type="checkbox" name="waiver_agree" value="1" required> <span>I've read the waiver and agree to it.</span></label>
      <div class="ac-field">
        <label class="ac-label">Your full name</label>
        <input type="text" name="waiver_name" class="ac-input" required maxlength="160" autocomplete="name" value="{{ old('waiver_name', trim($customer->first_name . ' ' . $customer->last_name)) }}">
      </div>
      <button type="submit" class="ac-btn ac-btn--primary">Sign the waiver</button>
    </form>
  @endif
</div>
@endsection
