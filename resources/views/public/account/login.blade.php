@extends('public.account._shell')
@php $pageTitle = 'Sign in'; @endphp

@section('content')
<div class="ac-card">
  <div class="ac-title">Welcome back</div>
  <div class="ac-subtitle">Sign in to manage your bookings, classes and account.</div>

  @if(session('success'))
    <div class="ac-flash ac-flash--success">{{ session('success') }}</div>
  @endif

  @if($errors->has('email'))
    <div class="ac-flash ac-flash--error">{{ $errors->first('email') }}</div>
  @endif

  <form method="POST" action="{{ route('tenant.customer.login.submit') }}">
    @csrf
    <div class="ac-field">
      <label class="ac-label">Email</label>
      <input type="email" name="email" class="ac-input" value="{{ old('email') }}" required autofocus placeholder="you@example.com">
    </div>
    <div class="ac-field">
      <label class="ac-label">Password</label>
      <input type="password" name="password" class="ac-input" required placeholder="••••••••">
    </div>
    <div class="ac-check-row" style="margin-bottom:20px">
      <input type="checkbox" name="remember" id="remember" value="1">
      <label for="remember" style="cursor:pointer">Keep me signed in</label>
      <a href="{{ route('tenant.customer.forgot') }}" class="ac-link" style="margin-left:auto;font-size:13px">Forgot password?</a>
    </div>
    <button type="submit" class="ac-btn ac-btn--primary">Sign in</button>
  </form>

  <div class="ac-divider">or</div>

  <a href="{{ route('tenant.customer.register') }}" class="ac-btn ac-btn--ghost" style="display:block;text-align:center;padding:13px;border-radius:var(--p-r);font-weight:600;font-size:15px">Create an account</a>
</div>
@endsection
