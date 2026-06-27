@extends('layouts.tenant.app')
@php $pageTitle = 'Recovery'; @endphp

@push('styles')
<style>
  .rec-intro { font-size: 13px; color: var(--ia-text-muted); margin-bottom: 18px; line-height: 1.5; max-width: 640px; }

  /* Funnel card */
  .rec-funnel {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: var(--ia-r-lg);
    padding: 20px 22px;
    margin-bottom: 28px;
  }
  .rec-funnel-head {
    display: flex; align-items: baseline; justify-content: space-between;
    margin-bottom: 18px;
  }
  .rec-funnel-head h2 { font-size: 14px; font-weight: 600; margin: 0; }
  .rec-funnel-head span { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; opacity: .45; }
  .rec-stages { display: flex; align-items: stretch; gap: 8px; }
  .rec-stage {
    flex: 1; text-align: center;
    background: var(--ia-surface-2);
    border: 0.5px solid var(--ia-border);
    border-radius: var(--ia-r-md);
    padding: 16px 8px;
  }
  .rec-stage-n { font-size: 28px; font-weight: 700; line-height: 1; }
  .rec-stage-l { font-size: 11px; color: var(--ia-text-muted); margin-top: 6px; }
  .rec-arrow {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    min-width: 52px; gap: 2px;
  }
  .rec-arrow-glyph { font-size: 16px; opacity: .35; line-height: 1; }
  .rec-arrow-pct { font-size: 12px; font-weight: 600; color: var(--ia-accent); }
  .rec-funnel-foot { font-size: 12px; color: var(--ia-text-muted); margin-top: 16px; }
  .rec-funnel-foot strong { color: var(--ia-text); }

  /* Section head */
  .rec-sec-head {
    display: flex; align-items: baseline; justify-content: space-between;
    margin: 0 0 12px;
  }
  .rec-sec-head h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .08em; font-weight: 600; opacity: .55; margin: 0; }
  .rec-sec-head .rec-count { font-size: 12px; opacity: .4; }

  /* Worklist rows */
  .rec-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 30px; }
  .rec-row {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: var(--ia-r-lg);
    padding: 16px 18px;
    display: grid;
    grid-template-columns: 1fr auto auto;
    gap: 18px;
    align-items: center;
  }
  .rec-name { font-size: 14px; font-weight: 600; }
  .rec-meta { font-size: 12px; color: var(--ia-text-muted); margin-top: 3px; }
  .rec-contact { font-size: 12px; color: var(--ia-text-muted); margin-top: 4px; word-break: break-word; }

  /* contact tiles */
  .rec-tiles { display: flex; gap: 6px; }
  .rec-tile {
    display: flex; flex-direction: column; align-items: center; gap: 3px;
    width: 52px; padding: 8px 4px;
    background: var(--ia-surface-2); border: 0.5px solid var(--ia-border);
    border-radius: var(--ia-r-md); color: var(--ia-text); text-decoration: none;
    -webkit-tap-highlight-color: transparent;
  }
  .rec-tile svg { color: var(--ia-accent); }
  .rec-tile:active { transform: scale(0.97); }
  .rec-tile-l { font-size: 10px; color: var(--ia-text-muted); }
  .rec-tile.is-disabled { opacity: .3; pointer-events: none; }
  .rec-tile.is-disabled svg { color: var(--ia-text-muted); }

  /* actions */
  .rec-actions { display: flex; gap: 6px; }
  .rec-btn {
    appearance: none; -webkit-appearance: none;
    background: var(--ia-surface-2); border: 0.5px solid var(--ia-border);
    border-radius: var(--ia-r-md); color: var(--ia-text);
    font-family: inherit; font-size: 12px; font-weight: 500;
    padding: 8px 12px; cursor: pointer; white-space: nowrap;
  }
  .rec-btn:hover { border-color: var(--ia-accent); }
  .rec-btn--good { color: var(--ia-accent); }
  .rec-btn--ghost { opacity: .6; }

  .rec-empty {
    background: var(--ia-surface); border: 0.5px dashed var(--ia-border);
    border-radius: var(--ia-r-lg); padding: 32px; text-align: center;
    color: var(--ia-text-muted); font-size: 13px;
  }

  /* handled (collapsed list) */
  .rec-handled-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px; border-bottom: 0.5px solid var(--ia-border);
    font-size: 12px; color: var(--ia-text-muted);
  }
  .rec-handled-row:last-child { border-bottom: none; }
  .rec-pill { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; padding: 2px 7px; border-radius: 999px; border: 0.5px solid var(--ia-border); }

  @media (max-width: 700px) {
    .rec-row { grid-template-columns: 1fr; gap: 12px; }
    .rec-actions { flex-wrap: wrap; }
    .rec-btn { flex: 1; text-align: center; }
    .rec-stage-n { font-size: 22px; }
    .rec-arrow { min-width: 30px; }
  }
