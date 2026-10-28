@extends('emails.layout')

@section('body')
@php
  $accent     = $tenant->accent_color ?? '#BEF264';
  $accentText = \App\Support\ColorHelper::accentTextColor($accent);
@endphp

<p style="font-size:18px;font-weight:700;margin:0 0 16px;letter-spacing:-.01em">
  Your password was reset
</p>

<p style="margin:0 0 20px;color:#444">
  Hi {{ $user->name }}, {{ $resetBy ? $resetBy . ' reset' : 'someone on the team reset' }} your {{ $tenant->name }} password.
  Sign in with this temporary one. You'll be asked to choose your own right away.
</p>

<p style="font-size:22px;font-weight:600;letter-spacing:.14em;text-align:center;font-family:'SF Mono',SFMono-Regular,Menlo,Consolas,monospace;color:#111;padding:18px 8px;border-top:1px solid #e6e6e1;border-bottom:1px solid #e6e6e1;margin:0 0 28px;word-break:break-all">
  {{ $tempPassword }}
</p>

<table cellpadding="0" cellspacing="0" style="margin:0 0 24px">
  <tr>
    <td style="border-radius:8px;background:{{ $accent }}">
      <a href="{{ $loginUrl }}"
        style="display:inline-block;padding:13px 28px;font-size:15px;font-weight:700;color:{{ $accentText }};text-decoration:none;border-radius:8px">
        Sign in →
      </a>
    </td>
  </tr>
</table>

<p style="font-size:13px;color:#888;margin:0">
  If you didn't expect this, tell your manager. Once you've changed it, delete this email.
</p>

@endsection
