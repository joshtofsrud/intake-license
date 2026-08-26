@extends('layouts.tenant.app')
@php $pageTitle = 'Campaign results'; @endphp

{{-- MARKER-CAMPAIGN-RESULTS --}}
@push('styles')
<style>
  .cr-wrap{max-width:900px}
  .cr-crumb{color:var(--ia-text-3,#74747a);font-size:12.5px;margin-bottom:14px}
  .cr-crumb a{color:var(--ia-text-2,#a6a6ac);text-decoration:none}
  .cr-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
  .cr-card{background:var(--ia-surface,#161619);border:1px solid var(--ia-border,#2a2a2e);border-radius:12px;padding:14px 16px}
  .cr-num{font-size:21px;font-weight:740;color:var(--ia-text,#f4f4f5)}
  .cr-lbl{font-size:11.5px;color:var(--ia-text-3,#74747a);margin-top:2px}
  .cr-note{background:var(--ia-surface,#161619);border:1px solid var(--ia-border,#2a2a2e);border-radius:11px;padding:10px 14px;margin-bottom:16px;color:var(--ia-text-2,#a6a6ac);font-size:12.5px;line-height:1.6}
  .cr-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
  .cr-tbl th{text-align:left;color:var(--ia-text-3,#74747a);font-weight:500;padding:7px 8px;border-bottom:1px solid var(--ia-border,#2a2a2e)}
  .cr-tbl td{padding:8px;border-bottom:1px solid var(--ia-border,#2a2a2e);color:var(--ia-text-2,#a6a6ac)}
  .cr-s{font-size:11px;padding:2px 8px;border-radius:20px;border:1px solid var(--ia-border,#2a2a2e)}
</style>
@endpush

@section('content')
<div class="cr-wrap">
  <div class="cr-crumb"><a href="{{ route('tenant.campaigns.index') }}">Campaigns</a> → <a href="{{ route('tenant.campaigns.show', $campaign->id) }}">{{ $campaign->name }}</a> → Results</div>

  <div class="cr-cards">
    <div class="cr-card"><div class="cr-num">{{ number_format($summary['sent']) }}</div><div class="cr-lbl">Delivered</div></div>
    <div class="cr-card"><div class="cr-num">{{ number_format($summary['opened']) }}</div><div class="cr-lbl">Opened</div></div>
    <div class="cr-card"><div class="cr-num">{{ number_format($summary['clicked']) }}</div><div class="cr-lbl">Clicked</div></div>
    <div class="cr-card"><div class="cr-num">{{ number_format($summary['skipped'] + $summary['failed'] + $summary['bounced']) }}</div><div class="cr-lbl">Not delivered</div></div>
  </div>

  {{-- Legend: opens depend on stream settings and on the recipient's mail app,
       so a zero here is not necessarily a zero in reality. --}}
  <div class="cr-note">
    <strong style="color:var(--ia-text,#f4f4f5)">Reading these numbers.</strong>
    Opens only count if open tracking is switched on for the broadcast stream in
    Postmark — and even then, mail apps that block images hide many real opens, so
    treat opened as a floor, not a measurement. Clicks are reliable. <em>Skipped</em>
    means the person lost marketing permission or was suppressed between the send
    starting and their turn coming up; <em>bounced</em> means the address rejected it.
  </div>

  @if($summary['pending'] > 0)
    <div class="cr-note">Still sending — {{ number_format($summary['pending']) }} to go. This page updates as they complete.</div>
  @endif

  <table class="cr-tbl">
    <thead><tr><th>Recipient</th><th>Status</th><th>Opened</th><th>Clicked</th><th>Note</th></tr></thead>
    <tbody>
    @forelse($rows as $r)
      <tr>
        <td>{{ $r->email }}</td>
        <td><span class="cr-s">{{ ucfirst($r->status) }}</span></td>
        <td>{{ $r->opened_at ? $r->opened_at->format('M j g:ia') : '—' }}</td>
        <td>{{ $r->clicked_at ? $r->clicked_at->format('M j g:ia') : '—' }}</td>
        <td>{{ $r->error_message ?: '—' }}</td>
      </tr>
    @empty
      <tr><td colspan="5" style="opacity:.55">No recipients yet.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endsection