</style>
@endpush

<p class="rec-intro">People who started a booking and left contact info before finishing. Reach out, then mark them done. The funnel counts anonymous sessions over the last 30 days.</p>

{{-- Funnel --}}
<div class="rec-funnel">
  <div class="rec-funnel-head">
    <h2>Booking funnel</h2>
    <span>Last 30 days</span>
  </div>
  <div class="rec-stages">
    <div class="rec-stage">
      <div class="rec-stage-n">{{ $funnel['viewed'] }}</div>
      <div class="rec-stage-l">Opened booking</div>
    </div>
    <div class="rec-arrow">
      <div class="rec-arrow-glyph">&rarr;</div>
      <div class="rec-arrow-pct">{{ $funnel['pct_start'] }}%</div>
    </div>
    <div class="rec-stage">
      <div class="rec-stage-n">{{ $funnel['started'] }}</div>
      <div class="rec-stage-l">Started</div>
    </div>
    <div class="rec-arrow">
      <div class="rec-arrow-glyph">&rarr;</div>
      <div class="rec-arrow-pct">{{ $funnel['pct_finish'] }}%</div>
    </div>
    <div class="rec-stage">
      <div class="rec-stage-n">{{ $funnel['completed'] }}</div>
      <div class="rec-stage-l">Completed</div>
    </div>
  </div>
  <div class="rec-funnel-foot">
    <strong>{{ $funnel['pct_overall'] }}%</strong> of people who opened booking completed it.
  </div>
</div>

{{-- Worklist --}}
<div class="rec-sec-head">
  <h2>Didn't finish</h2>
  <span class="rec-count">{{ $open->count() }} to follow up</span>
</div>

@if($open->isEmpty())
  <div class="rec-empty">No abandoned bookings to follow up right now.</div>
@else
  <div class="rec-list">
    @foreach($open as $row)
      @php
        $phone = $row->phone ? preg_replace('/[^0-9+]/', '', $row->phone) : '';
      @endphp
      <div class="rec-row">
        <div class="rec-row-main">
          <div class="rec-name">{{ $row->name ?: 'Someone' }}</div>
          <div class="rec-meta">{{ $row->step_reached ? 'Left at ' . $row->step_reached : 'Left mid-booking' }}@if($row->created_at) &middot; {{ $row->created_at->diffForHumans() }}@endif</div>
          @if($row->email || $row->phone)
            <div class="rec-contact">{{ $row->email }}@if($row->email && $row->phone) &middot; @endif{{ $row->phone }}</div>
          @endif
        </div>

        <div class="rec-tiles">
          <a href="{{ $phone ? 'tel:' . $phone : '#' }}" class="rec-tile {{ $phone ? '' : 'is-disabled' }}">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            <span class="rec-tile-l">Call</span>
          </a>
          <a href="{{ $phone ? 'sms:' . $phone : '#' }}" class="rec-tile {{ $phone ? '' : 'is-disabled' }}">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <span class="rec-tile-l">Text</span>
          </a>
          <a href="{{ $row->email ? 'mailto:' . $row->email : '#' }}" class="rec-tile {{ $row->email ? '' : 'is-disabled' }}">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <span class="rec-tile-l">Email</span>
          </a>
        </div>

        <form method="POST" action="{{ route('tenant.recovery.update', $row->id) }}" class="rec-actions">
          @csrf
          @method('PATCH')
          <button type="submit" name="status" value="contacted" class="rec-btn">Contacted</button>
          <button type="submit" name="status" value="converted" class="rec-btn rec-btn--good">Converted</button>
          <button type="submit" name="status" value="dismissed" class="rec-btn rec-btn--ghost">Dismiss</button>
        </form>
      </div>
    @endforeach
  </div>
@endif

{{-- Recently handled --}}
@if($handled->isNotEmpty())
  <div class="rec-sec-head">
    <h2>Recently handled</h2>
  </div>
  <div class="rec-funnel" style="padding:6px 0">
    @foreach($handled as $row)
      <div class="rec-handled-row">
        <span>{{ $row->name ?: ($row->email ?: ($row->phone ?: 'Someone')) }}</span>
        <span class="rec-pill">{{ $row->status }}</span>
      </div>
    @endforeach
  </div>
@endif
