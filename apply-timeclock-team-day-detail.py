#!/usr/bin/env python3
"""Team timeclock grid: per-day session detail.
Cells with >1 punch get a visible session-count badge; every non-empty
cell opens a modal listing each session (in → out, break, net minutes,
open/auto flags) with per-session edit for managers via editPunch.
Also fixes the flag-overwrite bug (open beats auto; neither is lost).
Run from repo root: python3 apply-timeclock-team-day-detail.py
"""
import os, sys

ROOT = os.getcwd()
def read(p):
    with open(os.path.join(ROOT, p)) as f: return f.read()
def write(p, s):
    with open(os.path.join(ROOT, p), 'w') as f: f.write(s)
def sub(p, old, new, label):
    s = read(p)
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    write(p, s.replace(old, new, 1))
    print(f"OK: {label}")

CTRL = 'app/Http/Controllers/Tenant/TimeClockController.php'
VIEW = 'resources/views/tenant/timeclock/team.blade.php'

# 1) Controller — collect per-day punch details alongside the totals,
#    and stop flags overwriting each other (open > auto).
sub(CTRL,
    """            $byUser[$m->id] = ['name' => $m->name, 'role' => $m->role, 'days' => array_fill(0, 7, 0), 'flags' => array_fill(0, 7, null), 'total' => 0];""",
    """            $byUser[$m->id] = ['name' => $m->name, 'role' => $m->role, 'days' => array_fill(0, 7, 0), 'flags' => array_fill(0, 7, null), 'total' => 0, 'sessions' => array_fill(0, 7, [])]; // MARKER-TIMECLOCK-DAY-DETAIL""",
    "controller: init sessions")

sub(CTRL,
    """            $byUser[$p->tenant_user_id]['days'][$idx] += $mins;
            $byUser[$p->tenant_user_id]['total'] += $mins;
            if (!$p->clock_out_at)   $byUser[$p->tenant_user_id]['flags'][$idx] = 'open';
            elseif ($p->auto_closed) $byUser[$p->tenant_user_id]['flags'][$idx] = 'auto';""",
    """            $byUser[$p->tenant_user_id]['days'][$idx] += $mins;
            $byUser[$p->tenant_user_id]['total'] += $mins;
            // MARKER-TIMECLOCK-DAY-DETAIL — flags no longer overwrite each
            // other across multiple punches: 'open' wins over 'auto'.
            $curFlag = $byUser[$p->tenant_user_id]['flags'][$idx];
            if (!$p->clock_out_at)                            $byUser[$p->tenant_user_id]['flags'][$idx] = 'open';
            elseif ($p->auto_closed && $curFlag !== 'open')   $byUser[$p->tenant_user_id]['flags'][$idx] = 'auto';
            $byUser[$p->tenant_user_id]['sessions'][$idx][] = [
                'id'      => $p->id,
                'in'      => tlocal_carbon($p->clock_in_at)->format('g:ia'),
                'out'     => $p->clock_out_at ? tlocal_carbon($p->clock_out_at)->format('g:ia') : null,
                'in_raw'  => tlocal_carbon($p->clock_in_at)->format('Y-m-d\\\\TH:i'),
                'out_raw' => $p->clock_out_at ? tlocal_carbon($p->clock_out_at)->format('Y-m-d\\\\TH:i') : '',
                'break'   => (int) ($p->break_minutes ?? 0),
                'mins'    => $mins,
                'auto'    => (bool) $p->auto_closed,
            ];""",
    "controller: collect sessions + flag fix")

# 2) View — badge styles + day-detail modal styles
sub(VIEW,
    """.tt-c .z { color:var(--ia-text-muted); opacity:.4; }""",
    """.tt-c .z { color:var(--ia-text-muted); opacity:.4; }
/* MARKER-TIMECLOCK-DAY-DETAIL */
.tt-c.day.has { cursor:pointer; }
.tt-c.day.has:hover { background:color-mix(in srgb,var(--ia-accent) 7%,transparent); }
.tt-cnt { display:inline-block; font-size:9.5px; font-weight:700; padding:1px 6px; border-radius:9px; margin-left:4px; background:color-mix(in srgb,var(--ia-accent) 18%,transparent); color:var(--ia-accent); }
.tt-sess { display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:.5px dashed var(--ia-border); font-size:12.5px; }
.tt-sess:last-child { border-bottom:none; }
.tt-sess .t { flex:1; font-variant-numeric:tabular-nums; }
.tt-sess .m { color:var(--ia-text-muted); font-size:11.5px; }
.tt-sess-edit { display:none; padding:10px 0 4px; border-bottom:.5px dashed var(--ia-border); }
.tt-sess-edit.on { display:block; }""",
    "view: styles")

