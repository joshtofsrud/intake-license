@extends('emails.layout')

@section('body')
@php
  $accent     = $tenant->accent_color ?? '#BEF264';
  $accentText = \App\Support\ColorHelper::accentTextColor($accent);
@endphp

<p style="font-size:18px;font-weight:700;margin:0 0 16px;letter-spacing:-.01em">
  You've been added to {{ $tenant->name }}
</p>

<p style="margin:0 0 24px;color:#444">
  Hi {{ $user->name }}, {{ $inviter ? $inviter . ' invited you' : 'you\'ve been invited' }} to join the
  {{ $tenant->name }} team. Click below to set your password and finish setting up your account.
</p>

{{-- CTA button --}}
<table cellpadding="0" cellspacing="0" style="margin:0 0 24px">
  <tr>
    <td style="border-radius:8px;background:{{ $accent }}">
      <a href="{{ $setupUrl }}"
        style="display:inline-block;padding:13px 28px;font-size:15px;font-weight:700;color:{{ $accentText }};text-decoration:none;border-radius:8px">
        Set up my account →
      </a>
    </td>
  </tr>
</table>

<p style="font-size:13px;color:#888;margin:0 0 8px">
  Or copy this link into your browser:
</p>
<p style="font-size:12px;color:#888;word-break:break-all;background:#f8f8f6;padding:10px 12px;border-radius:6px;margin:0 0 24px">
  {{ $setupUrl }}
</p>

<p style="font-size:13px;color:#888;margin:0">
  This link expires in 7 days. If you weren't expecting this, you can safely ignore this email.
</p>

@endsection
