@extends('layouts.tenant.app')
@php $pageTitle = 'Class Schedule'; @endphp

@push('styles')
<style>
.cl-subnav{display:flex;gap:2px;margin-bottom:20px;border-bottom:0.5px solid var(--ia-border)}
.cl-subnav-tab{padding:9px 14px;font-size:13px;color:var(--ia-text-muted);border-bottom:2px solid transparent;margin-bottom:-0.5px;cursor:pointer;background:none;border-left:none;border-right:none;border-top:none;text-decoration:none;transition:color var(--ia-t),border-color var(--ia-t)}
.cl-subnav-tab:hover{color:var(--ia-text)}
.cl-subnav-tab.is-active{color:var(--ia-text);border-bottom-color:var(--ia-accent);font-weight:500}
.cl-week-nav{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.cl-week-label{font-size:14px;font-weight:500;color:var(--ia-text);min-width:200px;text-align:center}
.cl-week-btn{width:30px;height:30px;border-radius:6px;background:var(--ia-surface);border:0.5px solid var(--ia-border);color:var(--ia-text-muted);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:all var(--ia-t);text-decoration:none}
.cl-week-btn:hover{background:var(--ia-hover);color:var(--ia-text)}
.cl-session-grid{display:flex;flex-direction:column;gap:8px}
.cl-session-card{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
.cl-session-head{display:grid;grid-template-columns:120px 1fr auto auto auto;gap:14px;align-items:center;padding:13px 16px;cursor:pointer;transition:background var(--ia-t)}
.cl-session-head:hover{background:var(--ia-hover)}
.cl-session-time{font-size:13px;font-weight:500;color:var(--ia-text);font-variant-numeric:tabular-nums}
.cl-session-date{font-size:11px;color:var(--ia-text-muted);margin-top:1px}
.cl-session-name{font-size:14px;font-weight:500;color:var(--ia-text)}
.cl-session-instructor{font-size:12px;color:var(--ia-text-muted);margin-top:2px}
.cl-capacity-bar-wrap{display:flex;align-items:center;gap:8px;min-width:120px}
.cl-capacity-bar{flex:1;height:4px;background:var(--ia-border);border-radius:2px;overflow:hidden}
.cl-capacity-fill{height:100%;background:var(--ia-accent);border-radius:2px;transition:width .3s}
.cl-capacity-fill.is-full{background:#EF4444}
.cl-capacity-text{font-size:12px;color:var(--ia-text-muted);white-space:nowrap;font-variant-numeric:tabular-nums}
.cl-status-pill{display:inline-flex;align-items:center;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:500;white-space:nowrap}
.cl-status-pill.scheduled{background:var(--ia-surface-2);color:var(--ia-text-muted)}
.cl-status-pill.confirmed{background:var(--ia-accent-soft);color:var(--ia-accent)}
.cl-status-pill.cancelled{background:rgba(239,68,68,.1);color:#EF4444}
.cl-status-pill.completed{background:var(--ia-surface-2);color:var(--ia-text-muted)}
.cl-session-body{border-top:0.5px solid var(--ia-border);padding:16px;display:none;background:var(--ia-surface-2)}
.cl-session-card.is-open .cl-session-body{display:block}
.cl-session-actions{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center}
.cl-reg-table{width:100%;border-collapse:collapse;font-size:13px}
.cl-reg-table th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;padding:6px 10px;border-bottom:0.5px solid var(--ia-border)}
.cl-reg-table td{padding:9px 10px;border-bottom:0.5px solid var(--ia-border);color:var(--ia-text)}
.cl-reg-table tr:last-child td{border-bottom:none}
.cl-reg-table tr:hover td{background:var(--ia-hover)}
.cl-waitlist-label{display:inline-flex;align-items:center;padding:2px 7px;border-radius:20px;font-size:10px;font-weight:500;background:rgba(239,68,68,.1);color:#EF4444;margin-left:6px}
.cl-add-reg-row{display:grid;grid-template-columns:1fr 160px auto;gap:8px;align-items:end;margin-top:14px;padding-top:14px;border-top:0.5px solid var(--ia-border)}
.cl-input,.cl-select{padding:8px 11px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;outline:none;transition:border var(--ia-t);width:100%;font-family:inherit}
.cl-input:focus,.cl-select:focus{border-color:var(--ia-accent)}
.cl-select{appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10' fill='none' stroke='rgba(255,255,255,.4)'><path d='M2 4l3 3 3-3' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/></svg>");background-repeat:no-repeat;background-position:right 10px center;padding-right:28px}
.cl-empty-week{padding:48px;text-align:center;background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg)}
.cl-empty-week-title{font-size:15px;font-weight:500;color:var(--ia-text);margin-bottom:6px}
.cl-empty-week-body{font-size:13px;color:var(--ia-text-muted)}
.cl-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:400;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .15s}
.cl-modal-overlay.is-open{opacity:1;pointer-events:all}
.cl-modal{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);width:100%;max-width:520px;padding:24px;max-height:90vh;overflow-y:auto}
.cl-modal-title{font-size:15px;font-weight:600;margin-bottom:18px;color:var(--ia-text)}
.cl-field{margin-bottom:14px}
.cl-label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-muted);font-weight:500;margin-bottom:5px}
.cl-modal-footer{display:flex;justify-content:flex-end;gap:8px;margin-top:20px;padding-top:16px;border-top:0.5px solid var(--ia-border)}
.cl-action-btn{width:28px;height:28px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;color:var(--ia-text-muted);background:none;border:none;cursor:pointer;transition:all var(--ia-t)}
.cl-action-btn:hover{background:var(--ia-hover);color:var(--ia-text)}
.cl-field-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
/* Mini calendar */
.cl-cal{background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:8px;padding:10px}
.cl-cal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.cl-cal-title{font-size:13px;font-weight:600}
.cl-cal-nav{background:transparent;border:0.5px solid var(--ia-border);border-radius:5px;width:26px;height:26px;cursor:pointer;color:var(--ia-text-muted);font-size:14px;line-height:1;font-family:inherit;display:flex;align-items:center;justify-content:center}
.cl-cal-nav:hover{color:var(--ia-text);border-color:var(--ia-border-strong)}
.cl-cal-dows{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;margin-bottom:4px;font-size:9.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-muted);font-weight:600;text-align:center}
.cl-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}
.cl-cal-cell{aspect-ratio:1;display:flex;align-items:center;justify-content:center;font-size:12px;border-radius:4px;cursor:pointer;user-select:none;background:transparent;border:none;color:var(--ia-text-muted);font-family:inherit;transition:background var(--ia-t),color var(--ia-t)}
.cl-cal-cell:hover:not(:disabled){background:var(--ia-hover);color:var(--ia-text)}
.cl-cal-cell:disabled{opacity:.3;cursor:not-allowed}
.cl-cal-cell.is-today{outline:0.5px solid var(--ia-accent);outline-offset:-1px}
.cl-cal-cell.is-selected{background:var(--ia-accent);color:var(--ia-accent-text);font-weight:600}
.cl-cal-cell.is-selected:hover{filter:brightness(.92)}
.cl-cal-cell.is-empty{cursor:default;pointer-events:none}
/* Recurrence */
.cl-repeat-tabs{display:flex;gap:4px;margin-bottom:14px}
.cl-repeat-tab{padding:5px 12px;border-radius:6px;font-size:12px;border:0.5px solid var(--ia-border);background:transparent;color:var(--ia-text-muted);cursor:pointer;transition:all var(--ia-t)}
.cl-repeat-tab.active{background:var(--ia-accent-soft);color:var(--ia-accent);border-color:var(--ia-accent)}
.cl-dow-btns{display:flex;gap:4px;flex-wrap:wrap}
.cl-dow-btn{width:34px;height:34px;border-radius:6px;border:0.5px solid var(--ia-border);background:transparent;color:var(--ia-text-muted);font-size:12px;cursor:pointer;transition:all var(--ia-t);font-family:inherit}
.cl-dow-btn.active{background:var(--ia-accent);color:var(--ia-accent-text);border-color:var(--ia-accent)}
.cl-repeat-section{display:none}
.cl-repeat-section.active{display:block}
.cl-session-preview{font-size:12px;color:var(--ia-text-muted);margin-top:8px;padding:8px 10px;background:var(--ia-surface-2);border-radius:6px;min-height:32px}

