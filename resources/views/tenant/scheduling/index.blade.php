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
/* MARKER-TPL-MANAGE */
.sc-tpl { display:flex; align-items:center; gap:8px; border-radius:7px; padding:7px 8px; }
.sc-tpl:hover { background:var(--ia-surface-2,#1a1a1a); }
.sc-tpl-main { display:block; width:100%; text-align:left; background:none; border:none; padding:0; cursor:pointer; color:var(--ia-text); font-family:inherit; }
.sc-tpl-name { display:block; font-size:12.5px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sc-tpl-meta { display:block; font-size:10.5px; color:var(--ia-text-muted); margin-top:1px; }
.sc-tpl-del { flex:0 0 auto; background:none; border:none; color:var(--ia-text-muted); font-size:14px; line-height:1; cursor:pointer; padding:4px 6px; border-radius:5px; opacity:0; transition:opacity .13s,color .13s,background .13s; font-family:inherit; }
.sc-tpl:hover .sc-tpl-del, .sc-tpl-del:focus-visible { opacity:1; }
.sc-tpl-del:hover { color:#F0999B; background:rgba(240,120,120,.12); }
.sc-tpl-confirm { border:1px solid rgba(240,120,120,.4); background:rgba(240,120,120,.07); border-radius:7px; padding:9px 10px; margin:4px 0; }
.sc-tpl-warn { border:1px solid rgba(245,158,11,.4); background:rgba(245,158,11,.08); border-radius:7px; padding:9px 10px; margin:4px 0; }
.sc-tpl-confirm .t, .sc-tpl-warn .t { font-size:12px; font-weight:600; line-height:1.45; }
.sc-tpl-warn .t { color:#FBBF24; }
.sc-tpl-confirm .s, .sc-tpl-warn .s { font-size:11px; color:var(--ia-text-muted); margin-top:3px; line-height:1.5; }
.sc-tpl-confirm .r, .sc-tpl-warn .r { display:flex; gap:6px; margin-top:9px; }
.sc-xs { background:none; border:1px solid var(--ia-border-2,rgba(255,255,255,.2)); color:var(--ia-text); border-radius:6px; padding:4px 10px; font-size:11px; font-weight:600; cursor:pointer; font-family:inherit; }
.sc-xs--danger { background:#E88B8B; border-color:#E88B8B; color:#160b0b; }
.sc-mf { padding:13px 18px; border-top:.5px solid var(--ia-border); display:flex; justify-content:flex-end; gap:9px; }
/* MARKER-SCHED-PHONE — tabs on one scrolling line on phones */
@media (max-width: 700px) {
  .sc-sub { overflow-x: auto; flex-wrap: nowrap; white-space: nowrap; scrollbar-width: none; gap: 16px; }
  .sc-sub::-webkit-scrollbar { display: none; }
  .sc-sub a { flex: none; }
}
/* MARKER-SCHED-PHONE — the builder on phones: one day at a time. */
.scm, .scm-sheet { display: none; }
@media (max-width: 700px) {
  .sc-grid, .sc-grid ~ p { display: none !important; }
  .sc-bar { display: grid !important; grid-template-columns: auto 1fr auto; gap: 8px; align-items: center; }
  .sc-bar > span:first-of-type { text-align: center; }
  .sc-bar .sc-drafts { grid-column: 1 / -1; order: 5; margin: 0; }
  .sc-bar > span[style*="margin-left:auto"] { grid-column: 1 / -1; margin: 0 !important; display: grid !important; grid-template-columns: 1fr 1fr 1.2fr; gap: 8px; }
  .sc-bar > span[style*="margin-left:auto"] form, .sc-bar > span[style*="margin-left:auto"] > span { display: block; }
  .sc-bar > span[style*="margin-left:auto"] .sc-btn { width: 100%; justify-content: center; }
  #sc-tpl-menu { left: 0 !important; right: auto !important; width: min(330px, 88vw); }
  .scm { display: block; margin-top: 12px; }
  .scm-strip { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; margin-bottom: 14px; }
  .scm-d { border-radius: 10px; background: var(--ia-surface); border: .5px solid var(--ia-border); padding: 7px 2px 6px;
    text-align: center; cursor: pointer; color: var(--ia-text); font: inherit; }
  .scm-d .w { font-size: 9.5px; letter-spacing: .06em; color: var(--ia-text-muted); text-transform: uppercase; }
  .scm-d .n { font-size: 15px; font-weight: 600; line-height: 1.3; }
  .scm-d .s { font-size: 10px; color: var(--ia-text-muted); }
  .scm-d .dem { height: 3px; border-radius: 2px; background: rgba(255,255,255,.08); margin: 5px 6px 0; overflow: hidden; }
  .scm-d .dem i { display: block; height: 100%; background: rgba(96,165,250,.8); }
  .scm-d.short .s { color: #f0a3a3; }
  .scm-d.on { background: var(--ia-accent); border-color: var(--ia-accent); }
  .scm-d.on .w, .scm-d.on .n, .scm-d.on .s { color: var(--ia-accent-text); }
  .scm-d.on .dem { background: rgba(0,0,0,.15); }
  .scm-d.on .dem i { background: var(--ia-accent-text); }
  .scm-day[hidden] { display: none; }
  .scm-dayh { display: flex; justify-content: space-between; align-items: baseline; margin: 4px 2px 8px; }
  .scm-dayh b { font-size: 16px; }
  .scm-dayh span { font-size: 12.5px; color: var(--ia-text-muted); }
  .scm-warn { font-size: 12.5px; background: rgba(226,75,74,.10); border: .5px solid rgba(226,75,74,.35); color: #f0b3b3;
    border-radius: 10px; padding: 9px 12px; margin-bottom: 10px; }
  .scm-rows { border: .5px solid var(--ia-border); border-radius: 14px; overflow: hidden; background: var(--ia-surface); }
  .scm-r { display: flex; align-items: center; gap: 12px; padding: 13px 14px; border-top: .5px solid var(--ia-border);
    cursor: pointer; width: 100%; background: none; border-left: 0; border-right: 0; border-bottom: 0; color: var(--ia-text); font: inherit; text-align: left; }
  .scm-r:first-child { border-top: 0; }
  .scm-r[disabled] { cursor: default; }
  .scm-who { flex: 1; min-width: 0; }
  .scm-who b { display: block; font-size: 14.5px; }
  .scm-who span { font-size: 12px; color: var(--ia-text-muted); }
  .scm-who .note { display: block; color: #f0c78a; font-size: 11.5px; margin-top: 2px; }
  .scm-chips { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
  .scm-sh { font-size: 12.5px; font-weight: 600; padding: 5px 10px; border-radius: 99px; white-space: nowrap;
    background: rgba(233,162,59,.12); color: var(--ia-accent); }
  .scm-sh.draft { outline: 1px dashed rgba(233,162,59,.5); }
  .scm-sh.off { background: none; color: var(--ia-text-muted); outline: .5px dashed var(--ia-border-2, rgba(255,255,255,.22)); }
  .scm-sh.to { background: rgba(96,165,250,.12); color: #93c5fd; }
  .scm-sheet { position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,.55); align-items: flex-end; }
  .scm-sheet.on { display: flex; }
  .scm-sheet-card { width: 100%; background: var(--ia-surface); border-radius: 18px 18px 0 0; border-top: .5px solid var(--ia-border);
    padding: 10px 18px calc(24px + env(safe-area-inset-bottom, 0px)); max-height: 85vh; overflow-y: auto; }
  .scm-grab { width: 38px; height: 4px; border-radius: 2px; background: rgba(255,255,255,.2); margin: 0 auto 12px; }
  .scm-sheet h3 { margin: 0 0 2px; font-size: 16px; }
  .scm-sheet .sub { font-size: 12.5px; color: var(--ia-text-muted); margin-bottom: 14px; }
  .scm-sheet label { font-size: 12px; color: var(--ia-text-muted); display: block; margin-bottom: 5px; }
  .scm-sheet input, .scm-sheet select { width: 100%; background: rgba(255,255,255,.07); border: 1px solid var(--ia-border); border-radius: 10px;
    padding: 10px 11px; color: var(--ia-text); font: inherit; font-size: 14px; margin-bottom: 12px; }
  .scm-two { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
  /* MARKER-SCHED-PHONE-2 — iOS gives time inputs an intrinsic minimum width,
     so without these the End box ran off the sheet and overlapped Start. */
  .scm-two > div { min-width: 0; }
  .scm-sheet input[type="time"] { min-width: 0; max-width: 100%; display: block; -webkit-appearance: none; appearance: none; text-align: center; }
  .scm-exist { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: .5px dashed var(--ia-border); font-size: 13.5px; }
  .scm-btns { display: flex; gap: 8px; margin-top: 4px; }
  .scm-btns .sc-btn { flex: 1; justify-content: center; padding: 12px; }
}
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
        {{-- MARKER-TPL-MANAGE — reopen the menu after a rejected save so the
             overwrite prompt isn't hidden behind a closed dropdown. --}}
        @if(session('tpl_overwrite'))
          <script>document.addEventListener('DOMContentLoaded',function(){var m=document.getElementById('sc-tpl-menu');if(m)m.classList.add('on');});</script>
        @endif
        <span id="sc-tpl-menu" style="display:none;position:absolute;right:0;top:36px;z-index:20;background:var(--ia-bg,#0c0c0c);border:1px solid var(--ia-border-2,rgba(255,255,255,.2));border-radius:9px;min-width:340px;padding:5px">
          {{-- MARKER-TPL-MANAGE — each row says what it holds, so two similar
               names can be told apart before one gets deleted. --}}
          <div style="font-size:10px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--ia-text-muted);padding:6px 9px 3px">Apply to this week</div>
          @forelse($templates as $tpl)
            <div class="sc-tpl" data-tpl-row="{{ $tpl->id }}">
              <form method="POST" action="{{ route('tenant.scheduling.template.apply', ['templateId' => $tpl->id, 'week' => $weekStart->toDateString()]) }}" style="flex:1;min-width:0">@csrf
                <button type="submit" class="sc-tpl-main">
                  <span class="sc-tpl-name">{{ $tpl->name }}</span>
                  <span class="sc-tpl-meta">
                    {{ $tpl->shift_count }} {{ Str::plural('shift', $tpl->shift_count) }} ·
                    {{ $tpl->people_count }} {{ Str::plural('person', $tpl->people_count) }} ·
                    {{ $tpl->last_applied_at ? 'last applied ' . tlocal_date($tpl->last_applied_at, 'M j') : 'never applied' }}
                  </span>
                </button>
              </form>
              <button type="button" class="sc-tpl-del" title="Delete template"
                      onclick="document.querySelector('[data-tpl-row=&quot;{{ $tpl->id }}&quot;]').style.display='none';document.querySelector('[data-tpl-confirm=&quot;{{ $tpl->id }}&quot;]').style.display='block'">&times;</button>
            </div>
            <div class="sc-tpl-confirm" data-tpl-confirm="{{ $tpl->id }}" style="display:none">
              <div class="t">Delete &ldquo;{{ $tpl->name }}&rdquo;?</div>
              <div class="s">{{ $tpl->shift_count }} {{ Str::plural('shift', $tpl->shift_count) }} across {{ $tpl->people_count }} {{ Str::plural('person', $tpl->people_count) }}. Shifts already on the calendar aren't touched — only the saved pattern goes.</div>
              <div class="r">
                <form method="POST" action="{{ route('tenant.scheduling.template.delete', ['templateId' => $tpl->id, 'week' => $weekStart->toDateString()]) }}" style="margin:0">
                  @csrf @method('DELETE')
                  <button type="submit" class="sc-xs sc-xs--danger">Delete template</button>
                </form>
                <button type="button" class="sc-xs"
                        onclick="document.querySelector('[data-tpl-confirm=&quot;{{ $tpl->id }}&quot;]').style.display='none';document.querySelector('[data-tpl-row=&quot;{{ $tpl->id }}&quot;]').style.display='flex'">Keep it</button>
              </div>
            </div>
          @empty
            <span style="display:block;font-size:11.5px;color:var(--ia-text-muted);padding:7px 9px">No templates yet</span>
          @endforelse

          {{-- MARKER-TPL-MANAGE — the name already exists: show the trade. --}}
          @if(session('tpl_overwrite'))
            @php $ow = session('tpl_overwrite'); @endphp
            <div class="sc-tpl-warn">
              <div class="t">&ldquo;{{ $ow['name'] }}&rdquo; already exists</div>
              <div class="s">Replacing it swaps its {{ $ow['has'] }} {{ Str::plural('shift', $ow['has']) }} for this week's. The old pattern can't be recovered.</div>
              <div class="r">
                <form method="POST" action="{{ route('tenant.scheduling.template.save', ['week' => $ow['week']]) }}" style="margin:0">
                  @csrf
                  <input type="hidden" name="name" value="{{ $ow['name'] }}">
                  <input type="hidden" name="confirm_overwrite" value="1">
                  <button type="submit" class="sc-xs" style="border-color:#FBBF24;color:#FBBF24">Replace it</button>
                </form>
                <button type="button" class="sc-xs" onclick="var f=document.getElementById('sc-tpl-name');f.focus();f.select()">Save as a new name</button>
              </div>
            </div>
          @endif

          <form method="POST" action="{{ route('tenant.scheduling.template.save', ['week' => $weekStart->toDateString()]) }}" style="border-top:.5px solid var(--ia-border);margin-top:4px;padding:7px 9px;display:flex;gap:6px">
            @csrf
            <input type="text" id="sc-tpl-name" name="name" required maxlength="80" value="{{ session('tpl_overwrite')['name'] ?? '' }}" placeholder="save this week as…" style="flex:1;background:var(--ia-surface-2,#1a1a1a);border:1px solid var(--ia-border);color:var(--ia-text);border-radius:6px;padding:5px 8px;font-size:11.5px">
            <button class="sc-btn" type="submit" style="padding:4px 10px;font-size:11px">Save</button>
          </form>
        </span>
      </span>
      <form method="POST" action="{{ route('tenant.scheduling.publish', ['week' => $weekStart->toDateString()]) }}"
            onsubmit="event.preventDefault(); var f = this; iaConfirm('Publish this week? Staff will see their shifts and get notified.').then(function (ok) { if (ok) { f.submit(); } });">@csrf{{-- MARKER-SCHED-PHONE: in-app dialog --}}
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
                        onsubmit="event.preventDefault(); var f = this; iaConfirm('Remove this shift?').then(function (ok) { if (ok) { f.submit(); } });">@csrf<button class="x" type="submit">×</button></form>
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

  {{-- MARKER-SCHED-PHONE — the builder on phones: a day strip (shifts, booking
       demand, short-staffed days in red), then that day's staff as rows. Tap a
       person to add or change their shift. The week bar above is the same one
       desktop uses, laid out for a phone. --}}
  @php
    $scmPer = (int) ($set['bookings_per_staff'] ?? 0);
    $scmTodayStr = tenant()->localToday()->toDateString();
    $scmSel = 0;
    foreach ($days as $scmI => $scmD) { if ($scmD->toDateString() === $scmTodayStr) { $scmSel = $scmI; } }
    $scmBookings = []; $scmOn = []; $scmMaxB = 1;
    for ($i = 0; $i < 7; $i++) {
        $scmBookings[$i] = ! empty($demand['bands'][$i] ?? null) ? array_sum($demand['bands'][$i]) : null;
        $scmMaxB = max($scmMaxB, (int) ($scmBookings[$i] ?? 0));
        $scmOn[$i] = 0;
        foreach ($staff as $m) { if (! empty($grid[$m->id][$i]['shifts'])) { $scmOn[$i]++; } }
    }
    $scmNeed = function ($i) use ($scmPer, $scmBookings) {
        return ($scmPer > 0 && ($scmBookings[$i] ?? 0) > 0) ? (int) ceil($scmBookings[$i] / $scmPer) : 0;
    };
    $scmBandWord = ['morning' => 'mornings', 'afternoon' => 'afternoons', 'evening' => 'evenings'];
    $scmLocations = \App\Models\Tenant\TenantLocation::where('tenant_id', tenant()->id)->orderBy('name')->get(['id', 'name']);
  @endphp
  <div class="scm">
    <div class="scm-strip" role="tablist">
      @foreach($days as $i => $d)
        <button type="button" class="scm-d {{ $i === $scmSel ? 'on' : '' }} {{ $scmOn[$i] < $scmNeed($i) ? 'short' : '' }}" data-scm-day="{{ $i }}" role="tab">
          <div class="w">{{ $d->format('D') }}</div>
          <div class="n">{{ $d->format('j') }}</div>
          <div class="s">{{ $scmOn[$i] }} on</div>
          @if($scmBookings[$i] !== null)
            <div class="dem"><i style="width: {{ (int) round(($scmBookings[$i] / $scmMaxB) * 100) }}%"></i></div>
          @endif
        </button>
      @endforeach
    </div>

    @foreach($days as $i => $d)
      <div class="scm-day" data-scm-day-panel="{{ $i }}" {{ $i === $scmSel ? '' : 'hidden' }}>
        <div class="scm-dayh">
          <b>{{ $d->format('l, M j') }}</b>
          <span>{{ $scmOn[$i] }} on shift @if($scmBookings[$i] !== null) · {{ $scmBookings[$i] }} {{ \Illuminate\Support\Str::plural('booking', $scmBookings[$i]) }} @endif</span>
        </div>
        @if($scmOn[$i] < $scmNeed($i))
          <div class="scm-warn">{{ $scmBookings[$i] }} bookings and {{ $scmOn[$i] }} on shift — your guide is {{ $scmNeed($i) }}.</div>
        @endif
        <div class="scm-rows">
          @foreach($staff as $m)
            @php
              $cell = $grid[$m->id][$i];
              $scmUnavail = [];
              foreach (($availability[$m->id] ?? []) as $scmKey) {
                  [$scmDow, $scmBand] = array_pad(explode(':', $scmKey, 2), 2, '');
                  if ((int) $scmDow === (int) $d->dayOfWeek) { $scmUnavail[] = $scmBand; }
              }
              // MARKER-SCHED-PHONE-2 — in the day's order, and "all day" when
              // every part of the day is marked.
              $scmUnavail = array_values(array_intersect(['morning', 'afternoon', 'evening'], array_unique($scmUnavail)));
              $scmUnavail = count($scmUnavail) === 3 ? ['all day'] : array_map(fn ($b) => $scmBandWord[$b] ?? $b, $scmUnavail);
              $scmShifts = collect($cell['shifts'])->map(fn ($sh) => [
                  'id' => $sh->id, 'start' => tlocal($sh->starts_at, 'H:i'), 'end' => tlocal($sh->ends_at, 'H:i'),
                  'label' => $sh->label, 'text' => tlocal($sh->starts_at, 'g:ia') . '–' . tlocal($sh->ends_at, 'g:ia'),
                  'location_id' => $sh->location_id, 'delete' => route('tenant.scheduling.shift.delete', $sh->id),
              ])->values();
            @endphp
            <button type="button" class="scm-r" {{ $cell['off'] ? 'disabled' : '' }}
                    data-scm-person="{{ $m->id }}" data-scm-name="{{ $m->name }}"
                    data-scm-date="{{ $d->toDateString() }}" data-scm-date-label="{{ $d->format('l, M j') }}"
                    data-scm-unavail="{{ $scmUnavail ? 'Unavailable ' . implode(', ', $scmUnavail) : '' }}"
                    data-scm-shifts='@json($scmShifts)'>
              <span class="scm-who">
                <b>{{ $m->name }}</b><span>{{ $m->role }}</span>
                @if($scmUnavail)<span class="note">Unavailable {{ implode(', ', $scmUnavail) }}</span>@endif
              </span>
              <span class="scm-chips">
                @if($cell['off'])
                  <span class="scm-sh to">Time off</span>
                @elseif(empty($cell['shifts']))
                  <span class="scm-sh off">Off</span>
                @else
                  @foreach($cell['shifts'] as $sh)
                    <span class="scm-sh {{ $sh->published_at ? '' : 'draft' }}">{{ tlocal($sh->starts_at, 'g:ia') }}–{{ tlocal($sh->ends_at, 'g:ia') }}</span>
                  @endforeach
                @endif
              </span>
            </button>
          @endforeach
        </div>
      </div>
    @endforeach
  </div>

  <div class="scm-sheet" id="scm-sheet" aria-hidden="true">
    <div class="scm-sheet-card" role="dialog" aria-modal="true">
      <div class="scm-grab"></div>
      <h3 id="scm-name"></h3>
      <div class="sub" id="scm-sub"></div>
      <div id="scm-exist"></div>
      <form method="POST" action="{{ route('tenant.scheduling.shift.store') }}" id="scm-form">@csrf
        <input type="hidden" name="tenant_user_id" id="scm-uid">
        <input type="hidden" name="date" id="scm-date">
        <input type="hidden" name="replace_shift_id" id="scm-replace">
        <div class="scm-two">
          <div><label>Start</label><input type="time" name="start_time" id="scm-start" value="09:00" required></div>
          <div><label>End</label><input type="time" name="end_time" id="scm-end" value="17:00" required></div>
        </div>
        <label>Label (optional)</label>
        <input type="text" name="label" id="scm-label" maxlength="80" placeholder="Shop, Routes, …">
        @if($scmLocations->count() > 1)
          <label>Location</label>
          <select name="location_id" id="scm-loc">
            @foreach($scmLocations as $scmLoc)
              <option value="{{ $scmLoc->id }}" @selected(session('current_location_id') === $scmLoc->id)>{{ $scmLoc->name }}</option>
            @endforeach
          </select>
        @endif
        <div class="scm-btns">
          <button type="button" class="sc-btn" id="scm-cancel">Cancel</button>
          <button type="submit" class="sc-btn p" id="scm-save">Save shift</button>
        </div>
      </form>
    </div>
  </div>
  <script>
  // MARKER-SCHED-PHONE — day strip and the shift sheet.
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-scm-day]').forEach(function (b) {
      b.addEventListener('click', function () {
        var i = b.getAttribute('data-scm-day');
        document.querySelectorAll('[data-scm-day]').forEach(function (x) { x.classList.toggle('on', x === b); });
        document.querySelectorAll('[data-scm-day-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-scm-day-panel') !== i; });
      });
    });
    var sheet = document.getElementById('scm-sheet');
    if (!sheet) { return; }
    var $ = function (id) { return document.getElementById(id); };
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;
    var close = function () { sheet.classList.remove('on'); sheet.setAttribute('aria-hidden', 'true'); };
    $('scm-cancel').addEventListener('click', close);
    sheet.addEventListener('click', function (e) { if (e.target === sheet) { close(); } });
    document.querySelectorAll('[data-scm-person]').forEach(function (row) {
      row.addEventListener('click', function () {
        if (row.disabled) { return; }
        var shifts = []; try { shifts = JSON.parse(row.getAttribute('data-scm-shifts') || '[]'); } catch (e) {}
        $('scm-name').textContent = row.getAttribute('data-scm-name');
        var un = row.getAttribute('data-scm-unavail');
        $('scm-sub').textContent = row.getAttribute('data-scm-date-label') + (un ? ' · ' + un : '');
        $('scm-uid').value = row.getAttribute('data-scm-person');
        $('scm-date').value = row.getAttribute('data-scm-date');
        var one = shifts.length === 1 ? shifts[0] : null;
        $('scm-replace').value = one ? one.id : '';
        $('scm-start').value = one ? one.start : '09:00';
        $('scm-end').value = one ? one.end : '17:00';
        $('scm-label').value = one && one.label ? one.label : '';
        if ($('scm-loc') && one && one.location_id) { $('scm-loc').value = one.location_id; }
        $('scm-save').textContent = one ? 'Save shift' : (shifts.length ? 'Add another shift' : 'Add shift');
        var ex = $('scm-exist'); ex.innerHTML = '';
        shifts.forEach(function (s) {
          var r = document.createElement('div'); r.className = 'scm-exist';
          var t = document.createElement('span'); t.textContent = s.text + (s.label ? ' · ' + s.label : '');
          var b = document.createElement('button'); b.type = 'button'; b.className = 'sc-btn'; b.textContent = 'Set as off';
          b.addEventListener('click', function () {
            iaConfirm('Remove this shift?').then(function (ok) {
              if (!ok) { return; }
              var f = document.createElement('form'); f.method = 'POST'; f.action = s['delete'];
              var c = document.createElement('input'); c.type = 'hidden'; c.name = '_token'; c.value = csrf; f.appendChild(c);
              document.body.appendChild(f); f.submit();
            });
          });
          r.appendChild(t); r.appendChild(b); ex.appendChild(r);
        });
        sheet.classList.add('on'); sheet.setAttribute('aria-hidden', 'false');
      });
    });
  });
  </script>

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

