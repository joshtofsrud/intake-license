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
.cl-modal{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);width:100%;max-width:480px;padding:24px}
.cl-modal-title{font-size:15px;font-weight:600;margin-bottom:18px;color:var(--ia-text)}
.cl-field{margin-bottom:14px}
.cl-label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-muted);font-weight:500;margin-bottom:5px}
.cl-modal-footer{display:flex;justify-content:flex-end;gap:8px;margin-top:20px;padding-top:16px;border-top:0.5px solid var(--ia-border)}
.cl-action-btn{width:28px;height:28px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;color:var(--ia-text-muted);background:none;border:none;cursor:pointer;transition:all var(--ia-t)}
.cl-action-btn:hover{background:var(--ia-hover);color:var(--ia-text)}
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

<nav class="cl-subnav">
  <a href="{{ route('tenant.classes.templates') }}" class="cl-subnav-tab">Templates</a>
  <a href="{{ route('tenant.classes.sessions') }}" class="cl-subnav-tab is-active">Schedule</a>
  <a href="{{ route('tenant.classes.memberships') }}" class="cl-subnav-tab">Memberships</a>
  <a href="{{ route('tenant.classes.packs') }}" class="cl-subnav-tab">Packs</a>
</nav>

@if(session('success'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('success') }}</div>
@endif

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
  <a href="{{ request()->fullUrlWithQuery(['from' => now()->startOfWeek()->format('Y-m-d')]) }}" class="cl-week-btn">
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
        $isFull = $pct >= 100;
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
            @if($session->status === 'scheduled')
              <form method="POST" action="{{ $updateUrl }}" style="display:inline">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="confirmed">
                <button type="submit" class="ia-btn ia-btn--ghost" style="font-size:12px;padding:5px 12px">Confirm session</button>
              </form>
            @endif
            @if(!in_array($session->status, ['cancelled','completed']))
              <button type="button"
                class="ia-btn ia-btn--ghost"
                style="font-size:12px;padding:5px 12px;color:#EF4444"
                onclick="confirmCancel('{{ $updateUrl }}')">
                Cancel session
              </button>
            @endif
            <a href="{{ $showUrl }}" class="ia-btn ia-btn--ghost" style="font-size:12px;padding:5px 12px">Full detail →</a>
          </div>

          @if($session->active_registrations_count > 0)
            <table class="cl-reg-table">
              <thead>
                <tr>
                  <th>Customer</th>
                  <th>Payment</th>
                  <th>Status</th>
                  <th></th>
                </tr>
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
                    <td>
                      <span class="cl-status-pill {{ $reg->status }}" style="font-size:10px">{{ ucfirst(str_replace('_',' ',$reg->status)) }}</span>
                    </td>
                    <td style="text-align:right">
                      @if($reg->status === 'registered')
                        <form method="POST" action="{{ route('tenant.classes.registrations.checkin', ['subdomain' => $sub, 'id' => $reg->id]) }}" style="display:inline">
                          @csrf
                          <button type="submit" class="cl-action-btn" title="Check in">✓</button>
                        </form>
                      @endif
                      <form method="POST" action="{{ route('tenant.classes.registrations.cancel', ['subdomain' => $sub, 'id' => $reg->id]) }}" style="display:inline" onsubmit="return confirm('Cancel this registration?')">
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
                  <input type="text" name="customer_id" class="cl-input" placeholder="Customer UUID">
                </div>
                <div>
                  <label class="cl-label">Payment</label>
                  <select name="payment_method" class="cl-select">
                    <option value="cash">Cash</option>
                    <option value="per_class">Per class</option>
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
@endif

{{-- Cancel session confirmation modal --}}
<div class="cl-modal-overlay" id="cancel-modal">
  <div class="cl-modal">
    <div class="cl-modal-title">Cancel this session?</div>
    <p style="font-size:13px;color:var(--ia-text-muted);margin-bottom:20px">Registered customers will not be automatically notified. You can notify them manually via Campaigns.</p>
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
    <form method="POST" action="{{ route('tenant.classes.sessions.store') }}">
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
      <div class="cl-field">
        <label class="cl-label">Date & time</label>
        <input type="datetime-local" name="starts_at" class="cl-input" required min="{{ now()->format('Y-m-d\TH:i') }}">
      </div>
      <div class="cl-field">
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
  window.toggleSession = function(id){
    document.getElementById('session-' + id).classList.toggle('is-open');
  };
  window.openAddModal  = function(){ document.getElementById('add-modal').classList.add('is-open'); }
  window.closeAddModal = function(){ document.getElementById('add-modal').classList.remove('is-open'); }

  window.confirmCancel = function(url){
    document.getElementById('cancel-form').action = url;
    document.getElementById('cancel-modal').classList.add('is-open');
  }
  window.closeCancelModal = function(){
    document.getElementById('cancel-modal').classList.remove('is-open');
  }

  document.getElementById('cancel-modal').addEventListener('click', function(e){
    if(e.target === this) closeCancelModal();
  });

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){ closeAddModal(); closeCancelModal(); }
  });
})();
</script>
@endpush
