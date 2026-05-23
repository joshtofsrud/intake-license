@extends('layouts.tenant')

@section('content')
<div style="padding: 24px 32px;">
  <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; color: #555; margin-bottom: 6px;">
    Settings → Domains → {{ $domain->hostname }}
  </div>
  <div style="font-size: 22px; font-weight: 800; font-family: monospace;">{{ $domain->hostname }}</div>
  <div style="font-size: 13px; color: #888; margin-top: 4px;">Stub — Part 2 ships the real UI.</div>

  <div style="margin-top: 24px; padding: 18px; background: #131313; border: 1px solid #1f1f1f; border-radius: 12px;">
    <p>Status: <strong>{{ $domain->status }}</strong></p>
    <p style="margin-top: 8px;">CF hostname ID: <code>{{ $domain->cloudflare_hostname_id ?? '(none)' }}</code></p>
    <p style="margin-top: 8px;">Last check: {{ $domain->last_check_at?->diffForHumans() ?? 'never' }}</p>
    @if($domain->last_error_message)
      <p style="margin-top: 8px; color: #F87171;">Error: {{ $domain->last_error_message }}</p>
    @endif
  </div>

  <div style="margin-top: 24px; padding: 18px; background: #131313; border: 1px solid #1f1f1f; border-radius: 12px;">
    <p style="font-weight: 600;">DNS records (preview)</p>
    <pre style="margin-top: 8px; font-size: 12px; color: #c8c8c8; white-space: pre-wrap;">TXT  {{ $domain->verificationRecordName() }}  {{ $domain->verificationRecordValue() }}
CNAME  {{ $domain->hostname }}  {{ $cnameTarget }}</pre>
  </div>
</div>
@endsection
