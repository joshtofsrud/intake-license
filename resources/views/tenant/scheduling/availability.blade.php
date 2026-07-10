@extends('layouts.tenant.app')

{{-- MARKER-PATCH-624 — Scheduling: my availability (all staff). --}}

@section('title', 'Scheduling · Availability')

@push('styles')
<style>
.av-sub { display:flex; gap:20px; border-bottom:.5px solid var(--ia-border); margin-bottom:18px; }
.av-sub a { padding:11px 2px; font-size:13px; color:var(--ia-text-muted); border-bottom:2px solid transparent; margin-bottom:-.5px; text-decoration:none; }
.av-sub a.on { color:var(--ia-text); border-bottom-color:var(--ia-accent); font-weight:600; }
.av-card { border:.5px solid var(--ia-border); border-radius:12px; background:var(--ia-surface); padding:18px; max-width:640px; }
.av-grid { display:grid; grid-template-columns:96px repeat(7,1fr); gap:5px; }
.av-h { font-size:10px; color:var(--ia-text-muted); text-align:center; padding:4px; text-transform:uppercase; letter-spacing:.04em; }
.av-d { font-size:11.5px; color:var(--ia-text-muted); display:flex; align-items:center; }
.av-cell { height:34px; border-radius:6px; background:var(--ia-surface-2,#1a1a1a); border:1px solid var(--ia-border); cursor:pointer; }
.av-cell[data-p="available"] { background:rgba(190,242,100,.16); border-color:rgba(190,242,100,.45); }
.av-cell[data-p="prefer"] { background:rgba(96,165,250,.16); border-color:rgba(96,165,250,.45); }
.av-cell[data-p="unavailable"] { background:var(--ia-surface-2,#1a1a1a); border-color:var(--ia-border); opacity:.55; }
.av-leg { display:flex; gap:16px; margin-top:14px; font-size:11px; color:var(--ia-text-muted); align-items:center; flex-wrap:wrap; }
.av-leg i { display:inline-block; width:13px; height:13px; border-radius:4px; vertical-align:-2px; margin-right:5px; }
.av-btn { padding:8px 15px; border-radius:7px; font-size:12.5px; font-weight:600; cursor:pointer; border:none; background:var(--ia-accent); color:var(--ia-accent-text); }
</style>
@endpush

@section('content')
<div style="max-width:760px">
  <h1 style="font-size:19px;font-weight:700;margin-bottom:4px">Scheduling</h1>
  <div class="av-sub">
    @if(auth('tenant')->user()?->can('scheduling.build'))
      <a href="{{ route('tenant.scheduling.index') }}">Schedule builder</a>
    @endif
    @if(auth('tenant')->user()?->can('scheduling.timeoff'))
      <a href="{{ route('tenant.scheduling.timeoff') }}">Time off</a>
    @endif
    <a href="{{ route('tenant.scheduling.availability') }}" class="on">Availability</a>
    <a href="{{ route('tenant.scheduling.mine') }}">My schedule</a>
    @if(auth('tenant')->user()?->can('scheduling.build'))
      <a href="{{ route('tenant.scheduling.settings') }}">Settings</a>
    @endif
  </div>

  <p style="font-size:12.5px;color:var(--ia-text-muted);margin-bottom:16px">Tap a cell to cycle: <b>available → prefer → unavailable</b>. The builder flags shifts placed on your unavailable times (it doesn't block them).</p>

  <form method="POST" action="{{ route('tenant.scheduling.availability.store') }}" onsubmit="return avSerialize(this)">
    @csrf
    <input type="hidden" name="cells" id="av-cells">
    <div class="av-card">
      <div class="av-grid" style="margin-bottom:5px">
        <span></span>
        @foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d)<span class="av-h">{{ $d }}</span>@endforeach
      </div>
      @foreach(['morning' => 'Morning', 'afternoon' => 'Afternoon', 'evening' => 'Evening'] as $band => $label)
        <div class="av-grid" style="margin-bottom:5px">
          <span class="av-d">{{ $label }}</span>
          @for($day = 0; $day < 7; $day++)
            <button type="button" class="av-cell" data-day="{{ $day }}" data-band="{{ $band }}"
                    data-p="{{ $map[$day . ':' . $band] ?? 'available' }}" onclick="avCycle(this)"></button>
          @endfor
        </div>
      @endforeach
      <div class="av-leg">
        <span><i style="background:rgba(190,242,100,.4)"></i> Available</span>
        <span><i style="background:rgba(96,165,250,.4)"></i> Prefers</span>
        <span><i style="background:var(--ia-surface-2,#1a1a1a);border:1px solid var(--ia-border)"></i> Unavailable</span>
        <span style="margin-left:auto"><button class="av-btn" type="submit">Save availability</button></span>
      </div>
    </div>
  </form>
</div>

@push('scripts')
<script>
function avCycle(el) {
  var next = { available: 'prefer', prefer: 'unavailable', unavailable: 'available' };
  el.dataset.p = next[el.dataset.p] || 'available';
}
function avSerialize(form) {
  var cells = [];
  document.querySelectorAll('.av-cell').forEach(function (c) {
    if (c.dataset.p !== 'available') cells.push(c.dataset.day + ':' + c.dataset.band + ':' + c.dataset.p);
  });
  document.getElementById('av-cells').value = cells.join(',');
  return true;
}
</script>
@endpush
@endsection

