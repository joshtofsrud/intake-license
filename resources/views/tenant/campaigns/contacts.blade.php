@extends('layouts.tenant.app')
@php $pageTitle = 'Contacts & consent'; @endphp

{{-- MARKER-CONSENT-SURFACES --}}
@push('styles')
<style>
  .cn-wrap{max-width:860px}
  .cn-crumb{color:var(--ia-text-3,#74747a);font-size:12.5px;margin-bottom:14px}
  .cn-crumb a{color:var(--ia-text-2,#a6a6ac);text-decoration:none}
  .cn-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px}
  .cn-card{background:var(--ia-surface,#161619);border:1px solid var(--ia-border,#2a2a2e);border-radius:13px;padding:16px 18px}
  .cn-num{font-size:24px;font-weight:740;color:var(--ia-text,#f4f4f5)}
  .cn-lbl{font-size:12px;color:var(--ia-text-3,#74747a);margin-top:3px}
  .cn-sec{background:var(--ia-surface,#161619);border:1px solid var(--ia-border,#2a2a2e);border-radius:13px;padding:18px 20px;margin-bottom:16px}
  .cn-sec h2{font-size:14.5px;font-weight:660;margin:0 0 8px;color:var(--ia-text,#f4f4f5)}
  .cn-sec p{font-size:13px;line-height:1.6;color:var(--ia-text-2,#a6a6ac);margin:0 0 10px}
  .cn-word{background:var(--ia-bg,#0f0f11);border:1px solid var(--ia-border,#2a2a2e);border-radius:9px;padding:12px 14px;font-size:12.5px;line-height:1.6;color:var(--ia-text-2,#a6a6ac);margin-bottom:12px}
  .cn-btn{appearance:none;border:none;cursor:pointer;font:inherit;font-weight:640;background:var(--ia-accent,#e0a82e);color:#141414;border-radius:9px;padding:10px 18px}
  .cn-check{display:flex;gap:8px;align-items:flex-start;font-size:13px;color:var(--ia-text-2,#a6a6ac);margin-bottom:12px}
  .cn-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
  .cn-tbl th{text-align:left;color:var(--ia-text-3,#74747a);font-weight:500;padding:6px 8px;border-bottom:1px solid var(--ia-border,#2a2a2e)}
  .cn-tbl td{padding:8px;border-bottom:1px solid var(--ia-border,#2a2a2e);color:var(--ia-text-2,#a6a6ac)}
  .cn-note{font-size:12.5px;color:var(--ia-text-3,#74747a)}
  .cn-flash{border:1px solid rgba(120,200,120,.4);border-radius:11px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:var(--ia-text-2,#a6a6ac)}
</style>
@endpush

@section('content')
<div class="cn-wrap">
  <div class="cn-crumb"><a href="{{ route('tenant.campaigns.index') }}">Campaigns</a> → Contacts &amp; consent</div>

  @if(session('success'))<div class="cn-flash">{{ session('success') }}</div>@endif

  <div class="cn-cards">
    <div class="cn-card"><div class="cn-num">{{ number_format($counts['mailable']) }}</div><div class="cn-lbl">Can receive campaigns</div></div>
    <div class="cn-card"><div class="cn-num">{{ number_format($counts['unconfirmed']) }}</div><div class="cn-lbl">Unconfirmed — never emailed marketing</div></div>
    <div class="cn-card"><div class="cn-num">{{ number_format($counts['unsubscribed']) }}</div><div class="cn-lbl">Unsubscribed — receipts still send</div></div>
  </div>

  {{-- Legend: what these states DO, since the effect is invisible here --}}
  <div class="cn-sec">
    <h2>How this works</h2>
    <p>Campaigns only ever go to the first group. Customers join it by opting in
    during booking or checkout, from their account page, by asking your staff, or
    when a manager confirms permission below. An unsubscribe stops marketing only —
    receipts and booking confirmations always send. Dead and complained addresses
    are blocked for everything on the
    <a href="{{ route('tenant.suppressions.index') }}" style="color:var(--ia-text-2,#a6a6ac)">suppression list</a>.</p>
  </div>

  @if($counts['unconfirmed'] > 0)
  <div class="cn-sec">
    <h2>Confirm permission for {{ number_format($counts['unconfirmed']) }} unconfirmed contact(s)</h2>
    <p>These are customers with email addresses — usually imported or added at the
    counter — who haven't opted in through any channel yet. If your business has
    their permission, confirm it here. This is recorded: what you agreed to, who
    confirmed, when, and from where.</p>
    <div class="cn-word">{{ $attestWording }}</div>
    <form method="POST" action="{{ route('tenant.consent.attest') }}"
      onsubmit="return confirm('Record permission for all unconfirmed contacts? This is logged with your name.');">
      @csrf
      <label class="cn-check">
        <input type="checkbox" name="confirm" value="1" required>
        <span>I've read the statement above and it's true for these contacts.</span>
      </label>
      <button type="submit" class="cn-btn">Confirm permission</button>
    </form>
  </div>
  @endif

  <div class="cn-sec">
    <h2>Permission record</h2>
    @if($attestations->isEmpty())
      <p class="cn-note">No confirmations recorded yet.</p>
    @else
      <table class="cn-tbl">
        <thead><tr><th>Contacts</th><th>Confirmed by</th><th>When</th></tr></thead>
        <tbody>
        @foreach($attestations as $a)
          <tr>
            <td>{{ number_format($a->contact_count) }}</td>
            <td>{{ $a->confirmed_by_name }}@if($a->confirmed_by_role) ({{ $a->confirmed_by_role }})@endif</td>
            <td>{{ $a->created_at->format('M j, Y g:ia') }}@if($a->ip) · {{ $a->ip }}@endif</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>
@endsection
