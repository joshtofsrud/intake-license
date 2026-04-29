@extends('public.account._shell')
@php $pageTitle = 'Create account'; @endphp

@section('content')
<div class="ac-card">
  <div class="ac-title">Create your account</div>
  <div class="ac-subtitle">Save your details, track bookings and manage class credits.</div>

  @if($errors->any())
    <div class="ac-flash ac-flash--error">{{ $errors->first() }}</div>
  @endif

  <form method="POST" action="{{ route('tenant.customer.register.submit') }}">
    @csrf
    <div class="ac-row">
      <div class="ac-field">
        <label class="ac-label">First name</label>
        <input type="text" name="first_name" class="ac-input" value="{{ old('first_name') }}" required autofocus>
      </div>
      <div class="ac-field">
        <label class="ac-label">Last name</label>
        <input type="text" name="last_name" class="ac-input" value="{{ old('last_name') }}" required>
      </div>
    </div>
    <div class="ac-field">
      <label class="ac-label">Email</label>
      <input type="email" name="email" class="ac-input" value="{{ old('email') }}" required placeholder="you@example.com">
    </div>
    <div class="ac-field">
      <label class="ac-label">Password</label>
      <input type="password" name="password" class="ac-input" required placeholder="At least 8 characters">
    </div>
    <div class="ac-field" style="margin-bottom:20px">
      <label class="ac-label">Confirm password</label>
      <input type="password" name="password_confirmation" class="ac-input" required>
    </div>
    <button type="submit" class="ac-btn ac-btn--primary">Create account</button>
  </form>

  <div class="ac-divider">already have an account?</div>

  <a href="{{ route('tenant.customer.login') }}" class="ac-btn ac-btn--ghost" style="display:block;text-align:center;padding:13px;border-radius:var(--p-r);font-weight:600;font-size:15px">Sign in</a>
</div>
@endsection
