@extends('public.account._shell')
@php $pageTitle = 'Set new password'; @endphp

@section('content')
<div class="ac-card">
  <div class="ac-title">Set a new password</div>
  <div class="ac-subtitle">Choose a strong password for your account.</div>

  @if($errors->any())
    <div class="ac-flash ac-flash--error">{{ $errors->first() }}</div>
  @endif

  <form method="POST" action="{{ route('tenant.customer.reset.submit') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <input type="hidden" name="email" value="{{ $email }}">
    <div class="ac-field">
      <label class="ac-label">New password</label>
      <input type="password" name="password" class="ac-input" required autofocus placeholder="At least 8 characters">
    </div>
    <div class="ac-field" style="margin-bottom:20px">
      <label class="ac-label">Confirm new password</label>
      <input type="password" name="password_confirmation" class="ac-input" required>
    </div>
    <button type="submit" class="ac-btn ac-btn--primary">Update password</button>
  </form>
</div>
@endsection