/* Schedule list — mobile parallel render (patch #34).
   Desktop expand-on-tap stays. Mobile tap opens full detail (no inline
   expand, which would be redundant with the polish in patch #33). */
.cl-sched-mobile{display:none}
.cl-sched-week-nav-m{display:none}
.cl-sched-day-group{margin-bottom:18px}
.cl-sched-day-label{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:600;padding:0 4px 6px}
.cl-sched-day-label.is-today{color:var(--ia-accent)}
.cl-sess-card-m{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:12px;padding:14px;margin-bottom:8px;display:flex;flex-direction:column;gap:10px;text-decoration:none;color:inherit;transition:background var(--ia-t)}
.cl-sess-card-m:hover{background:var(--ia-hover)}
.cl-sess-top-m{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.cl-sess-left-m{min-width:0;flex:1}
.cl-sess-time-m{font-size:13px;font-weight:600;color:var(--ia-text);font-variant-numeric:tabular-nums}
.cl-sess-name-m{font-size:15px;font-weight:500;color:var(--ia-text);margin-top:2px;line-height:1.25}
.cl-sess-meta-m{font-size:12px;color:var(--ia-text-muted);margin-top:3px;line-height:1.4;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cl-sess-right-m{display:flex;align-items:flex-start;flex-shrink:0}
.cl-sess-capacity-row-m{display:flex;align-items:center;gap:8px}
.cl-sess-capacity-bar-m{flex:1;height:5px;background:var(--ia-surface-2);border-radius:3px;overflow:hidden}
.cl-sess-capacity-fill-m{height:100%;background:var(--ia-accent);border-radius:3px}
.cl-sess-capacity-fill-m.is-full{background:#EF4444}
.cl-sess-capacity-text-m{font-size:12px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums;flex-shrink:0;min-width:44px;text-align:right}
.cl-sess-waitlist-m{display:inline-flex;align-items:center;padding:2px 7px;border-radius:20px;font-size:10px;font-weight:600;background:rgba(239,68,68,.12);color:#EF4444;flex-shrink:0}
@media(max-width:640px){
  .cl-session-grid{display:none}
  .cl-sched-mobile{display:block}
  /* Compact desktop week-nav too; the desktop one has padding/min-width
     hard-coded that overflows on phones. */
  .cl-week-nav{flex-wrap:wrap;gap:6px}
  .cl-week-label{min-width:0;flex:1}
}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Classes</h1>
    <p class="ia-page-subtitle">Schedule and manage individual class sessions.</p>
  </div>
  <div class="ia-page-head-right">
    <button class="ia-btn ia-btn--primary" onclick="openAddModal()">+ New session</button>
  </div>
</div>

<x-tenant.schedule-tabs active="classes" />

<div class="cl-subnav-wrap"><nav class="cl-subnav">
  <a href="{{ route('tenant.classes.templates') }}" class="cl-subnav-tab">Templates</a>
  <a href="{{ route('tenant.classes.sessions') }}" class="cl-subnav-tab is-active">Schedule</a>
  <a href="{{ route('tenant.classes.memberships') }}" class="cl-subnav-tab">Memberships</a>
  <a href="{{ route('tenant.classes.packs') }}" class="cl-subnav-tab">Packs</a>
  <a href="{{ route('tenant.classes.reports') }}" class="cl-subnav-tab">Reports</a>
</nav></div>


@php
  $sub = request()->route('subdomain');
  $prevFrom = $from->copy()->subDays(7)->format('Y-m-d');
  $nextFrom = $from->copy()->addDays(7)->format('Y-m-d');
@endphp

<div class="cl-week-nav">
  <a href="{{ request()->fullUrlWithQuery(['from' => $prevFrom]) }}" class="cl-week-btn">
    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M8 2L4 6l4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
  </a>
  <div class="cl-week-label">{{ $from->format('M j') }} – {{ $to->format('M j, Y') }}</div>
  <a href="{{ request()->fullUrlWithQuery(['from' => $nextFrom]) }}" class="cl-week-btn">
    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M4 2l4 4-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
  </a>
  <a href="{{ request()->fullUrlWithQuery(['from' => now()->startOfWeek()->format('Y-m-d')]) }}" class="ia-btn ia-btn--ghost" style="margin-left:4px;font-size:12px;padding:5px 10px">Today</a>
</div>

@if($sessions->isEmpty())
  <div class="cl-empty-week">
    <div class="cl-empty-week-title">No sessions this week</div>
    <div class="cl-empty-week-body">Add a session above or <a href="{{ route('tenant.classes.templates') }}" style="color:var(--ia-accent)">create a template</a> first.</div>
  </div>
@else
  <div class="cl-session-grid">
    @foreach($sessions as $session)
      @php
        $pct = $session->capacity_snapshot > 0
          ? min(100, round(($session->active_registrations_count / $session->capacity_snapshot) * 100))
          : 0;
        $isFull    = $pct >= 100;
        $updateUrl = route('tenant.classes.sessions.update', ['subdomain' => $sub, 'id' => $session->id]);
        $showUrl   = route('tenant.classes.sessions.show',   ['subdomain' => $sub, 'id' => $session->id]);
      @endphp
      <div class="cl-session-card" id="session-{{ $session->id }}">
        <div class="cl-session-head" onclick="toggleSession('{{ $session->id }}')">
          <div>
            <div class="cl-session-time">{{ $session->starts_at->format('g:i A') }}</div>
            <div class="cl-session-date">{{ $session->starts_at->format('D, M j') }}</div>
          </div>
          <div>
            <div class="cl-session-name">{{ $session->template->name }}</div>
            <div class="cl-session-instructor">{{ $session->instructor_snapshot ?? 'No instructor' }} · {{ $session->template->duration_minutes }}min</div>
          </div>
          <div class="cl-capacity-bar-wrap">
            <div class="cl-capacity-bar">
              <div class="cl-capacity-fill {{ $isFull ? 'is-full' : '' }}" style="width:{{ $pct }}%"></div>
            </div>
            <span class="cl-capacity-text">{{ $session->active_registrations_count }}/{{ $session->capacity_snapshot }}</span>
          </div>
          @if($session->waitlist_count > 0)
            <span class="cl-waitlist-label">+{{ $session->waitlist_count }} waitlist</span>
          @endif
          <span class="cl-status-pill {{ $session->status }}">{{ ucfirst($session->status) }}</span>
        </div>

        <div class="cl-session-body">
          <div class="cl-session-actions">
            @if(!in_array($session->status, ['cancelled','completed']))
              <button type="button" class="ia-btn ia-btn--ghost" style="font-size:12px;padding:5px 12px;color:#EF4444" onclick="confirmCancel('{{ $updateUrl }}')">
                Cancel session
              </button>
            @endif
            <a href="{{ $showUrl }}" class="ia-btn ia-btn--ghost" style="font-size:12px;padding:5px 12px">Full detail →</a>
          </div>

          @if($session->active_registrations_count > 0)
            <table class="cl-reg-table">
              <thead>
                <tr><th>Customer</th><th>Payment</th><th>Status</th><th></th></tr>
              </thead>
              <tbody>
                @foreach($session->registrations->whereIn('status', ['registered','checked_in','waitlisted']) as $reg)
                  <tr>
                    <td>
                      {{ $reg->customer?->fullName() ?? 'Unknown' }}
                      @if($reg->status === 'waitlisted')
                        <span class="cl-waitlist-label">#{{ $reg->waitlist_position }}</span>
                      @endif
                    </td>
                    <td style="color:var(--ia-text-muted)">{{ ucfirst(str_replace('_',' ',$reg->payment_method)) }}</td>
                    <td><span class="cl-status-pill {{ $reg->status }}" style="font-size:10px">{{ ucfirst(str_replace('_',' ',$reg->status)) }}</span></td>
                    <td style="text-align:right">
                      @if($reg->status === 'registered')
                        <form method="POST" action="{{ route('tenant.classes.registrations.checkin', ['subdomain' => $sub, 'id' => $reg->id]) }}" style="display:inline">
                          @csrf
                          <button type="submit" class="cl-action-btn" title="Check in">✓</button>
                        </form>
                      @endif
                      <form method="POST" action="{{ route('tenant.classes.registrations.cancel', ['subdomain' => $sub, 'id' => $reg->id]) }}" style="display:inline" onsubmit="return iaConfirmCancelRegistration(this, event)">
                        @csrf
                        <button type="submit" class="cl-action-btn" title="Cancel" style="color:#EF4444">✕</button>
                      </form>
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          @else
            <p style="font-size:13px;color:var(--ia-text-muted);margin:0 0 14px">No registrations yet.</p>
          @endif

          @if(!in_array($session->status, ['cancelled','completed']))
            <form method="POST" action="{{ route('tenant.classes.sessions.register', ['subdomain' => $sub, 'id' => $session->id]) }}">
              @csrf
              <div class="cl-add-reg-row">
                <div>
                  <label class="cl-label">Add customer</label>
                  <x-tenant.customer-search name="customer_id" required />
                </div>
                <div>
                  <label class="cl-label">Payment</label>
                  <select name="payment_method" class="cl-select">
                    <option value="cash">Cash</option>
                    
                    <option value="pack">Pack</option>
                    <option value="membership">Membership</option>
                  </select>
                </div>
                <button type="submit" class="ia-btn ia-btn--primary" style="white-space:nowrap">Add</button>
              </div>
            </form>
          @endif
        </div>
      </div>
    @endforeach
  </div>

  {{-- Mobile day-grouped card list (parallel render, ≤640px) --}}
  @php
    // Group sessions by Y-m-d so we can render sticky day labels.
    // Sessions are already ordered by starts_at from the controller.
    $byDay = [];
    foreach ($sessions as $sess) {
      $key = $sess->starts_at->format('Y-m-d');
      $byDay[$key] = $byDay[$key] ?? [];
      $byDay[$key][] = $sess;
    }
    $todayKey = now()->format('Y-m-d');
  @endphp
  <div class="cl-sched-mobile">
    @foreach($byDay as $dayKey => $daySessions)
      @php
        $isToday = ($dayKey === $todayKey);
        // Reuse the Carbon instance from the first session of the day —
        // avoids re-parsing the string key.
        $dayDate = $daySessions[0]->starts_at;
      @endphp
      <div class="cl-sched-day-group">
        <div class="cl-sched-day-label {{ $isToday ? 'is-today' : '' }}">
          {{ $dayDate->format('D, M j') }}@if($isToday) · Today @endif
        </div>
        @foreach($daySessions as $session)
          @php
            $pct = $session->capacity_snapshot > 0
              ? min(100, round(($session->active_registrations_count / $session->capacity_snapshot) * 100))
              : 0;
            $isFull = $pct >= 100;
            $showUrl = route('tenant.classes.sessions.show', ['subdomain' => $sub, 'id' => $session->id]);
          @endphp
          <a href="{{ $showUrl }}" class="cl-sess-card-m">
            <div class="cl-sess-top-m">
              <div class="cl-sess-left-m">
                <div class="cl-sess-time-m">{{ $session->starts_at->format('g:i A') }} – {{ $session->ends_at->format('g:i A') }}</div>
                <div class="cl-sess-name-m">{{ $session->template->name }}</div>
                <div class="cl-sess-meta-m">{{ $session->instructor_snapshot ?? 'No instructor' }} · {{ $session->template->duration_minutes }}min</div>
              </div>
              <div class="cl-sess-right-m">
                <span class="cl-status-pill {{ $session->status }}">{{ ucfirst($session->status) }}</span>
              </div>
            </div>
            <div class="cl-sess-capacity-row-m">
              <div class="cl-sess-capacity-bar-m">
                <div class="cl-sess-capacity-fill-m {{ $isFull ? 'is-full' : '' }}" style="width:{{ $pct }}%"></div>
              </div>
              @if($session->waitlist_count > 0)
                <span class="cl-sess-waitlist-m">+{{ $session->waitlist_count }} wait</span>
              @endif
              <span class="cl-sess-capacity-text-m">{{ $session->active_registrations_count }}/{{ $session->capacity_snapshot }}</span>
            </div>
          </a>
        @endforeach
      </div>
    @endforeach
  </div>
@endif

{{-- Cancel session modal --}}
<div class="cl-modal-overlay" id="cancel-modal">
  <div class="cl-modal" style="max-width:400px">
    <div class="cl-modal-title">Cancel this session?</div>
    <p style="font-size:13px;color:var(--ia-text-muted);margin-bottom:20px">Registered customers will not be automatically notified.</p>
    <form method="POST" id="cancel-form" action="">
      @csrf
      @method('PATCH')
      <input type="hidden" name="status" value="cancelled">
      <div class="cl-modal-footer">
        <button type="button" class="ia-btn ia-btn--ghost" onclick="closeCancelModal()">Keep session</button>
        <button type="submit" class="ia-btn ia-btn--primary" style="background:#EF4444;border-color:#EF4444">Yes, cancel it</button>
      </div>
    </form>
  </div>
</div>

{{-- Add session modal --}}
<div class="cl-modal-overlay" id="add-modal" onclick="if(event.target===this)closeAddModal()">
  <div class="cl-modal">
    <div class="cl-modal-title">New class session</div>
    <form method="POST" action="{{ route('tenant.classes.sessions.store') }}" id="session-form">
      @csrf

      <div class="cl-field">
        <label class="cl-label">Template</label>
        <select name="class_template_id" class="cl-select" required>
          <option value="">— Select template —</option>
          @foreach($templates as $t)
            <option value="{{ $t->id }}">{{ $t->name }} ({{ $t->duration_minutes }}min · cap {{ $t->default_capacity }})</option>
          @endforeach
        </select>
      </div>

      {{-- Repeat type --}}
      <div class="cl-field">
        <label class="cl-label">Frequency</label>
        <div class="cl-repeat-tabs">
          <button type="button" class="cl-repeat-tab active" onclick="setRepeat('once', this)">One time</button>
          <button type="button" class="cl-repeat-tab" onclick="setRepeat('weekly', this)">Weekly</button>
          <button type="button" class="cl-repeat-tab" onclick="setRepeat('daily', this)">Daily</button>
        </div>
      </div>

      {{-- Mini calendar --}}
      <div class="cl-field">
        <label class="cl-label" id="cal-label">Date</label>
        <div class="cl-cal">
          <div class="cl-cal-head">
            <button type="button" class="cl-cal-nav" id="cal-prev">‹</button>
            <div class="cl-cal-title" id="cal-title"></div>
            <button type="button" class="cl-cal-nav" id="cal-next">›</button>
          </div>
          <div class="cl-cal-dows">
            <div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div>
          </div>
          <div class="cl-cal-grid" id="cal-grid"></div>
        </div>
        <input type="hidden" name="starts_date" id="starts-date" required>
      </div>

      {{-- Time --}}
      <div class="cl-field">
        <label class="cl-label">Time</label>
        <input type="time" name="starts_time" id="starts-time" class="cl-input" required value="09:00">
      </div>

      {{-- Weekly options --}}
      <div class="cl-repeat-section" id="repeat-weekly">
        <div class="cl-field">
          <label class="cl-label">Repeat on</label>
          <div class="cl-dow-btns">
            <button type="button" class="cl-dow-btn" data-dow="0" onclick="toggleDow(this)">Su</button>
            <button type="button" class="cl-dow-btn" data-dow="1" onclick="toggleDow(this)">Mo</button>
            <button type="button" class="cl-dow-btn" data-dow="2" onclick="toggleDow(this)">Tu</button>
            <button type="button" class="cl-dow-btn" data-dow="3" onclick="toggleDow(this)">We</button>
            <button type="button" class="cl-dow-btn" data-dow="4" onclick="toggleDow(this)">Th</button>
            <button type="button" class="cl-dow-btn" data-dow="5" onclick="toggleDow(this)">Fr</button>
            <button type="button" class="cl-dow-btn" data-dow="6" onclick="toggleDow(this)">Sa</button>
          </div>
          <input type="hidden" name="repeat_days" id="repeat-days-input">
        </div>
        <div class="cl-field">
          <label class="cl-label">Until</label>
          <input type="date" name="repeat_until" id="repeat-until" class="cl-input" min="{{ now()->addDay()->format('Y-m-d') }}">
        </div>
      </div>

      {{-- Daily options --}}
      <div class="cl-repeat-section" id="repeat-daily">
        <div class="cl-field">
          <label class="cl-label">Until</label>
          <input type="date" name="repeat_until_daily" class="cl-input" min="{{ now()->addDay()->format('Y-m-d') }}">
        </div>
      </div>

      {{-- Session preview --}}
      <div class="cl-session-preview" id="session-preview">Select a date above to preview sessions.</div>

      <div class="cl-field" style="margin-top:14px">
        <label class="cl-label">Capacity override (optional)</label>
        <input type="number" name="capacity_override" class="cl-input" min="1" max="500" placeholder="Leave blank to use template default">
      </div>

      <div class="cl-field">
        <label class="cl-label">Notes (optional)</label>
        <input type="text" name="notes" class="cl-input" maxlength="1000" placeholder="Internal note for this session">
      </div>

      <div class="cl-modal-footer">
        <button type="button" class="ia-btn ia-btn--ghost" onclick="closeAddModal()">Cancel</button>
        <button type="submit" class="ia-btn ia-btn--primary">Create session</button>
      </div>
    </form>
  </div>
</div>

@endsection

@push('scripts')
<script>
(function(){
  var repeatMode = 'once';
  var selectedDate = null;
  var calYear, calMonth;
  var today = new Date();
  today.setHours(0,0,0,0);

  function initCal(){
    var d = new Date();
    calYear  = d.getFullYear();
    calMonth = d.getMonth();
    renderCal();
  }

  function renderCal(){
    var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    document.getElementById('cal-title').textContent = months[calMonth] + ' ' + calYear;
    var grid = document.getElementById('cal-grid');
    grid.innerHTML = '';
    var first = new Date(calYear, calMonth, 1).getDay();
    var days  = new Date(calYear, calMonth+1, 0).getDate();
    for(var i=0;i<first;i++){
      var e=document.createElement('button');
      e.type='button';e.className='cl-cal-cell is-empty';e.disabled=true;
      grid.appendChild(e);
    }
    for(var d=1;d<=days;d++){
      var cell=document.createElement('button');
      cell.type='button';
      cell.className='cl-cal-cell';
      cell.textContent=d;
      var cellDate=new Date(calYear,calMonth,d);
      if(cellDate<today){ cell.disabled=true; cell.classList.add('is-past'); }
      else {
        if(cellDate.toDateString()===today.toDateString()) cell.classList.add('is-today');
        if(selectedDate && cellDate.toDateString()===selectedDate.toDateString()) cell.classList.add('is-selected');
        (function(cd){ cell.addEventListener('click',function(){ selectDate(cd); }); })(cellDate);
      }
      grid.appendChild(cell);
    }
  }

  function selectDate(d){
    selectedDate = d;
    document.getElementById('starts-date').value = d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
    renderCal();
    updatePreview();
  }

  function updatePreview(){
    var preview = document.getElementById('session-preview');
    if(!selectedDate){ preview.textContent='Select a date above to preview sessions.'; return; }
    var time = document.getElementById('starts-time').value || '09:00';
    var dates = [];
    if(repeatMode==='once'){
      dates.push(fmtDate(selectedDate));
    } else if(repeatMode==='weekly'){
      var dows = Array.from(document.querySelectorAll('.cl-dow-btn.active')).map(b=>parseInt(b.dataset.dow));
      var until = document.getElementById('repeat-until').value;
      if(dows.length && until){
        var cur = new Date(selectedDate);
        var end = new Date(until);
        while(cur<=end && dates.length<20){
          if(dows.includes(cur.getDay())) dates.push(fmtDate(cur));
          cur.setDate(cur.getDate()+1);
        }
      }
    } else if(repeatMode==='daily'){
      var untilD = document.querySelector('[name="repeat_until_daily"]').value;
      if(untilD){
        var cur = new Date(selectedDate);
        var end = new Date(untilD);
        while(cur<=end && dates.length<20){
          dates.push(fmtDate(cur));
          cur.setDate(cur.getDate()+1);
        }
      }
    }
    if(!dates.length){ preview.textContent='Select repeat options above.'; return; }
    var suffix = dates.length>5 ? ' + '+(dates.length-5)+' more' : '';
    preview.textContent = dates.slice(0,5).join(', ') + suffix + ' at ' + fmtTime(time);
  }

  function fmtDate(d){
    var days=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    var months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return days[d.getDay()]+' '+months[d.getMonth()]+' '+d.getDate();
  }

  function fmtTime(t){
    var parts=t.split(':');
    var h=parseInt(parts[0]),m=parts[1];
    return (h%12||12)+':'+m+' '+(h<12?'AM':'PM');
  }

  document.getElementById('cal-prev').addEventListener('click',function(){
    calMonth--; if(calMonth<0){calMonth=11;calYear--;} renderCal();
  });
  document.getElementById('cal-next').addEventListener('click',function(){
    calMonth++; if(calMonth>11){calMonth=0;calYear++;} renderCal();
  });
  document.getElementById('starts-time').addEventListener('input', updatePreview);
  document.getElementById('repeat-until').addEventListener('input', updatePreview);
  document.querySelector('[name="repeat_until_daily"]').addEventListener('input', updatePreview);

  window.toggleDow = function(btn){
    btn.classList.toggle('active');
    var dows = Array.from(document.querySelectorAll('.cl-dow-btn.active')).map(b=>b.dataset.dow);
    document.getElementById('repeat-days-input').value = dows.join(',');
    updatePreview();
  };

  window.setRepeat = function(mode, btn){
    repeatMode = mode;
    document.querySelectorAll('.cl-repeat-tab').forEach(function(b){ b.classList.remove('active'); });
    btn.classList.add('active');
    document.querySelectorAll('.cl-repeat-section').forEach(function(s){ s.classList.remove('active'); });
    if(mode!=='once') document.getElementById('repeat-'+mode).classList.add('active');
    document.getElementById('cal-label').textContent = mode==='once' ? 'Date' : 'Start date';
    updatePreview();
  };

  window.toggleSession = function(id){ document.getElementById('session-'+id).classList.toggle('is-open'); };
  window.openAddModal  = function(){ document.getElementById('add-modal').classList.add('is-open'); initCal(); }
  window.closeAddModal = function(){ document.getElementById('add-modal').classList.remove('is-open'); }
  window.confirmCancel = function(url){ document.getElementById('cancel-form').action=url; document.getElementById('cancel-modal').classList.add('is-open'); }

  /**
   * Cancel-a-single-registration confirm. Replaces the native browser confirm()
   * with the IntakeConfirm modal so it matches the rest of the app and looks
   * good on mobile. Returns false to block the synchronous form submit, then
   * resubmits programmatically if the user confirms.
   */
  window.iaConfirmCancelRegistration = function(form, ev){
    ev.preventDefault();
    if (!window.IntakeConfirm) {
      // Fallback if confirm.js hasn't loaded — keep the action working.
      if (window.confirm('Cancel this registration?')) form.submit();
      return false;
    }
    window.IntakeConfirm.show({
      title:       'Remove customer from class?',
      message:     'Their pack credit or membership usage will be restored if applicable.',
      confirmText: 'Remove',
      cancelText:  'Keep',
      danger:      true,
    }).then(function(ok){ if (ok) form.submit(); });
    return false;
  }
  window.closeCancelModal = function(){ document.getElementById('cancel-modal').classList.remove('is-open'); }

  document.getElementById('cancel-modal').addEventListener('click',function(e){ if(e.target===this) closeCancelModal(); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'){ closeAddModal(); closeCancelModal(); } });
})();
</script>
@endpush
