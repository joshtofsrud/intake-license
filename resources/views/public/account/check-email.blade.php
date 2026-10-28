@extends('public.account._shell')
@php $pageTitle = 'Check your email'; @endphp
{{-- MARKER-EMAIL-FIRST-SIGNUP: shown for EVERY sign-up, new, on file or
     already with a password, so the form can't be used to discover who has one. --}}

@section('content')
<div style="max-width:460px;margin:0 auto">
  <div class="ac-card">
    <h1 class="ac-title">Check your email</h1>
    <p class="ac-subtitle">We've sent a link to <b>{{ $email }}</b>.</p>
    <div class="ac-flash ac-flash--success" style="margin-bottom:18px">
      Open it to confirm it's you and create your password. It expires in 60 minutes.
    </div>
    <p style="font-size:13.5px;opacity:.6;line-height:1.6">
      Confirming first keeps your history with {{ $currentTenant->name }} private &mdash; nobody can open an account in your name just by knowing your email address.
    </p>
    <p style="font-size:13.5px;opacity:.6;line-height:1.6;margin-top:12px">
      Didn't get it? Check your spam folder, or <a href="{{ route('tenant.customer.forgot') }}" class="ac-link">request another link</a>.
    </p>
  </div>
</div>
@endsection
