{{-- MARKER-CLASSES-UI1: one tab row for every Classes screen. --}}
@php
  $clTz = tenant()->timezone();
  $clCheckinCount = \App\Models\Tenant\TenantClassSession::where('tenant_id', tenant()->id)
      ->whereIn('status', ['scheduled', 'confirmed'])
      ->whereBetween('starts_at', [now($clTz)->startOfDay()->utc(), now($clTz)->endOfDay()->utc()])
      ->where('ends_at', '>', now())
      ->count();
  $clTab = $active ?? '';
@endphp
<style>
.cl-tabs{display:flex;align-items:flex-end;gap:2px;margin:4px 0 20px;border-bottom:0.5px solid var(--ia-border);overflow-x:auto;scrollbar-width:none}
.cl-tabs::-webkit-scrollbar{display:none}
.cl-tab{padding:9px 14px;font-size:13px;color:var(--ia-text-muted);border-bottom:2px solid transparent;margin-bottom:-0.5px;text-decoration:none;white-space:nowrap;display:inline-flex;align-items:center;gap:6px;transition:color var(--ia-t),border-color var(--ia-t)}
.cl-tab:hover{color:var(--ia-text)}
.cl-tab.is-active{color:var(--ia-text);border-bottom-color:var(--ia-accent);font-weight:500}
.cl-tab-count{font-size:10.5px;font-weight:600;min-width:17px;height:17px;padding:0 5px;border-radius:9px;background:var(--ia-accent-soft);color:var(--ia-accent);display:inline-flex;align-items:center;justify-content:center;font-variant-numeric:tabular-nums}
.cl-tabs-spacer{flex:1}
</style>
<nav class="cl-tabs" aria-label="Classes">
  <a href="{{ route('tenant.classes.sessions') }}" class="cl-tab {{ $clTab === 'schedule' ? 'is-active' : '' }}">Schedule</a>
  <a href="{{ route('tenant.classes.checkin') }}" class="cl-tab {{ $clTab === 'checkin' ? 'is-active' : '' }}">Check-in @if($clCheckinCount)<span class="cl-tab-count">{{ $clCheckinCount }}</span>@endif</a>
  <a href="{{ route('tenant.classes.templates') }}" class="cl-tab {{ $clTab === 'types' ? 'is-active' : '' }}">Class types</a>
  <a href="{{ route('tenant.classes.schedules') }}" class="cl-tab {{ $clTab === 'schedules' ? 'is-active' : '' }}">Recurring schedules</a>
  <a href="{{ route('tenant.classes.memberships') }}" class="cl-tab {{ $clTab === 'passes' ? 'is-active' : '' }}">Passes &amp; memberships</a>
  <a href="{{ route('tenant.classes.reports') }}" class="cl-tab {{ $clTab === 'analytics' ? 'is-active' : '' }}">Analytics</a>
  <span class="cl-tabs-spacer"></span>
  <a href="{{ route('tenant.classes.settings') }}" class="cl-tab {{ $clTab === 'settings' ? 'is-active' : '' }}">Rules &amp; settings</a>
</nav>