# 3) View — cell markup: badge, click handler, data payload
sub(VIEW,
    """          @php $m = $u['days'][$i]; $flag = $u['flags'][$i]; @endphp
          <div class="tt-c">
            @if($m > 0)
              {{ intdiv($m,60) }}h {{ $m % 60 }}m
              @if($flag === 'open')<span class="tt-flag open">on</span>@elseif($flag === 'auto')<span class="tt-flag auto">auto</span>@endif
            @else
              <span class="z">—</span>
            @endif
          </div>""",
    """          @php $m = $u['days'][$i]; $flag = $u['flags'][$i]; $sess = $u['sessions'][$i]; @endphp
          {{-- MARKER-TIMECLOCK-DAY-DETAIL — clickable cell + session count --}}
          <div class="tt-c day {{ count($sess) ? 'has' : '' }}"
               @if(count($sess))
                 onclick='ttDayOpen(@json(['name' => $u['name'], 'date' => $days[$i]->format('D, M j'), 'total' => $m, 'sessions' => $sess]))'
               @endif>
            @if($m > 0)
              {{ intdiv($m,60) }}h {{ $m % 60 }}m
              @if(count($sess) > 1)<span class="tt-cnt">×{{ count($sess) }}</span>@endif
              @if($flag === 'open')<span class="tt-flag open">on</span>@elseif($flag === 'auto')<span class="tt-flag auto">auto</span>@endif
            @else
              <span class="z">—</span>
            @endif
          </div>""",
    "view: cell markup")

# 4) View — day-detail modal + JS before the add-punch modal block
sub(VIEW,
    """@if($canEdit)
{{-- add-punch modal --}}""",
    """{{-- MARKER-TIMECLOCK-DAY-DETAIL — per-day session breakdown --}}
<div class="tt-mov" id="tt-day">
  <div class="tt-modal">
    <div class="tt-mh"><span id="tt-day-title">Day detail</span><span style="cursor:pointer" onclick="document.getElementById('tt-day').classList.remove('on')">×</span></div>
    <div class="tt-mb" id="tt-day-body"></div>
  </div>
</div>
<script>
function ttDayOpen(d) {
  var canEdit = {{ $canEdit ? 'true' : 'false' }};
  var editUrl = "{{ route('tenant.timeclock.punch.edit', ['punchId' => '__ID__']) }}";
  var csrf = "{{ csrf_token() }}";
  document.getElementById('tt-day-title').textContent = d.name + ' — ' + d.date;
  var h = '';
  d.sessions.forEach(function (s, i) {
    h += '<div class="tt-sess">'
      +  '<span class="t">' + s.in + ' → ' + (s.out ? s.out : '<span class="tt-flag open">on clock</span>') + '</span>'
      +  '<span class="m">' + Math.floor(s.mins/60) + 'h ' + (s.mins%60) + 'm'
      +  (s.break ? ' · ' + s.break + 'm break' : '')
      +  (s.auto ? ' <span class="tt-flag auto">auto</span>' : '') + '</span>'
      +  (canEdit ? '<button type="button" class="tt-btn" style="padding:4px 10px;font-size:11px" onclick="document.getElementById(\\'tt-se-'+i+'\\').classList.toggle(\\'on\\')">Edit</button>' : '')
      +  '</div>';
    if (canEdit) {
      h += '<form class="tt-sess-edit" id="tt-se-'+i+'" method="POST" action="' + editUrl.replace('__ID__', s.id) + '">'
        +  '<input type="hidden" name="_token" value="' + csrf + '">'
        +  '<label>Clock in</label><input type="datetime-local" name="clock_in_at" value="' + s.in_raw + '" required>'
        +  '<label>Clock out</label><input type="datetime-local" name="clock_out_at" value="' + s.out_raw + '">'
        +  '<label>Break minutes</label><input type="number" name="break_minutes" min="0" max="1440" value="' + s.break + '">'
        +  '<label>Reason (required · audit)</label><input type="text" name="reason" required placeholder="e.g. missed clock-out">'
        +  '<div style="display:flex;justify-content:flex-end"><button type="submit" class="tt-btn p">Save</button></div>'
        +  '</form>';
    }
  });
  var t = Math.floor(d.total/60) + 'h ' + (d.total%60) + 'm';
  h += '<div style="padding-top:12px;font-weight:700;font-size:12.5px;text-align:right">Day total: ' + t + '</div>';
  document.getElementById('tt-day-body').innerHTML = h;
  document.getElementById('tt-day').classList.add('on');
}
</script>

@if($canEdit)
{{-- add-punch modal --}}""",
    "view: day modal + JS")

print("\\nDone.")
