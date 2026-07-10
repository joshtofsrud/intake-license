@extends('layouts.tenant.app')

{{-- MARKER-PATCH-623 — Scheduling: builder (manager). --}}

@section('title', 'Scheduling')

@push('styles')
<style>
.sc-sub { display:flex; gap:20px; border-bottom:.5px solid var(--ia-border); margin-bottom:18px; }
.sc-sub a { padding:11px 2px; font-size:13px; color:var(--ia-text-muted); border-bottom:2px solid transparent; margin-bottom:-.5px; text-decoration:none; }
.sc-sub a.on { color:var(--ia-text); border-bottom-color:var(--ia-accent); font-weight:600; }
.sc-sub .b { font-size:9px; background:#F59E0B; color:#000; border-radius:8px; padding:1px 6px; margin-left:5px; font-weight:700; }
.sc-bar { display:flex; align-items:center; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
.sc-btn { padding:7px 13px; border-radius:7px; font-size:12px; font-weight:600; cursor:pointer; border:.5px solid var(--ia-border-2,rgba(255,255,255,.2)); background:transparent; color:var(--ia-text); text-decoration:none; }
.sc-btn.p { background:var(--ia-accent); color:var(--ia-accent-text); border:none; }
.sc-grid { border:.5px solid var(--ia-border); border-radius:12px; overflow-x:auto; background:var(--ia-surface); }
.sc-row { display:grid; grid-template-columns:150px repeat(7, minmax(110px,1fr)); border-bottom:.5px solid var(--ia-border); min-width:920px; }
.sc-row:last-child { border-bottom:none; }
.sc-c { padding:8px; border-right:.5px dashed var(--ia-border); min-height:64px; position:relative; }
.sc-c:last-child { border-right:none; }
.sc-c.hd { background:var(--ia-surface-2,#1a1a1a); min-height:auto; padding:9px 10px; font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:var(--ia-text-muted); font-weight:600; }
.sc-c.name { background:var(--ia-surface-2,#1a1a1a); font-weight:600; font-size:12.5px; display:flex; flex-direction:column; justify-content:center; gap:2px; }
.sc-c.name .r { font-size:10px; color:var(--ia-text-muted); font-weight:400; }
.sc-shift { background:rgba(190,242,100,.12); border:1px solid rgba(190,242,100,.4); border-radius:6px; padding:4px 6px; font-size:10.5px; margin-bottom:4px; position:relative; }
.sc-shift.draft { border-style:dashed; opacity:.85; }
.sc-shift .t { font-weight:600; }
.sc-shift .l { font-size:9px; color:var(--ia-text-muted); }
.sc-shift .x { position:absolute; top:2px; right:4px; background:none; border:none; color:var(--ia-text-muted); cursor:pointer; font-size:11px; line-height:1; padding:2px; }
.sc-shift .x:hover { color:#f87171; }
.sc-warn { display:inline-flex; align-items:center; justify-content:center; width:13px; height:13px; border-radius:50%; background:rgba(245,158,11,.2); color:#F59E0B; font-size:9.5px; font-weight:800; margin-left:5px; vertical-align:1px; position:relative; }
.sc-warn::after { content:attr(data-tip); position:absolute; bottom:calc(100% + 6px); left:50%; transform:translateX(-50%); background:var(--ia-bg,#0c0c0c); border:1px solid var(--ia-border-2,rgba(255,255,255,.25)); color:var(--ia-text); font-size:10.5px; font-weight:500; padding:5px 9px; border-radius:6px; white-space:nowrap; opacity:0; pointer-events:none; transition:opacity .12s; z-index:30; }
.sc-warn:hover::after { opacity:1; }
.sc-off { background:repeating-linear-gradient(45deg,transparent,transparent 5px,rgba(248,113,113,.08) 5px,rgba(248,113,113,.08) 10px); border:1px dashed rgba(248,113,113,.35); border-radius:6px; padding:5px; font-size:9.5px; color:#f87171; text-align:center; }
.sc-add { border:1px dashed var(--ia-border-2,rgba(255,255,255,.2)); border-radius:6px; padding:4px; font-size:10px; color:var(--ia-text-muted); text-align:center; cursor:pointer; opacity:0; transition:opacity .12s; background:none; width:100%; }
.sc-c:hover .sc-add { opacity:1; }
.sc-drafts { font-size:11px; color:#F59E0B; }
/* modal */
.sc-mov { position:fixed; inset:0; background:rgba(0,0,0,.6); display:none; align-items:center; justify-content:center; z-index:50; }
.sc-mov.on { display:flex; }
#sc-tpl-menu.on { display:block !important; }
.sc-modal { width:400px; background:var(--ia-bg,#0c0c0c); border:1px solid var(--ia-border-2,rgba(255,255,255,.2)); border-radius:14px; }
.sc-mh { padding:15px 18px; border-bottom:.5px solid var(--ia-border); font-weight:700; display:flex; justify-content:space-between; }
.sc-mb { padding:18px; }
.sc-mb label { display:block; font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:var(--ia-text-muted); margin:0 0 5px; font-weight:600; }
.sc-mb input, .sc-mb select { width:100%; padding:9px 11px; margin-bottom:13px; background:var(--ia-surface-2,#1a1a1a); border:1px solid var(--ia-border); border-radius:7px; color:var(--ia-text); font-size:13px; }
.sc-mb .two { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.sc-mf { padding:13px 18px; border-top:.5px solid var(--ia-border); display:flex; justify-content:flex-end; gap:9px; }
</style>
@endpush

@section('content')
<div style="max-width:1080px">
  <h1 style="font-size:19px;font-weight:700;margin-bottom:4px">Scheduling</h1>

  <div class="sc-sub">
    <a href="{{ route('tenant.scheduling.index') }}" class="on">Schedule builder</a>
    @if(auth('tenant')->user()?->can('scheduling.timeoff'))
      <a href="{{ route('tenant.scheduling.timeoff') }}">Time off @if($pendingTimeOff > 0)<span class="b">{{ $pendingTimeOff }}</span>@endif</a>
    @endif
    @if($set['availability'])
      <a href="{{ route('tenant.scheduling.availability') }}">Availability</a>
    @endif
    <a href="{{ route('tenant.scheduling.mine') }}">My schedule</a>
    <a href="{{ route('tenant.scheduling.settings') }}">Settings</a>
  </div>

  <div class="sc-bar">
    <a class="sc-btn" href="{{ route('tenant.scheduling.index', ['week' => $weekStart->copy()->subWeek()->toDateString()]) }}">◀</a>
    <span style="font-weight:600;font-size:13px">Week of {{ $weekStart->format('M j') }} – {{ $weekStart->copy()->endOfWeek()->format('M j, Y') }}</span>
    <a class="sc-btn" href="{{ route('tenant.scheduling.index', ['week' => $weekStart->copy()->addWeek()->toDateString()]) }}">▶</a>
    @if($draftCount > 0)<span class="sc-drafts">{{ $draftCount }} draft shift{{ $draftCount > 1 ? 's' : '' }} — staff can't see them yet</span>@endif
    <span style="margin-left:auto;display:flex;gap:8px">
      <form method="POST" action="{{ route('tenant.scheduling.copy-week', ['week' => $weekStart->toDateString()]) }}">@csrf
        <button class="sc-btn" type="submit">Copy last week</button>
      </form>
      {{-- MARKER-PATCH-624 — templates --}}
      <span style="position:relative">
        <button class="sc-btn" type="button" onclick="document.getElementById('sc-tpl-menu').classList.toggle('on')">Templates ▾</button>
        <span id="sc-tpl-menu" style="display:none;position:absolute;right:0;top:36px;z-index:20;background:var(--ia-bg,#0c0c0c);border:1px solid var(--ia-border-2,rgba(255,255,255,.2));border-radius:9px;min-width:220px;padding:6px">
          @forelse($templates as $tpl)
            <form method="POST" action="{{ route('tenant.scheduling.template.apply', ['templateId' => $tpl->id, 'week' => $weekStart->toDateString()]) }}">@csrf
              <button type="submit" style="display:block;width:100%;text-align:left;background:none;border:none;color:var(--ia-text);font-size:12.5px;padding:7px 9px;border-radius:6px;cursor:pointer">Apply "{{ $tpl->name }}"</button>
            </form>
          @empty
            <span style="display:block;font-size:11.5px;color:var(--ia-text-muted);padding:7px 9px">No templates yet</span>
          @endforelse
          <form method="POST" action="{{ route('tenant.scheduling.template.save', ['week' => $weekStart->toDateString()]) }}" style="border-top:.5px solid var(--ia-border);margin-top:4px;padding:7px 9px;display:flex;gap:6px">
            @csrf
            <input type="text" name="name" required maxlength="80" placeholder="save week as…" style="flex:1;background:var(--ia-surface-2,#1a1a1a);border:1px solid var(--ia-border);color:var(--ia-text);border-radius:6px;padding:5px 8px;font-size:11.5px">
            <button class="sc-btn" type="submit" style="padding:4px 10px;font-size:11px">Save</button>
          </form>
        </span>
      </span>
      <form method="POST" action="{{ route('tenant.scheduling.publish', ['week' => $weekStart->toDateString()]) }}"
            onsubmit="return confirm('Publish this week? Staff will see their shifts and get notified.')">@csrf
        <button class="sc-btn p" type="submit">Publish week →</button>
      </form>
    </span>
  </div>

  <div class="sc-grid">
    @if($set['demand_overlay'] && !empty($demand))
      {{-- MARKER-PATCH-624 — booking demand from the appointment calendar --}}
      <div class="sc-row" style="min-height:auto">
        <div class="sc-c" style="min-height:auto;padding:6px 10px;font-size:9px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-muted);display:flex;align-items:center">Booking demand</div>
        @for($i = 0; $i < 7; $i++)
          <div class="sc-c" style="min-height:auto;padding:5px 8px">
            <div style="display:flex;align-items:flex-end;gap:2px;height:22px" title="bookings by time of day">
              @foreach($demand['bands'][$i] as $n)
                <span style="flex:1;border-radius:1px;background:rgba(96,165,250,.55);height:{{ $n > 0 ? max(15, (int) round($n / $demand['max'] * 100)) : 4 }}%;{{ $n === 0 ? 'opacity:.25;' : '' }}"></span>
              @endforeach
            </div>
          </div>
        @endfor
      </div>
    @endif
    <div class="sc-row">
      <div class="sc-c hd">Staff</div>
      @foreach($days as $d)<div class="sc-c hd">{{ $d->format('D') }} <span style="opacity:.6">{{ $d->format('j') }}</span></div>@endforeach
    </div>
    @foreach($staff as $m)
      <div class="sc-row">
        <div class="sc-c name">{{ $m->name }}<span class="r">{{ $m->role }}</span></div>
        @for($i = 0; $i < 7; $i++)
          @php $cell = $grid[$m->id][$i]; @endphp
          <div class="sc-c">
            @if($cell['off'])
              <div class="sc-off">Time off ✓</div>
            @else
              @foreach($cell['shifts'] as $sh)
                <div class="sc-shift {{ $sh->published_at ? '' : 'draft' }}">
                  <div class="t">{{ tlocal($sh->starts_at, 'g:ia') }}–{{ tlocal($sh->ends_at, 'g:ia') }}@if(!empty($sh->avail_conflict))<span class="sc-warn" data-tip="Outside {{ $m->name }}'s stated availability">!</span>@endif</div>
                  @if($sh->label)<div class="l">{{ $sh->label }}</div>@endif
                  <form method="POST" action="{{ route('tenant.scheduling.shift.delete', $sh->id) }}" style="display:inline"
                        onsubmit="return confirm('Remove this shift?')">@csrf<button class="x" type="submit">×</button></form>
                </div>
              @endforeach
              <button type="button" class="sc-add"
                onclick="scAddShift(@js($m->id), @js($m->name), '{{ $days[$i]->toDateString() }}', '{{ $days[$i]->format('D M j') }}')">+ shift</button>
            @endif
          </div>
        @endfor
      </div>
    @endforeach
  </div>

  <p style="font-size:11px;color:var(--ia-text-muted);margin-top:12px">Shifts are drafts (dashed) until you publish the week. Approved time off blocks the cell. Overnight shifts: set an end time earlier than the start and it rolls to the next day.</p>
</div>

{{-- add-shift modal --}}
<div class="sc-mov" id="sc-add-modal">
  <div class="sc-modal">
    <form method="POST" action="{{ route('tenant.scheduling.shift.store') }}">@csrf
      <input type="hidden" name="tenant_user_id" id="sc-uid">
      <input type="hidden" name="date" id="sc-date">
      <div class="sc-mh"><span id="sc-title">Add shift</span><span style="cursor:pointer" onclick="document.getElementById('sc-add-modal').classList.remove('on')">×</span></div>
      <div class="sc-mb">
        <div class="two">
          <div><label>Start</label><input type="time" name="start_time" value="09:00" required></div>
          <div><label>End</label><input type="time" name="end_time" value="17:00" required></div>
        </div>
        <label>Label (optional)</label>
        <input type="text" name="label" maxlength="80" placeholder="Shop, Routes, …">
      </div>
      <div class="sc-mf">
        <button type="button" class="sc-btn" onclick="document.getElementById('sc-add-modal').classList.remove('on')">Cancel</button>
        <button type="submit" class="sc-btn p">Add shift</button>
      </div>
    </form>
  </div>
</div>

@push('scripts')
<script>
function scAddShift(uid, name, date, dateLabel) {
  document.getElementById('sc-uid').value = uid;
  document.getElementById('sc-date').value = date;
  document.getElementById('sc-title').textContent = name + ' · ' + dateLabel;
  document.getElementById('sc-add-modal').classList.add('on');
}
</script>
@endpush
@endsection

