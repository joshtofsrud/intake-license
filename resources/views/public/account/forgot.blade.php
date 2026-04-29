@extends('public.account._shell')
@php $pageTitle = 'Reset password'; @endphp

@section('content')
<div class="ac-card">
  <div class="ac-title">Reset your password</div>
  <div class="ac-subtitle">Enter your email and we'll send you a reset link.</div>

  @if(session('success'))
    <div class="ac-flash ac-flash--success">{{ session('success') }}</div>
  @endif

  @if($errors->any())
    <div class="ac-flash ac-flash--error">{{ $errors->first() }}</div>
  @endif

  <form method="POST" action="{{ route('tenant.customer.forgot.submit') }}">
    @csrf
    <div class="ac-field" style="margin-bottom:20px">
      <label class="ac-label">Email</label>
      <input type="email" name="email" class="ac-input" value="{{ old('email') }}" required autofocus placeholder="you@example.com">
    </div>
    <button type="submit" class="ac-btn ac-btn--primary">Send reset link</button>
  </form>

  <div style="text-align:center;margin-top:20px;font-size:14px;opacity:.6">
    <a href="{{ route('tenant.customer.login') }}" class="ac-link">Back to sign in</a>
  </div>
</div>
@endsection
