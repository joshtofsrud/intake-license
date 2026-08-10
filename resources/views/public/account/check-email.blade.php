@extends('public.account._shell')
@php $pageTitle = 'Check your email'; @endphp
{{-- MARKER-CUST-AUTH — shown for ANY existing email, with or without an
     account, so the register form can't be used to discover who has one. --}}

@section('content')
<div style="max-width:460px;margin:0 auto">
  <div class="ac-card">
    <h1 class="ac-title">Check your email</h1>
    <p class="ac-subtitle">We found an existing profile for <b>{{ $email }}</b> at {{ $currentTenant->name }}.</p>

    <div class="ac-flash ac-flash--success" style="margin-bottom:18px">
      We've emailed you a secure link to finish setting up your account. It expires in 60 minutes.
    </div>

    <p style="font-size:13.5px;opacity:.6;line-height:1.6">
      This keeps your history private &mdash; nobody can claim your profile just by knowing your email address.
    </p>

    <p style="font-size:13.5px;opacity:.6;line-height:1.6;margin-top:12px">
      Didn't get it? Check your spam folder, or <a href="{{ route('tenant.customer.forgot') }}" class="ac-link">request another link</a>.
    </p>
  </div>
</div>
@endsection
