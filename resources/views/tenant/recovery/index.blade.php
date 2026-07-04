@extends('layouts.tenant.app')
@php $pageTitle = 'Recovery'; @endphp

@push('styles')
<style>
  .rec-intro { font-size: 13px; color: var(--ia-text-muted); margin-bottom: 18px; line-height: 1.5; max-width: 640px; }

  /* MARKER-PATCH-507 — subnav + settings tab */
  /* MARKER-PATCH-508 — underline tabs, same pattern as cl-subnav / cc-tabs */
  .rec-subnav{display:flex;gap:2px;margin-bottom:22px;border-bottom:0.5px solid var(--ia-border)}
  .rec-subnav a{font-size:13px;color:var(--ia-text-muted);padding:9px 14px;border-bottom:2px solid transparent;margin-bottom:-0.5px;text-decoration:none;transition:color var(--ia-t),border-color var(--ia-t)}
  .rec-subnav a:hover{color:var(--ia-text)}
  .rec-subnav a.on{color:var(--ia-text);border-bottom-color:var(--ia-accent)}
  .rs-dep{display:flex;gap:11px;align-items:flex-start;border:0.5px solid var(--ia-border);background:var(--ia-surface-2);border-radius:12px;padding:13px 15px;margin-bottom:18px;max-width:760px}
  .rs-dep .di{flex:none;font-size:15px;margin-top:1px;opacity:.7}
  .rs-dep .dt{font-size:12.5px}
  .rs-dep .dt b{font-weight:600}
  .rs-dep .dt span{display:block;color:var(--ia-text-muted);margin-top:2px;font-size:11.5px}
  .rs-group{border:0.5px solid var(--ia-border);border-radius:15px;background:var(--ia-surface);margin-bottom:18px;overflow:hidden;max-width:760px}
  .rs-gh{padding:15px 18px 14px;border-bottom:0.5px solid var(--ia-border)}
  .rs-gh .gt{font-size:14px;font-weight:600}
  .rs-gh .gs{font-size:12px;color:var(--ia-text-muted);margin-top:2px}
  .rs-gb{padding:6px 18px 14px}
  .rs-row{display:flex;align-items:center;gap:14px;padding:14px 0;border-bottom:0.5px solid var(--ia-border)}
  .rs-row:last-child{border-bottom:none}
  .rs-row .rl{flex:1}
  .rs-row .rt{font-size:13.5px;font-weight:500}
  .rs-row .rd{font-size:11.5px;color:var(--ia-text-dim,var(--ia-text-muted));margin-top:2px}
  .rs-row.off{opacity:.42}
  .rs-row .soon{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--ia-text-muted);border:0.5px solid var(--ia-border);border-radius:5px;padding:2px 8px;flex:none}
  .rs-inp{display:inline-flex;align-items:center;background:var(--ia-bg,rgba(0,0,0,.3));border:0.5px solid var(--ia-border);border-radius:8px;overflow:hidden;flex:none}
  .rs-inp input{width:44px;text-align:center;font-size:13px;font-weight:600;color:var(--ia-text);padding:7px 0;background:transparent;border:0;-moz-appearance:textfield}
  .rs-inp input::-webkit-outer-spin-button,.rs-inp input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
  .rs-inp input:focus{outline:none}
  .rs-inp .u{font-size:11.5px;color:var(--ia-text-muted);padding:0 10px 0 6px;border-left:0.5px solid var(--ia-border);align-self:stretch;display:flex;align-items:center}
  .rs-tog{appearance:none;-webkit-appearance:none;width:40px;height:23px;border-radius:99px;background:var(--ia-surface-2);border:0.5px solid var(--ia-border);position:relative;flex:none;cursor:pointer;transition:.15s;margin:0}
  .rs-tog::after{content:"";position:absolute;top:2px;left:2px;width:17px;height:17px;border-radius:50%;background:#8a8a88;transition:.15s}
  .rs-tog:checked{background:var(--ia-accent-soft);border-color:var(--ia-accent)}
  .rs-tog:checked::after{left:19px;background:var(--ia-accent)}
  .rs-seg{display:flex;gap:4px;background:var(--ia-bg,rgba(0,0,0,.3));border:0.5px solid var(--ia-border);border-radius:9px;padding:3px;flex:none}
  .rs-seg label{font-size:12px;font-weight:500;color:var(--ia-text-muted);padding:6px 12px;border-radius:6px;cursor:pointer}
  .rs-seg input{display:none}
  .rs-seg label:has(input:checked){background:var(--ia-accent-soft);color:var(--ia-accent)}
  .rs-savebar{display:flex;align-items:center;gap:12px;margin-top:22px;max-width:760px}
  .rs-savebar .note{margin-left:auto;font-size:11.5px;color:var(--ia-text-muted)}

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

  /* step drop-off */
  .rec-steps { display: flex; flex-direction: column; gap: 8px; }
  .rec-step { display: grid; grid-template-columns: 180px 1fr 44px 48px; align-items: center; gap: 12px; }
  .rec-step-label { font-size: 12px; color: var(--ia-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .rec-step-bar { height: 22px; background: var(--ia-surface-2); border: 0.5px solid var(--ia-border); border-radius: 6px; overflow: hidden; }
  .rec-step-fill { height: 100%; background: var(--ia-accent); opacity: .55; border-radius: 6px; transition: width .3s; }
  .rec-step-n { font-size: 13px; font-weight: 600; text-align: right; }
  .rec-step-drop { font-size: 11px; text-align: right; color: var(--ia-text-muted); }
  .rec-step-drop .is-cliff { color: #E0A23B; font-weight: 600; }

  @media (max-width: 700px) {
    .rec-row { grid-template-columns: 1fr; gap: 12px; }
    .rec-actions { flex-wrap: wrap; }
    .rec-btn { flex: 1; text-align: center; }
    .rec-stage-n { font-size: 22px; }
    .rec-arrow { min-width: 30px; }
    .rec-step { grid-template-columns: 96px 1fr 30px 38px; gap: 8px; }
    .rec-step-label { font-size: 11px; }
  }
</style>
@endpush

@section('content')

{{-- MARKER-PATCH-507 — Recovery / Settings subnav --}}
<div class="rec-subnav">
  <a href="{{ route('tenant.recovery.index') }}" class="{{ $tab === 'main' ? 'on' : '' }}">Recovery</a>
  <a href="{{ route('tenant.recovery.index', ['tab' => 'settings']) }}" class="{{ $tab === 'settings' ? 'on' : '' }}">Settings</a>
</div>

@if($tab === 'main')
{{-- MARKER-PATCH-484 — at-risk regulars --}}
@if(!empty($atRisk) && count($atRisk))
<div style="margin-bottom:26px">
  <h2 style="font-size:15px;font-weight:600;margin:0 0 4px">At-risk regulars</h2>
  <p style="font-size:12.5px;color:var(--ia-text-muted);margin:0 0 14px">Regulars overdue against their own visit rhythm. A flagged reason comes from their last experience.</p>
  @foreach($atRisk as $c)
    @php $flagged = !empty($c['reason']); @endphp
    <div style="border:1px solid {{ $flagged ? 'rgba(224,162,60,.4)' : 'var(--ia-border)' }};background:{{ $flagged ? 'rgba(224,162,60,.08)' : 'var(--ia-surface)' }};border-radius:12px;padding:14px 16px;margin-bottom:10px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
      <div style="flex:1;min-width:190px">
        <div style="font-size:14px;font-weight:600">{{ $c['name'] ?: 'Customer' }}</div>
        <div style="font-size:11.5px;color:var(--ia-text-muted);margin-top:2px">
          Normally every ~{{ $c['avg_gap_days'] }}d &middot; <b style="color:{{ $flagged ? '#e0a23c' : 'var(--ia-text)' }}">{{ $c['days_since'] }}d</b> since last visit &middot; {{ $c['visit_count'] }} visits
        </div>
        @if($flagged)
          <div style="font-size:12px;color:#e0a23c;margin-top:6px">&#8618; Likely why: {{ $c['reason'] }}</div>
        @endif
      </div>
      @if($c['phone'])<a href="tel:{{ $c['phone'] }}" style="font-size:12px;color:var(--ia-accent);text-decoration:none">{{ $c['phone'] }}</a>@endif
      <a href="{{ route('tenant.customers.show', $c['customer_id']) }}" class="ia-btn ia-btn--{{ $flagged ? 'primary' : 'secondary' }} ia-btn--sm">{{ $flagged ? 'Make it right' : 'Reach out' }}</a>
    </div>
  @endforeach
</div>
@endif

<p class="rec-intro">People who started a booking and left contact info before finishing. Reach out, then mark them done. The funnel counts anonymous sessions over the last 30 days.</p>

{{-- Funnel --}}
<div class="rec-funnel">
  <div class="rec-funnel-head">
    <h2>Booking funnel</h2>
    <span>Last 30 days</span>
  </div>
  {{-- MARKER-PATCH-488 — headline replaces the three redundant stage cards --}}
  <div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;padding:4px 0 2px">
    <div style="font-size:34px;font-weight:700;letter-spacing:-.02em;line-height:1">{{ $funnel['completed'] }}<span style="font-size:18px;color:var(--ia-text-muted);font-weight:500"> / {{ $funnel['viewed'] }}</span></div>
    <div style="font-size:12.5px;color:var(--ia-text-muted);flex:1;min-width:220px">completed &mdash; <strong style="color:var(--ia-accent)">{{ $funnel['pct_overall'] }}%</strong> of everyone who opened booking. The bars below show where the rest fall away.</div>
  </div>
  <div class="rec-funnel-foot">
    <strong>{{ $funnel['pct_overall'] }}%</strong> of people who opened booking completed it.
  </div>
</div>

{{-- Step-by-step drop-off --}}
@if(!empty($steps))
<div class="rec-funnel">
  <div class="rec-funnel-head">
    <h2>Where people drop off</h2>
    <span>Last 30 days</span>
  </div>
  <div class="rec-steps">
    @foreach($steps as $s)
      <div class="rec-step">
        <div class="rec-step-label">{{ $s['label'] }}</div>
        <div class="rec-step-bar"><div class="rec-step-fill" style="width: {{ $s['width'] }}%"></div></div>
        <div class="rec-step-n">{{ $s['sessions'] }}</div>
        <div class="rec-step-drop">@if($s['drop'] !== null)<span class="{{ $s['drop'] >= 40 ? 'is-cliff' : '' }}">&minus;{{ $s['drop'] }}%</span>@endif</div>
      </div>
    @endforeach
  </div>
</div>
@endif

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

@else
{{-- MARKER-PATCH-507 — settings tab (replaces the patch-486 inline form) --}}

<div class="rs-dep">
  <div class="di">&#9888;</div>
  <div class="dt"><b>Lateness is measured against what you told the customer.</b>
    <span>Appointments without a promised / ready-by time simply don't produce a signal — nothing breaks.</span>
  </div>
</div>

<form method="POST" action="{{ route('tenant.recovery.settings.update') }}">
  @csrf
  @method('PATCH')

  <div class="rs-group">
    <div class="rs-gh">
      <div class="gt">Quality signals</div>
      <div class="gs">Which slip-ups get logged to a customer's history — and how much grace before one counts.</div>
    </div>
    <div class="rs-gb">
      <div class="rs-row">
        <div class="rl"><div class="rt">Late completion</div><div class="rd">Work finished after the promised time</div></div>
        <div class="rs-inp">
          <input type="number" name="recovery_late_completion_grace_days" min="0" max="60" value="{{ $recoverySettings['grace_days'] }}">
          <span class="u">day{{ $recoverySettings['grace_days'] === 1 ? '' : 's' }} grace</span>
        </div>
        <input type="checkbox" class="rs-tog" name="recovery_signal_late_completion" value="1" @checked($recoverySettings['sig_late'])>
      </div>
      <div class="rs-row">
        <div class="rl"><div class="rt">Reschedule</div><div class="rd">You moved the appointment on the customer</div></div>
        <input type="checkbox" class="rs-tog" name="recovery_signal_reschedule" value="1" @checked($recoverySettings['sig_reschedule'])>
      </div>
      {{-- MARKER-PATCH-530 — live with pickup & delivery --}}
      <div class="rs-row">
        <div class="rl"><div class="rt">Late delivery</div><div class="rd">Drop-off happened after the delivery window</div></div>
        <input type="checkbox" class="rs-tog" name="recovery_signal_late_delivery" value="1" @checked($recoverySettings['sig_late_delivery'])>
      </div>
      <div class="rs-row off">
        <div class="rl"><div class="rt">Special-order delay</div><div class="rd">Part arrived later than the quoted ETA</div></div>
        <span class="soon">Planned</span>
      </div>
    </div>
  </div>

  <div class="rs-group">
    <div class="rs-gh">
      <div class="gt">At-risk detector</div>
      <div class="gs">When a regular counts as overdue — measured against their own visit rhythm, not a fixed number.</div>
    </div>
    <div class="rs-gb">
      <div class="rs-row">
        <div class="rl"><div class="rt">Flag when overdue by</div><div class="rd">Past their normal gap between visits</div></div>
        <div class="rs-seg">
          @foreach(['1.25' => '1.25&times;', '1.5' => '1.5&times;', '2' => '2&times;'] as $val => $label)
            <label><input type="radio" name="recovery_overdue_buffer" value="{{ $val }}"
              @checked((string) $recoverySettings['overdue_buffer'] === $val || (float) $recoverySettings['overdue_buffer'] === (float) $val)>{!! $label !!}</label>
          @endforeach
        </div>
      </div>
      <div class="rs-row">
        <div class="rl"><div class="rt">Trust cadence after</div><div class="rd">Minimum visits before we assume a rhythm</div></div>
        <div class="rs-inp">
          <input type="number" name="recovery_min_visits" min="2" max="20" value="{{ $recoverySettings['min_visits'] }}">
          <span class="u">visits</span>
        </div>
      </div>
      <div class="rs-row">
        <div class="rl"><div class="rt">Prioritize flagged reasons</div><div class="rd">Sort at-risk regulars with a known issue to the top</div></div>
        <input type="checkbox" class="rs-tog" name="recovery_prioritize_flagged" value="1" @checked($recoverySettings['prioritize'])>
      </div>
    </div>
  </div>

  <div class="rs-savebar">
    <button type="submit" class="ia-btn ia-btn--primary">Save settings</button>
    <a href="{{ route('tenant.recovery.index') }}" class="ia-btn ia-btn--ghost">Cancel</a>
    <span class="note">Shown with sensible defaults — works untouched.</span>
  </div>
</form>
@endif

@endsection
