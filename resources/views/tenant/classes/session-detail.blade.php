@extends('layouts.tenant.app')
@php $pageTitle = $session->template->name . ' — ' . $session->starts_at->format('M j, Y'); @endphp

@push('styles')
<style>
/* ============================================================
   Session detail — page-scoped via .cl-* prefix.
   Layout: parallel desktop + mobile renders, display-toggled.
   ============================================================ */
.cl-back{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--ia-text-muted);text-decoration:none;margin-bottom:16px;transition:color var(--ia-t)}
.cl-back:hover{color:var(--ia-text)}
.cl-detail-grid{display:grid;grid-template-columns:1fr 280px;gap:16px;align-items:start}
.cl-main-col{display:flex;flex-direction:column;gap:16px;min-width:0}
.cl-side-col{display:flex;flex-direction:column;gap:16px}
.cl-card{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
.cl-card-head{padding:14px 16px;border-bottom:0.5px solid var(--ia-border);display:flex;align-items:center;justify-content:space-between;gap:10px}
.cl-card-title{font-size:13px;font-weight:500;color:var(--ia-text)}
.cl-card-body{padding:16px}

/* Stat grid — cells get min-width:0 so sub-labels truncate. Card has
   overflow:hidden so the grid can't escape its container. THIS IS THE
   ONE-LINE FIX for the overflow visible in the original screenshot. */
.cl-stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--ia-border)}
.cl-stat{background:var(--ia-surface);padding:14px 16px;min-width:0}
.cl-stat-label{font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;margin-bottom:4px}
.cl-stat-value{font-size:22px;font-weight:600;color:var(--ia-text);font-variant-numeric:tabular-nums;line-height:1}
.cl-stat-sub{font-size:12px;color:var(--ia-text-muted);margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cl-capacity-bar{height:6px;background:var(--ia-border);border-radius:3px;overflow:hidden;margin:12px 16px}
.cl-capacity-fill{height:100%;background:var(--ia-accent);border-radius:3px;transition:width .3s}
.cl-capacity-fill.is-full{background:#EF4444}

.cl-status-pill{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:500}
.cl-status-pill.scheduled{background:var(--ia-surface-2);color:var(--ia-text-muted)}
.cl-status-pill.confirmed{background:var(--ia-accent-soft);color:var(--ia-accent)}
.cl-status-pill.cancelled{background:rgba(239,68,68,.1);color:#EF4444}
.cl-status-pill.completed{background:var(--ia-surface-2);color:var(--ia-text-muted)}
.cl-status-pill.registered{background:var(--ia-accent-soft);color:var(--ia-accent)}
.cl-status-pill.checked_in{background:rgba(34,197,94,.12);color:#16a34a}
.cl-status-pill.waitlisted{background:rgba(239,68,68,.1);color:#EF4444}
.cl-status-pill.no_show{background:var(--ia-surface-2);color:var(--ia-text-muted)}

/* ============================================================
   Class notes card.
   Two textareas:
     - Template class_notes (saves back to template, cascade confirm)
     - Session session_notes_override (this session only, no cascade)
   ============================================================ */
.cl-notes-card{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
.cl-notes-section{padding:14px 16px;border-bottom:0.5px solid var(--ia-border)}
.cl-notes-section:last-child{border-bottom:none}
.cl-notes-label-row{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:6px;gap:8px}
.cl-notes-label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-muted);font-weight:500}
.cl-notes-scope{font-size:10px;color:var(--ia-text-dim, rgba(255,255,255,.38));text-transform:uppercase;letter-spacing:.06em}
.cl-notes-textarea{width:100%;padding:9px 11px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;line-height:1.5;outline:none;transition:border var(--ia-t);font-family:inherit;resize:vertical;min-height:60px}
.cl-notes-textarea:focus{border-color:var(--ia-accent)}
.cl-notes-help{font-size:11px;color:var(--ia-text-muted);margin-top:5px;line-height:1.4}
.cl-notes-actions{display:flex;justify-content:flex-end;gap:6px;margin-top:8px}
.cl-notes-save{height:30px;padding:0 12px;border-radius:6px;font-size:12px;color:var(--ia-text);background:var(--ia-surface-2);border:0.5px solid var(--ia-border);cursor:pointer;transition:all var(--ia-t);font-family:inherit}
.cl-notes-save:hover{background:var(--ia-hover);border-color:var(--ia-border-strong)}
.cl-notes-save.is-dirty{background:var(--ia-accent);color:#000;border-color:var(--ia-accent)}
.cl-notes-save:disabled{opacity:.5;cursor:not-allowed}

/* ============================================================
   Roster — DESKTOP table
   ============================================================ */
.cl-reg-table{width:100%;border-collapse:collapse;font-size:13px}
.cl-reg-table th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;padding:8px 14px;border-bottom:0.5px solid var(--ia-border);background:var(--ia-surface-2)}
.cl-reg-table td{padding:11px 14px;border-bottom:0.5px solid var(--ia-border);color:var(--ia-text);vertical-align:middle}
.cl-reg-table tr:last-child td{border-bottom:none}
.cl-reg-table tbody tr:hover td{background:var(--ia-hover)}
.cl-reg-name{font-weight:500}
.cl-reg-email{font-size:12px;color:var(--ia-text-muted);margin-top:1px}
.cl-pay-method{display:inline-flex;align-items:center;padding:2px 7px;border-radius:20px;font-size:11px;background:var(--ia-surface-2);color:var(--ia-text-muted)}
.cl-reg-actions{display:flex;gap:4px;justify-content:flex-end}
.cl-action-btn{height:28px;padding:0 10px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;color:var(--ia-text-muted);background:none;border:0.5px solid var(--ia-border);cursor:pointer;transition:all var(--ia-t);white-space:nowrap;font-family:inherit}
.cl-action-btn:hover{background:var(--ia-hover);color:var(--ia-text);border-color:var(--ia-border-strong)}
.cl-action-btn.danger:hover{background:rgba(239,68,68,.08);color:#EF4444;border-color:rgba(239,68,68,.3)}
.cl-action-btn.success{color:#16a34a;border-color:rgba(34,197,94,.3)}
.cl-action-btn.success:hover{background:rgba(34,197,94,.08)}
.cl-waitlist-pos{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:rgba(239,68,68,.1);color:#EF4444;font-size:11px;font-weight:600;flex-shrink:0}
.cl-section-label{font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;padding:10px 14px;background:var(--ia-surface-2);border-bottom:0.5px solid var(--ia-border)}

/* ============================================================
   Add-registration — DESKTOP sticky form
   ============================================================ */
.cl-add-reg-form{padding:14px 16px;border-bottom:0.5px solid var(--ia-border);background:var(--ia-surface-2);position:sticky;top:0;z-index:5}
.cl-add-reg-grid{display:grid;grid-template-columns:1fr 160px auto;gap:8px;align-items:end}
.cl-label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-muted);font-weight:500;margin-bottom:5px}
.cl-input,.cl-select{padding:8px 11px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;outline:none;transition:border var(--ia-t);width:100%;font-family:inherit}
.cl-input:focus,.cl-select:focus{border-color:var(--ia-accent)}
.cl-select{appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10' fill='none' stroke='rgba(255,255,255,.4)'><path d='M2 4l3 3 3-3' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/></svg>");background-repeat:no-repeat;background-position:right 10px center;padding-right:28px}

.cl-info-row{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:0.5px solid var(--ia-border);font-size:13px;gap:10px}
.cl-info-row:last-child{border-bottom:none}
.cl-info-label{color:var(--ia-text-muted);flex-shrink:0}
.cl-info-value{color:var(--ia-text);font-weight:500;text-align:right;min-width:0;overflow-wrap:anywhere}
.cl-session-actions{display:flex;flex-direction:column;gap:6px}
.cl-session-action-btn{width:100%;padding:8px 12px;border-radius:var(--ia-r-md);font-size:13px;text-align:left;cursor:pointer;transition:all var(--ia-t);background:var(--ia-surface);border:0.5px solid var(--ia-border);color:var(--ia-text);font-family:inherit}
.cl-session-action-btn:hover{background:var(--ia-hover);border-color:var(--ia-border-strong)}
.cl-session-action-btn.danger{color:#EF4444;border-color:rgba(239,68,68,.25)}
.cl-session-action-btn.danger:hover{background:rgba(239,68,68,.06)}
.cl-empty-reg{padding:32px;text-align:center;color:var(--ia-text-muted);font-size:13px}

/* ============================================================
   MOBILE — parallel renders & toggles
   ============================================================ */
.cl-reg-mobile,
.cl-add-reg-mobile-trigger,
.cl-add-sheet-overlay,
.cl-add-sheet{display:none}

@media(max-width:900px){
  .cl-detail-grid{grid-template-columns:1fr}
}

@media(max-width:640px){
  /* Swap desktop roster + sticky form for mobile renders */
  .cl-reg-table,
  .cl-add-reg-form{display:none !important}
  .cl-reg-mobile{display:block}
  .cl-add-reg-mobile-trigger{display:flex}

  .cl-reg-mobile .cl-reg-row{padding:12px 16px;border-bottom:0.5px solid var(--ia-border);display:flex;flex-direction:column;gap:10px}
  .cl-reg-mobile .cl-reg-row:last-child{border-bottom:none}
  .cl-reg-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
  .cl-reg-identity{min-width:0;flex:1}
  .cl-reg-identity .cl-reg-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .cl-reg-identity .cl-reg-email{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .cl-reg-meta{display:flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:flex-end;flex-shrink:0}
  .cl-reg-mobile .cl-reg-actions{display:flex;gap:6px;justify-content:stretch}
  .cl-reg-mobile .cl-reg-actions form{flex:1}
  .cl-reg-mobile .cl-reg-actions .cl-action-btn{width:100%;height:34px}
  .cl-reg-mobile .is-waitlist .cl-reg-identity{display:flex;align-items:center;gap:10px}
  .cl-reg-mobile .is-waitlist .cl-reg-identity > div{min-width:0;flex:1}

  .cl-add-reg-mobile-trigger{padding:14px 16px;border-bottom:0.5px solid var(--ia-border);background:var(--ia-surface-2);align-items:center;gap:8px;cursor:pointer;color:var(--ia-accent);font-size:13px;font-weight:500;width:100%;text-align:left;border-left:none;border-right:none;border-top:none;font-family:inherit}
  .cl-add-reg-mobile-trigger:hover{background:var(--ia-hover)}
  .cl-add-reg-mobile-trigger svg{width:16px;height:16px}

  .cl-add-sheet-overlay{display:block;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:90;opacity:0;pointer-events:none;transition:opacity .15s}
  .cl-add-sheet-overlay.is-open{opacity:1;pointer-events:all}
  .cl-add-sheet{display:block;position:fixed;bottom:0;left:0;right:0;background:var(--ia-bg,#0a0a0a);border-radius:18px 18px 0 0;padding:12px 16px calc(20px + env(safe-area-inset-bottom, 0px));z-index:91;border-top:0.5px solid var(--ia-border);transform:translateY(100%);transition:transform .2s ease}
  .cl-add-sheet.is-open{transform:translateY(0)}
  .cl-add-sheet-handle{width:36px;height:4px;border-radius:2px;background:rgba(255,255,255,.2);margin:0 auto 14px}
  .cl-add-sheet-title{font-size:16px;font-weight:600;margin-bottom:14px;color:var(--ia-text)}
  .cl-add-sheet .cl-field{margin-bottom:14px}
  .cl-add-sheet-warn{background:rgba(239,68,68,.08);border:0.5px solid rgba(239,68,68,.2);border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:12px;color:#fca5a5;line-height:1.4}
  .cl-add-sheet-primary{width:100%;padding:14px;background:var(--ia-accent);color:#000;border:none;border-radius:var(--ia-r-md);font-size:15px;font-weight:600;cursor:pointer;font-family:inherit}
  .cl-add-sheet-cancel{width:100%;padding:12px;background:transparent;color:var(--ia-text-muted);border:none;font-size:14px;margin-top:4px;cursor:pointer;font-family:inherit}

  .ia-page-head-right{align-self:flex-start}
}
</style>
@endpush

@section('content')

<a href="{{ route('tenant.classes.sessions') }}" class="cl-back">
  <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M9 2L5 7l4 5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
  Back to schedule
</a>

@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
@endif

<div class="ia-page-head" style="margin-bottom:16px">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $session->template->name }}</h1>
    <p class="ia-page-subtitle">
      {{ $session->starts_at->format('l, F j, Y') }} ·
      {{ tlocal($session->starts_at) }} – {{ tlocal($session->ends_at) }}
    </p>
  </div>
  <div class="ia-page-head-right">
    <span class="cl-status-pill {{ $session->status }}">{{ ucfirst($session->status) }}</span>
  </div>
</div>

<div class="cl-detail-grid">

  {{-- Main column --}}
  <div class="cl-main-col">

    {{-- Capacity overview --}}
    @php
      $active    = $session->registrations->whereIn('status', ['registered','checked_in'])->count();
      $checkedIn = $session->registrations->where('status','checked_in')->count();
      $waitlist  = $session->registrations->where('status','waitlisted')->count();
      $cap       = $session->capacity_snapshot;
      $pct       = $cap > 0 ? min(100, round($active / $cap * 100)) : 0;
      $upcomingSessionsCount = $session->template->sessions()->where('starts_at', '>', now())->count();
    @endphp
    <div class="cl-card">
      <div class="cl-stat-grid">
        <div class="cl-stat">
          <div class="cl-stat-label">Registered</div>
          <div class="cl-stat-value">{{ $active }}</div>
          <div class="cl-stat-sub">of {{ $cap }} spots</div>
        </div>
        <div class="cl-stat">
          <div class="cl-stat-label">Checked in</div>
          <div class="cl-stat-value">{{ $checkedIn }}</div>
          <div class="cl-stat-sub">{{ $active > 0 ? round($checkedIn / $active * 100) : 0 }}% attendance</div>
        </div>
        <div class="cl-stat">
          <div class="cl-stat-label">Waitlist</div>
          <div class="cl-stat-value">{{ $waitlist }}</div>
          <div class="cl-stat-sub">{{ $waitlist === 0 ? 'No queue' : 'Pending promotion' }}</div>
        </div>
        <div class="cl-stat">
          <div class="cl-stat-label">Open spots</div>
          <div class="cl-stat-value">{{ max(0, $cap - $active) }}</div>
          <div class="cl-stat-sub">{{ $pct }}% full</div>
        </div>
      </div>
      <div class="cl-capacity-bar">
        <div class="cl-capacity-fill {{ $pct >= 100 ? 'is-full' : '' }}" style="width:{{ $pct }}%"></div>
      </div>
    </div>

    {{-- Class notes card --}}
    <div class="cl-notes-card">
      {{-- Template-level class notes.
           The template update endpoint validates the full template payload,
           so we resubmit existing fields as hidden inputs; only class_notes
           effectively changes. --}}
      <div class="cl-notes-section">
        <form method="POST"
              id="cl-template-notes-form"
              action="{{ route('tenant.classes.templates.update', ['id' => $session->template->id]) }}"
              data-original="{{ (string)($session->template->class_notes ?? '') }}"
              data-upcoming="{{ $upcomingSessionsCount }}">
          @csrf
          @method('PATCH')
          <input type="hidden" name="name"                   value="{{ $session->template->name }}">
          <input type="hidden" name="description"            value="{{ $session->template->description }}">
          <input type="hidden" name="duration_minutes"       value="{{ $session->template->duration_minutes }}">
          <input type="hidden" name="default_capacity"       value="{{ $session->template->default_capacity }}">
          <input type="hidden" name="instructor_resource_id" value="{{ $session->template->instructor_resource_id }}">
          <input type="hidden" name="price_dollars"          value="{{ number_format($session->template->price_cents / 100, 2, '.', '') }}">
          <input type="hidden" name="is_active"              value="{{ $session->template->is_active ? 1 : 0 }}">

          <div class="cl-notes-label-row">
            <span class="cl-notes-label">Class notes</span>
            <span class="cl-notes-scope">All sessions · {{ $upcomingSessionsCount }} upcoming</span>
          </div>
          <textarea name="class_notes"
                    id="cl-template-notes-input"
                    class="cl-notes-textarea"
                    rows="3"
                    maxlength="2000"
                    placeholder="e.g. Bring your own mat — studio mats are out."
                    >{{ $session->template->class_notes }}</textarea>
          <div class="cl-notes-help">
            Permanent note attached to <strong>{{ $session->template->name }}</strong>. Shown to staff on every session, and included in customer booking confirmations. Editing affects all upcoming sessions.
          </div>
          <div class="cl-notes-actions">
            <button type="submit" id="cl-template-notes-save" class="cl-notes-save" disabled>Save class notes</button>
          </div>
        </form>
      </div>

      {{-- Session-only override --}}
      <div class="cl-notes-section">
        <form method="POST"
              id="cl-session-notes-form"
              action="{{ route('tenant.classes.sessions.update', ['id' => $session->id]) }}"
              data-original="{{ (string)($session->session_notes_override ?? '') }}">
          @csrf
          @method('PATCH')
          <div class="cl-notes-label-row">
            <span class="cl-notes-label">Note for this session only</span>
            <span class="cl-notes-scope">{{ $session->starts_at->format('M j') }}</span>
          </div>
          <textarea name="session_notes_override"
                    id="cl-session-notes-input"
                    class="cl-notes-textarea"
                    rows="2"
                    maxlength="2000"
                    placeholder="e.g. Parking lot closed today — use side entrance."
                    >{{ $session->session_notes_override }}</textarea>
          <div class="cl-notes-help">
            Added to this session only. Appears below the class notes for staff and customers.
          </div>
          <div class="cl-notes-actions">
            <button type="submit" id="cl-session-notes-save" class="cl-notes-save" disabled>Save session note</button>
          </div>
        </form>
      </div>
    </div>

    {{-- Registration roster --}}
    <div class="cl-card">
      <div class="cl-card-head">
        <span class="cl-card-title">Roster</span>
        <span style="font-size:12px;color:var(--ia-text-muted)">{{ $active }} registered · {{ $checkedIn }} checked in</span>
      </div>

      @if(!in_array($session->status, ['cancelled','completed']))
        {{-- DESKTOP sticky form --}}
        <div class="cl-add-reg-form">
          <form method="POST" action="{{ route('tenant.classes.sessions.register', ['id' => $session->id]) }}">
            @csrf
            <div class="cl-add-reg-grid">
              <div>
                <label class="cl-label">Customer</label>
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
              <button type="submit" class="ia-btn ia-btn--primary" style="white-space:nowrap;align-self:flex-end">Add</button>
            </div>
          </form>
        </div>

        {{-- MOBILE tap-to-open trigger --}}
        <button type="button" class="cl-add-reg-mobile-trigger" onclick="clOpenAddSheet()">
          <svg viewBox="0 0 16 16" fill="none"><path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
          Add registration
        </button>
      @endif

      @php
        $registered = $session->registrations->whereIn('status',['registered','checked_in'])->sortBy('registered_at');
        $waitlisted = $session->registrations->where('status','waitlisted')->sortBy('waitlist_position');
        $cancelled  = $session->registrations->whereIn('status',['cancelled','no_show'])->sortByDesc('cancelled_at');
      @endphp

      @if($registered->isEmpty() && $waitlisted->isEmpty())
        <div class="cl-empty-reg">No registrations yet.</div>
      @else
        {{-- ========== DESKTOP table ========== --}}
        @if($registered->isNotEmpty())
          <table class="cl-reg-table">
            <thead>
              <tr><th>Customer</th><th>Payment</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              @foreach($registered as $reg)
                <tr>
                  <td>
                    <div class="cl-reg-name">{{ $reg->customer?->fullName() ?? 'Unknown' }}</div>
                    <div class="cl-reg-email">{{ $reg->customer->email ?? '' }}</div>
                  </td>
                  <td><span class="cl-pay-method">{{ ucfirst(str_replace('_',' ',$reg->payment_method)) }}</span></td>
                  <td><span class="cl-status-pill {{ $reg->status }}">{{ ucfirst(str_replace('_',' ',$reg->status)) }}</span></td>
                  <td>
                    <div class="cl-reg-actions">
                      @if($reg->status === 'registered')
                        <form method="POST" action="{{ route('tenant.classes.registrations.checkin', ['id' => $reg->id]) }}">
                          @csrf
                          <button type="submit" class="cl-action-btn success">Check in</button>
                        </form>
                        <form method="POST" action="{{ route('tenant.classes.registrations.noshow', ['id' => $reg->id]) }}" onsubmit="return iaConfirmAction(this, event, {title:'Mark as no-show?', message:'This is final and cannot be reversed.', confirmText:'Mark no-show', cancelText:'Keep', danger:true})">
                          @csrf
                          <button type="submit" class="cl-action-btn danger">No-show</button>
                        </form>
                      @endif
                      @if(in_array($reg->status, ['registered','checked_in']))
                        <form method="POST" action="{{ route('tenant.classes.registrations.cancel', ['id' => $reg->id]) }}" onsubmit="return iaConfirmAction(this, event, {title:'Remove customer from class?', message:'Their pack credit or membership usage will be restored if applicable.', confirmText:'Remove', cancelText:'Keep', danger:true})">
                          @csrf
                          <button type="submit" class="cl-action-btn danger">Cancel</button>
                        </form>
                      @endif
                    </div>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif

        @if($waitlisted->isNotEmpty())
          <div class="cl-section-label">Waitlist</div>
          <table class="cl-reg-table">
            <tbody>
              @foreach($waitlisted as $reg)
                <tr>
                  <td>
                    <div style="display:flex;align-items:center;gap:8px">
                      <span class="cl-waitlist-pos">{{ $reg->waitlist_position }}</span>
                      <div>
                        <div class="cl-reg-name">{{ $reg->customer?->fullName() ?? 'Unknown' }}</div>
                        <div class="cl-reg-email">{{ $reg->customer->email ?? '' }}</div>
                      </div>
                    </div>
                  </td>
                  <td><span class="cl-pay-method">{{ ucfirst(str_replace('_',' ',$reg->payment_method)) }}</span></td>
                  <td><span class="cl-status-pill waitlisted">Waitlisted</span></td>
                  <td>
                    <div class="cl-reg-actions">
                      <form method="POST" action="{{ route('tenant.classes.registrations.cancel', ['id' => $reg->id]) }}" onsubmit="return iaConfirmAction(this, event, {title:'Remove from waitlist?', message:'They will lose their spot in line.', confirmText:'Remove', cancelText:'Keep', danger:true})">
                        @csrf
                        <button type="submit" class="cl-action-btn danger">Remove</button>
                      </form>
                    </div>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif

        {{-- ========== MOBILE card list ========== --}}
        <div class="cl-reg-mobile">
          @foreach($registered as $reg)
            <div class="cl-reg-row">
              <div class="cl-reg-top">
                <div class="cl-reg-identity">
                  <div class="cl-reg-name">{{ $reg->customer?->fullName() ?? 'Unknown' }}</div>
                  <div class="cl-reg-email">{{ $reg->customer->email ?? '' }}</div>
                </div>
                <div class="cl-reg-meta">
                  <span class="cl-pay-method">{{ ucfirst(str_replace('_',' ',$reg->payment_method)) }}</span>
                  @if($reg->status === 'checked_in')
                    <span class="cl-status-pill checked_in">In</span>
                  @endif
                </div>
              </div>
              <div class="cl-reg-actions">
                @if($reg->status === 'registered')
                  <form method="POST" action="{{ route('tenant.classes.registrations.checkin', ['id' => $reg->id]) }}">
                    @csrf
                    <button type="submit" class="cl-action-btn success">Check in</button>
                  </form>
                  <form method="POST" action="{{ route('tenant.classes.registrations.noshow', ['id' => $reg->id]) }}" onsubmit="return iaConfirmAction(this, event, {title:'Mark as no-show?', message:'This is final and cannot be reversed.', confirmText:'Mark no-show', cancelText:'Keep', danger:true})">
                    @csrf
                    <button type="submit" class="cl-action-btn danger">No-show</button>
                  </form>
                @endif
                @if(in_array($reg->status, ['registered','checked_in']))
                  <form method="POST" action="{{ route('tenant.classes.registrations.cancel', ['id' => $reg->id]) }}" onsubmit="return iaConfirmAction(this, event, {title:'Remove customer from class?', message:'Their pack credit or membership usage will be restored if applicable.', confirmText:'Remove', cancelText:'Keep', danger:true})">
                    @csrf
                    <button type="submit" class="cl-action-btn danger">Cancel</button>
                  </form>
                @endif
              </div>
            </div>
          @endforeach

          @if($waitlisted->isNotEmpty())
            <div class="cl-section-label">Waitlist</div>
            @foreach($waitlisted as $reg)
              <div class="cl-reg-row is-waitlist">
                <div class="cl-reg-top">
                  <div class="cl-reg-identity">
                    <span class="cl-waitlist-pos">{{ $reg->waitlist_position }}</span>
                    <div>
                      <div class="cl-reg-name">{{ $reg->customer?->fullName() ?? 'Unknown' }}</div>
                      <div class="cl-reg-email">{{ $reg->customer->email ?? '' }}</div>
                    </div>
                  </div>
                  <div class="cl-reg-meta">
                    <span class="cl-pay-method">{{ ucfirst(str_replace('_',' ',$reg->payment_method)) }}</span>
                  </div>
                </div>
                <div class="cl-reg-actions">
                  <form method="POST" action="{{ route('tenant.classes.registrations.cancel', ['id' => $reg->id]) }}" onsubmit="return iaConfirmAction(this, event, {title:'Remove from waitlist?', message:'They will lose their spot in line.', confirmText:'Remove', cancelText:'Keep', danger:true})">
                    @csrf
                    <button type="submit" class="cl-action-btn danger">Remove</button>
                  </form>
                </div>
              </div>
            @endforeach
          @endif
        </div>
      @endif

      {{-- Cancelled / no-show (shared, collapsed) --}}
      @if($cancelled->isNotEmpty())
        <div class="cl-section-label" style="cursor:pointer" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'':'none'">
          {{ $cancelled->count() }} cancelled / no-show ▾
        </div>
        <div style="display:none">
          <table class="cl-reg-table">
            <tbody>
              @foreach($cancelled as $reg)
                <tr style="opacity:.5">
                  <td>
                    <div class="cl-reg-name">{{ $reg->customer?->fullName() ?? 'Unknown' }}</div>
                    <div class="cl-reg-email">{{ $reg->customer->email ?? '' }}</div>
                  </td>
                  <td><span class="cl-pay-method">{{ ucfirst(str_replace('_',' ',$reg->payment_method)) }}</span></td>
                  <td><span class="cl-status-pill {{ $reg->status }}">{{ ucfirst(str_replace('_',' ',$reg->status)) }}</span></td>
                  <td></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>
  </div>

  {{-- Sidebar --}}
  <div class="cl-side-col">

    <div class="cl-card">
      <div class="cl-card-head"><span class="cl-card-title">Session info</span></div>
      <div class="cl-card-body">
        <div class="cl-info-row">
          <span class="cl-info-label">Template</span>
          <span class="cl-info-value">{{ $session->template->name }}</span>
        </div>
        <div class="cl-info-row">
          <span class="cl-info-label">Date</span>
          <span class="cl-info-value">{{ $session->starts_at->format('M j, Y') }}</span>
        </div>
        <div class="cl-info-row">
          <span class="cl-info-label">Time</span>
          <span class="cl-info-value">{{ tlocal($session->starts_at) }} – {{ tlocal($session->ends_at) }}</span>
        </div>
        <div class="cl-info-row">
          <span class="cl-info-label">Instructor</span>
          <span class="cl-info-value">{{ $session->instructor_snapshot ?? '—' }}</span>
        </div>
        <div class="cl-info-row">
          <span class="cl-info-label">Capacity</span>
          <span class="cl-info-value">{{ $session->capacity_snapshot }}</span>
        </div>
        @if($session->notes)
          <div class="cl-info-row">
            <span class="cl-info-label">Notes</span>
            <span class="cl-info-value" style="font-weight:400;color:var(--ia-text-muted)">{{ $session->notes }}</span>
          </div>
        @endif
      </div>
    </div>

    @if(!in_array($session->status, ['cancelled','completed']))
      <div class="cl-card">
        <div class="cl-card-head"><span class="cl-card-title">Actions</span></div>
        <div class="cl-card-body">
          <div class="cl-session-actions">
            @if($session->status === 'confirmed')
              <form method="POST" action="{{ route('tenant.classes.sessions.update', ['id' => $session->id]) }}">
                @csrf @method('PATCH')
                <input type="hidden" name="status" value="completed">
                <button type="submit" class="cl-session-action-btn">Mark completed</button>
              </form>
            @endif
            <form method="POST" action="{{ route('tenant.classes.sessions.update', ['id' => $session->id]) }}" onsubmit="return iaConfirmAction(this, event, {title:'Cancel this session?', message:'Registered customers will NOT be automatically notified. Refunds and credit restoration apply per registration.', confirmText:'Cancel session', cancelText:'Keep session', danger:true})">
              @csrf @method('PATCH')
              <input type="hidden" name="status" value="cancelled">
              <button type="submit" class="cl-session-action-btn danger">Cancel session</button>
            </form>
          </div>
        </div>
      </div>
    @endif

  </div>
</div>

{{-- Mobile add-registration bottom sheet.
     Rendered outside .cl-detail-grid so position:fixed isn't trapped. --}}
@if(!in_array($session->status, ['cancelled','completed']))
  <div class="cl-add-sheet-overlay" id="cl-add-sheet-overlay" onclick="clCloseAddSheet()"></div>
  <div class="cl-add-sheet" id="cl-add-sheet" role="dialog" aria-label="Add registration">
    <div class="cl-add-sheet-handle"></div>
    <div class="cl-add-sheet-title">Add registration</div>
    <form method="POST" action="{{ route('tenant.classes.sessions.register', ['id' => $session->id]) }}">
      @csrf
      <div class="cl-field" style="margin-bottom:14px">
        <label class="cl-label">Customer</label>
        <x-tenant.customer-search name="customer_id" required />
      </div>
      <div class="cl-field" style="margin-bottom:14px">
        <label class="cl-label">Payment method</label>
        <select name="payment_method" class="cl-select">
          <option value="cash">Cash</option>
          <option value="pack">Pack</option>
          <option value="membership">Membership</option>
        </select>
      </div>
      @if($pct >= 100)
        <div class="cl-add-sheet-warn">
          Class is full. Customer will be added to the waitlist at position #{{ $waitlist + 1 }}.
        </div>
        <button type="submit" class="cl-add-sheet-primary">Add to waitlist</button>
      @else
        <button type="submit" class="cl-add-sheet-primary">Add registration</button>
      @endif
      <button type="button" class="cl-add-sheet-cancel" onclick="clCloseAddSheet()">Cancel</button>
    </form>
  </div>
@endif

@push('scripts')
<script>
  window.iaConfirmAction = function(form, ev, opts){
    ev.preventDefault();
    if (!window.IntakeConfirm) {
      if (window.confirm((opts && opts.title) || 'Are you sure?')) form.submit();
      return false;
    }
    window.IntakeConfirm.show(opts || {}).then(function(ok){ if (ok) form.submit(); });
    return false;
  }

  window.clOpenAddSheet  = function(){
    document.getElementById('cl-add-sheet-overlay').classList.add('is-open');
    document.getElementById('cl-add-sheet').classList.add('is-open');
    document.body.style.overflow = 'hidden';
  };
  window.clCloseAddSheet = function(){
    document.getElementById('cl-add-sheet-overlay').classList.remove('is-open');
    document.getElementById('cl-add-sheet').classList.remove('is-open');
    document.body.style.overflow = '';
  };
  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') clCloseAddSheet();
  });

  // Class notes — dirty state, cascade confirm on template-notes save.
  (function(){
    var TPL  = document.getElementById('cl-template-notes-form');
    var SESS = document.getElementById('cl-session-notes-form');

    function wireDirty(form, inputId, saveId){
      if (!form) return;
      var input = document.getElementById(inputId);
      var save  = document.getElementById(saveId);
      var orig  = form.dataset.original || '';
      function check(){
        var dirty = (input.value || '') !== orig;
        save.disabled = !dirty;
        save.classList.toggle('is-dirty', dirty);
      }
      input.addEventListener('input', check);
      check();
    }

    wireDirty(TPL,  'cl-template-notes-input', 'cl-template-notes-save');
    wireDirty(SESS, 'cl-session-notes-input',  'cl-session-notes-save');

    // Cascade confirm. Intake principle: every action gets a reaction.
    // upcoming = total future sessions for this template (includes this one).
    // We warn whenever upcoming > 1, since editing this affects sessions
    // beyond the one the user is currently looking at.
    if (TPL) {
      TPL.addEventListener('submit', function(ev){
        var upcoming = parseInt(TPL.dataset.upcoming, 10) || 0;
        var input    = document.getElementById('cl-template-notes-input');
        var changed  = (input.value || '') !== (TPL.dataset.original || '');
        if (!changed) { ev.preventDefault(); return; }
        if (upcoming <= 1) return;
        if (!window.IntakeConfirm) {
          if (!window.confirm('Update class notes on ' + upcoming + ' upcoming session(s)?')) {
            ev.preventDefault();
          }
          return;
        }
        ev.preventDefault();
        window.IntakeConfirm.show({
          title:       'Update class notes?',
          message:     'These notes will be shown to staff on ' + upcoming + ' upcoming session(s), and included in customer booking confirmations for any new registrations.',
          confirmText: 'Update notes',
          cancelText:  'Keep current'
        }).then(function(ok){ if (ok) TPL.submit(); });
      });
    }
  })();
</script>
@endpush

@endsection
