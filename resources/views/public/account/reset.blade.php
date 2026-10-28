@extends('public.account._shell')
{{-- MARKER-EMAIL-FIRST-SIGNUP: one page for "create your password" (first time:
     a sign-up or a shop's invite) and "set a new password" (a reset). A dead
     link says so before asking for anything. --}}
@php $pageTitle = ! $valid ? 'Link expired' : ($setup ? 'Create your password' : 'Set new password'); @endphp

@section('content')
<div class="ac-card">
  @if (! $valid)
    <div class="ac-title">This link has expired</div>
    <div class="ac-subtitle">Links work for 60 minutes and only once. Send yourself a new one and use the newest email.</div>
    <a href="{{ route('tenant.customer.forgot', array_filter(['email' => $email ?: null])) }}" class="ac-btn ac-btn--primary" style="display:block;text-align:center;margin-top:18px">Send a new link</a>
  @else
    <div class="ac-title">{{ $setup ? 'Create your password' : 'Set a new password' }}</div>
    <div class="ac-subtitle">{{ $setup ? 'Your email is confirmed. Choose a password and you\'re in.' : 'Choose a strong password for your account.' }}</div>

    @if($errors->any())
      <div class="ac-flash ac-flash--error">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('tenant.customer.reset.submit') }}">
      @csrf
      <input type="hidden" name="token" value="{{ $token }}">
      <input type="hidden" name="email" value="{{ $email }}">
      <div class="ac-field">
        <label class="ac-label">{{ $setup ? 'Password' : 'New password' }}</label>
        <input type="password" name="password" class="ac-input" required autofocus placeholder="At least 8 characters" autocomplete="new-password">
      </div>
      <div class="ac-field" style="margin-bottom:20px">
        <label class="ac-label">Confirm {{ $setup ? 'password' : 'new password' }}</label>
        <input type="password" name="password_confirmation" class="ac-input" required autocomplete="new-password">
      </div>
      <button type="submit" class="ac-btn ac-btn--primary">{{ $setup ? 'Create password & sign in' : 'Update password' }}</button>
    </form>
  @endif
</div>
@endsection
