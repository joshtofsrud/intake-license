@extends('layouts.tenant.app')
@php $pageTitle = $session->template->name . ' — ' . $session->starts_at->format('M j, Y'); @endphp

@push('styles')
<style>
.cl-back{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--ia-text-muted);text-decoration:none;margin-bottom:16px;transition:color var(--ia-t)}
.cl-back:hover{color:var(--ia-text)}
.cl-detail-grid{display:grid;grid-template-columns:1fr 280px;gap:16px;align-items:start}
.cl-main-col{display:flex;flex-direction:column;gap:16px}
.cl-side-col{display:flex;flex-direction:column;gap:16px}
.cl-card{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
.cl-card-head{padding:14px 16px;border-bottom:0.5px solid var(--ia-border);display:flex;align-items:center;justify-content:space-between}
.cl-card-title{font-size:13px;font-weight:500;color:var(--ia-text)}
.cl-card-body{padding:16px}
.cl-stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--ia-border)}
.cl-stat{background:var(--ia-surface);padding:14px 16px}
.cl-stat-label{font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;margin-bottom:4px}
.cl-stat-value{font-size:22px;font-weight:600;color:var(--ia-text);font-variant-numeric:tabular-nums;line-height:1}
.cl-stat-sub{font-size:12px;color:var(--ia-text-muted);margin-top:3px}
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
.cl-status-pill.cancelled{background:var(--ia-surface-2);color:var(--ia-text-muted)}
.cl-reg-table{width:100%;border-collapse:collapse;font-size:13px}
.cl-reg-table th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;padding:8px 14px;border-bottom:0.5px solid var(--ia-border);background:var(--ia-surface-2)}
.cl-reg-table td{padding:11px 14px;border-bottom:0.5px solid var(--ia-border);color:var(--ia-text);vertical-align:middle}
.cl-reg-table tr:last-child td{border-bottom:none}
.cl-reg-table tbody tr:hover td{background:var(--ia-hover)}
.cl-reg-name{font-weight:500}
.cl-reg-email{font-size:12px;color:var(--ia-text-muted);margin-top:1px}
.cl-pay-method{display:inline-flex;align-items:center;padding:2px 7px;border-radius:20px;font-size:11px;background:var(--ia-surface-2);color:var(--ia-text-muted)}
.cl-reg-actions{display:flex;gap:4px;justify-content:flex-end}
.cl-action-btn{height:28px;padding:0 10px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;color:var(--ia-text-muted);background:none;border:0.5px solid var(--ia-border);cursor:pointer;transition:all var(--ia-t);white-space:nowrap}
.cl-action-btn:hover{background:var(--ia-hover);color:var(--ia-text);border-color:var(--ia-border-strong)}
.cl-action-btn.danger:hover{background:rgba(239,68,68,.08);color:#EF4444;border-color:rgba(239,68,68,.3)}
.cl-action-btn.success{color:#16a34a;border-color:rgba(34,197,94,.3)}
.cl-action-btn.success:hover{background:rgba(34,197,94,.08)}
.cl-waitlist-pos{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:rgba(239,68,68,.1);color:#EF4444;font-size:11px;font-weight:600}
.cl-section-label{font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;padding:10px 14px;background:var(--ia-surface-2);border-bottom:0.5px solid var(--ia-border)}
.cl-add-reg-form{padding:16px;border-top:0.5px solid var(--ia-border);background:var(--ia-surface-2)}
.cl-add-reg-grid{display:grid;grid-template-columns:1fr 160px auto;gap:8px;align-items:end}
.cl-label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-muted);font-weight:500;margin-bottom:5px}
.cl-input,.cl-select{padding:8px 11px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;outline:none;transition:border var(--ia-t);width:100%;font-family:inherit}
.cl-input:focus,.cl-select:focus{border-color:var(--ia-accent)}
.cl-select{appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10' fill='none' stroke='rgba(255,255,255,.4)'><path d='M2 4l3 3 3-3' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/></svg>");background-repeat:no-repeat;background-position:right 10px center;padding-right:28px}
.cl-info-row{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:0.5px solid var(--ia-border);font-size:13px}
.cl-info-row:last-child{border-bottom:none}
.cl-info-label{color:var(--ia-text-muted)}
.cl-info-value{color:var(--ia-text);font-weight:500;text-align:right}
.cl-session-actions{display:flex;flex-direction:column;gap:6px}
.cl-session-action-btn{width:100%;padding:8px 12px;border-radius:var(--ia-r-md);font-size:13px;text-align:left;cursor:pointer;transition:all var(--ia-t);background:var(--ia-surface);border:0.5px solid var(--ia-border);color:var(--ia-text)}
.cl-session-action-btn:hover{background:var(--ia-hover);border-color:var(--ia-border-strong)}
.cl-session-action-btn.danger{color:#EF4444;border-color:rgba(239,68,68,.25)}
.cl-session-action-btn.danger:hover{background:rgba(239,68,68,.06)}
.cl-empty-reg{padding:32px;text-align:center;color:var(--ia-text-muted);font-size:13px}
@media(max-width:900px){.cl-detail-grid{grid-template-columns:1fr}}
</style>
@endpush

@section('content')

<a href="{{ route('tenant.classes.sessions') }}" class="cl-back">
  <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M9 2L5 7l4 5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
  Back to schedule
</a>

@if(session('success'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('success') }}</div>
@endif
@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
@endif

<div class="ia-page-head" style="margin-bottom:16px">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $session->template->name }}</h1>
    <p class="ia-page-subtitle">
      {{ $session->starts_at->format('l, F j, Y') }} ·
      {{ $session->starts_at->format('g:i A') }} – {{ $session->ends_at->format('g:i A') }}
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
      $active   = $session->registrations->whereIn('status', ['registered','checked_in'])->count();
      $checkedIn= $session->registrations->where('status','checked_in')->count();
      $waitlist = $session->registrations->where('status','waitlisted')->count();
      $cap      = $session->capacity_snapshot;
      $pct      = $cap > 0 ? min(100, round($active / $cap * 100)) : 0;
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
          <div class="cl-stat-value {{ $pct >= 100 ? '' : '' }}">{{ max(0, $cap - $active) }}</div>
          <div class="cl-stat-sub">{{ $pct }}% full</div>
        </div>
      </div>
      <div class="cl-capacity-bar">
        <div class="cl-capacity-fill {{ $pct >= 100 ? 'is-full' : '' }}" style="width:{{ $pct }}%"></div>
      </div>
    </div>

    {{-- Registration roster --}}
    <div class="cl-card">
      <div class="cl-card-head">
        <span class="cl-card-title">Roster</span>
        <span style="font-size:12px;color:var(--ia-text-muted)">{{ $active }} registered · {{ $checkedIn }} checked in</span>
      </div>

      @php
        $registered = $session->registrations->whereIn('status',['registered','checked_in'])->sortBy('registered_at');
        $waitlisted = $session->registrations->where('status','waitlisted')->sortBy('waitlist_position');
        $cancelled  = $session->registrations->whereIn('status',['cancelled','no_show'])->sortByDesc('cancelled_at');
      @endphp

      @if($registered->isEmpty() && $waitlisted->isEmpty())
        <div class="cl-empty-reg">No registrations yet.</div>
      @else
        {{-- Active registrations --}}
        @if($registered->isNotEmpty())
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
                        <form method="POST" action="{{ route('tenant.classes.registrations.checkin', ['subdomain' => request()->route('subdomain'), 'id' => $reg->id]) }}">
                          @csrf
                          <button type="submit" class="cl-action-btn success">Check in</button>
                        </form>
                        <form method="POST" action="{{ route('tenant.classes.registrations.noshow', ['subdomain' => request()->route('subdomain'), 'id' => $reg->id]) }}" onsubmit="return confirm('Mark as no-show?')">
                          @csrf
                          <button type="submit" class="cl-action-btn danger">No-show</button>
                        </form>
                      @endif
                      @if(in_array($reg->status, ['registered','checked_in']))
                        <form method="POST" action="{{ route('tenant.classes.registrations.cancel', ['subdomain' => request()->route('subdomain'), 'id' => $reg->id]) }}" onsubmit="return confirm('Cancel this registration?')">
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

        {{-- Waitlist --}}
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
                      <form method="POST" action="{{ route('tenant.classes.registrations.cancel', ['subdomain' => request()->route('subdomain'), 'id' => $reg->id]) }}" onsubmit="return confirm('Remove from waitlist?')">
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
      @endif

      {{-- Add registration --}}
      @if(!in_array($session->status, ['cancelled','completed']))
        <div class="cl-add-reg-form">
          <form method="POST" action="{{ route('tenant.classes.sessions.register', ['subdomain' => request()->route('subdomain'), 'id' => $session->id]) }}">
            @csrf
            <div class="cl-add-reg-grid">
              <div>
                <label class="cl-label">Add customer by ID</label>
                <input type="text" name="customer_id" class="cl-input" placeholder="Customer UUID" required>
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
              <button type="submit" class="ia-btn ia-btn--primary" style="white-space:nowrap;align-self:flex-end">Add</button>
            </div>
          </form>
        </div>
      @endif

      {{-- Cancelled / no-show --}}
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

    {{-- Session info --}}
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
          <span class="cl-info-value">{{ $session->starts_at->format('g:i A') }} – {{ $session->ends_at->format('g:i A') }}</span>
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

    {{-- Session actions --}}
    @if(!in_array($session->status, ['cancelled','completed']))
      <div class="cl-card">
        <div class="cl-card-head"><span class="cl-card-title">Actions</span></div>
        <div class="cl-card-body">
          <div class="cl-session-actions">
            @if($session->status === 'confirmed')
              <form method="POST" action="{{ route('tenant.classes.sessions.update', ['subdomain' => request()->route('subdomain'), 'id' => $session->id]) }}">
                @csrf @method('PATCH')
                <input type="hidden" name="status" value="completed">
                <button type="submit" class="cl-session-action-btn">Mark completed</button>
              </form>
            @endif
            <form method="POST" action="{{ route('tenant.classes.sessions.update', ['subdomain' => request()->route('subdomain'), 'id' => $session->id]) }}" onsubmit="return confirm('Cancel this session? Registered customers will not be automatically notified.')">
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

@endsection
