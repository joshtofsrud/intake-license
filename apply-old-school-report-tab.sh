#!/usr/bin/env bash
# apply-old-school-report-tab.sh
# MARKER-OLD-SCHOOL-REPORT — "how it's going" as a third tab on the pad.
#
# Not in Reports. The numbers here are only ever read as a prompt to clear the
# pile, and the pile is on this page — a report a click away from the thing it
# describes gets opened once.
#
# It measures CLEARING, not writing. A written count becomes a target the
# moment it is ranked, and then you get more notes and worse ones. Nothing on
# this page sorts by it.
#
# Everything is computed from open notes plus eight weeks of timestamps, held
# in PHP rather than pushed into grouped SQL — a shop's pad is small, and
# YEARWEEK() would tie the page to MySQL for no gain.
#
# The page deliberately ENDS with the stuck notes, tickable in place. Every
# number above is fixed by clearing them, so they are the last thing on it.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- controller
p = 'app/Http/Controllers/Tenant/NoteController.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-OLD-SCHOOL-REPORT' not in s, 'already applied'

old = """        $tab = $request->input('tab') === 'done' ? 'done' : 'open';"""
assert s.count(old) == 1, 'C1 tab anchor'
s = s.replace(old, """        $tab = in_array($request->input('tab'), ['done', 'report'], true)
            ? $request->input('tab')
            : 'open';

        // MARKER-OLD-SCHOOL-REPORT — the report needs no note list.
        if ($tab === 'report') {
            return view('tenant.notes.report', $this->reportData() + [
                'tab'       => $tab,
                'openCount' => self::openCount(),
                'doneCount' => TenantNote::where('tenant_id', $tenant->id)
                    ->whereNotNull('completed_at')->count(),
            ]);
        }""")

old = """    public function store(Request $request): RedirectResponse"""
assert s.count(old) == 1, 'C2 store anchor'
s = s.replace(old, """    /**
     * MARKER-OLD-SCHOOL-REPORT — everything the report shows.
     *
     * Computed in PHP from open notes and eight weeks of timestamps. A pad is
     * small, and grouping by week in SQL would mean MySQL-only date functions
     * for no benefit.
     */
    private function reportData(): array
    {
        $tenantId = tenant()->id;
        $now      = now();
        $weekAgo  = $now->copy()->startOfWeek();

        $open = TenantNote::where('tenant_id', $tenantId)
            ->whereNull('completed_at')
            ->with(['author', 'customer'])
            ->orderBy('created_at')
            ->get();

        // Age buckets. The shape of these is the health of the pad: weight on
        // the left is a pad being worked, weight on the right is one being
        // ignored.
        $buckets = ['0-2' => 0, '3-7' => 0, '8-30' => 0, '30+' => 0];
        foreach ($open as $n) {
            $d = $n->ageInDays();
            if ($d <= 2)       { $buckets['0-2']++; }
            elseif ($d <= 7)   { $buckets['3-7']++; }
            elseif ($d <= 30)  { $buckets['8-30']++; }
            else               { $buckets['30+']++; }
        }

        // Eight weeks of written-against-cleared.
        $since = $now->copy()->subWeeks(8)->startOfWeek();
        $rows = TenantNote::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('created_at', '>=', $since)
                ->orWhere('completed_at', '>=', $since))
            ->get(['created_at', 'completed_at']);

        $weeks = [];
        for ($i = 7; $i >= 0; $i--) {
            $start = $now->copy()->subWeeks($i)->startOfWeek();
            $weeks[$start->toDateString()] = [
                'label'   => $start->format('M j'),
                'written' => 0,
                'cleared' => 0,
            ];
        }
        foreach ($rows as $r) {
            if ($r->created_at && $r->created_at >= $since) {
                $k = $r->created_at->copy()->startOfWeek()->toDateString();
                if (isset($weeks[$k])) { $weeks[$k]['written']++; }
            }
            if ($r->completed_at && $r->completed_at >= $since) {
                $k = $r->completed_at->copy()->startOfWeek()->toDateString();
                if (isset($weeks[$k])) { $weeks[$k]['cleared']++; }
            }
        }
        $peak = max(1, max(array_merge(
            array_column($weeks, 'written'),
            array_column($weeks, 'cleared')
        )));

        // People. Written is shown and never ranked — see the header comment.
        $people = [];
        $touch = function (?string $id, ?string $name) use (&$people) {
            $id = $id ?: 'unknown';
            $people[$id] ??= [
                'name' => $name ?: 'Someone', 'written' => 0, 'cleared' => 0,
                'still_open' => 0, 'oldest' => 0,
            ];
            return $id;
        };

        $recent = TenantNote::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('created_at', '>=', $weekAgo)
                ->orWhere('completed_at', '>=', $weekAgo))
            ->with(['author', 'completer'])
            ->get();

        foreach ($recent as $n) {
            if ($n->created_at >= $weekAgo) {
                $people[$touch($n->created_by, $n->author?->name)]['written']++;
            }
            if ($n->completed_at && $n->completed_at >= $weekAgo) {
                $people[$touch($n->completed_by, $n->completer?->name)]['cleared']++;
            }
        }
        foreach ($open as $n) {
            $k = $touch($n->created_by, $n->author?->name);
            $people[$k]['still_open']++;
            $people[$k]['oldest'] = max($people[$k]['oldest'], $n->ageInDays());
        }
        uasort($people, fn ($a, $b) => $b['still_open'] <=> $a['still_open']);

        return [
            'openCount'   => $open->count(),
            'oldest'      => $open->first(),
            'clearedWeek' => TenantNote::where('tenant_id', $tenantId)
                ->where('completed_at', '>=', $weekAgo)->count(),
            'writtenWeek' => TenantNote::where('tenant_id', $tenantId)
                ->where('created_at', '>=', $weekAgo)->count(),
            'buckets'     => $buckets,
            'weeks'       => array_values($weeks),
            'peak'        => $peak,
            'people'      => $people,
            // Stuck: over a week old, oldest first. The point of the page.
            'stuck'       => $open->filter(fn ($n) => $n->ageInDays() >= 7)->take(10),
        ];
    }

    public function store(Request $request): RedirectResponse""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- tabs
p = 'resources/views/tenant/notes/index.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """  <a href="{{ route('tenant.notes.index', ['tab' => 'done']) }}"
     class="np-tab {{ $tab === 'done' ? 'on' : '' }}">Crossed off {{ $doneCount }}</a>"""
assert s.count(old) == 1, 'V1 tabs anchor'
s = s.replace(old, """  <a href="{{ route('tenant.notes.index', ['tab' => 'done']) }}"
     class="np-tab {{ $tab === 'done' ? 'on' : '' }}">Crossed off {{ $doneCount }}</a>
  {{-- MARKER-OLD-SCHOOL-REPORT --}}
  <a href="{{ route('tenant.notes.index', ['tab' => 'report']) }}"
     class="np-tab {{ $tab === 'report' ? 'on' : '' }}">How it's going</a>""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

cat <<'EOF' > resources/views/tenant/notes/report.blade.php
@extends('layouts.tenant.app')
@php $pageTitle = 'Notes'; @endphp

@section('content')

{{-- MARKER-OLD-SCHOOL-REPORT --}}
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">The pad — how it's going</h1>
    <p class="ia-page-subtitle">
      Whether the pad is being cleared, not how much gets written. A pad that fills faster than it
      empties stops being read, and then the note that mattered gets missed with the rest.
    </p>
  </div>
</div>

<div class="np-tabs">
  <a href="{{ route('tenant.notes.index') }}" class="np-tab">Open {{ $openCount }}</a>
  <a href="{{ route('tenant.notes.index', ['tab' => 'done']) }}" class="np-tab">Crossed off {{ $doneCount }}</a>
  <a href="{{ route('tenant.notes.index', ['tab' => 'report']) }}" class="np-tab on">How it's going</a>
</div>

<div class="rp-strip">
  <div class="rp-stat">
    <div class="n">{{ $openCount }}</div><div class="l">Open right now</div>
  </div>
  <div class="rp-stat {{ $oldest && $oldest->ageInDays() >= 7 ? 'warn' : '' }}">
    <div class="n">{{ $oldest ? $oldest->ageInDays() . 'd' : '—' }}</div>
    <div class="l">Oldest still open</div>
  </div>
  <div class="rp-stat {{ $clearedWeek >= $writtenWeek ? 'good' : '' }}">
    <div class="n">{{ $clearedWeek }}</div><div class="l">Crossed off this week</div>
  </div>
  <div class="rp-stat">
    <div class="n">{{ $writtenWeek }}</div><div class="l">Written this week</div>
  </div>
</div>

<div class="rp-sec">
  <div class="rp-h">How long things have been sitting
    <span>weight on the right is the warning</span></div>
  <div class="rp-b">
    @php $bmax = max(1, max($buckets)); @endphp
    @foreach([
      '0-2'  => ['Today &amp; yesterday', '#4ade80'],
      '3-7'  => ['3–7 days',              '#a3b18a'],
      '8-30' => ['8–30 days',             '#d9a441'],
      '30+'  => ['Over 30 days',          '#f87171'],
    ] as $k => $meta)
      <div class="rp-arow">
        <span class="lbl">{!! $meta[0] !!}</span>
        <span class="track">
          <span class="fill" style="width:{{ round($buckets[$k] / $bmax * 100) }}%;background:{{ $meta[1] }}"></span>
        </span>
        <span class="ct">{{ $buckets[$k] }}</span>
      </div>
    @endforeach
  </div>
</div>

<div class="rp-sec">
  <div class="rp-h">Written against crossed off
    <span>eight weeks · the bars drifting apart is what to catch early</span></div>
  <div class="rp-b">
    <div class="rp-trend">
      @foreach($weeks as $w)
        <div class="wk">
          <div class="bars">
            <i class="w" style="height:{{ max(2, round($w['written'] / $peak * 100)) }}%"
               title="{{ $w['written'] }} written"></i>
            <i class="c" style="height:{{ max(2, round($w['cleared'] / $peak * 100)) }}%"
               title="{{ $w['cleared'] }} crossed off"></i>
          </div>
          <div class="lb">{{ $w['label'] }}</div>
        </div>
      @endforeach
    </div>
    <div class="rp-legend">
      <span><i style="background:#3f3f49"></i>written</span>
      <span><i style="background:#4ade80"></i>crossed off</span>
    </div>
  </div>
</div>

@if(count($people))
  <div class="rp-sec">
    <div class="rp-h">People
      <span>who writes, who clears — and whose notes are still sitting</span></div>
    <div class="rp-b" style="padding:0 15px">
      <table class="rp-ppl">
        <thead>
          <tr>
            <th>Staff</th>
            <th class="num">Written</th>
            <th class="num">Crossed off</th>
            <th class="num">Theirs still open</th>
            <th class="num">Oldest of those</th>
          </tr>
        </thead>
        <tbody>
          @foreach($people as $p)
            <tr>
              <td>{{ $p['name'] }}</td>
              <td class="num">{{ $p['written'] }}</td>
              <td class="num">{{ $p['cleared'] }}</td>
              <td class="num">{{ $p['still_open'] }}</td>
              <td class="num {{ $p['oldest'] >= 14 ? 'lag' : '' }}">
                {{ $p['oldest'] ? $p['oldest'] . 'd' : '—' }}
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <p class="rp-caveat">
        Written is shown and never sorted on. Someone writing a lot is not a finding; someone writing a
        few and clearing none, with something three weeks old, is worth a conversation.
      </p>
    </div>
  </div>
@endif

<div class="rp-sec">
  <div class="rp-h">Nobody has touched these
    <span>over a week old · the actual point of the page</span></div>
  <div class="rp-b">
    @forelse($stuck as $n)
      <div class="rp-note">
        <form method="POST" action="{{ route('tenant.notes.toggle', $n->id) }}">
          @csrf
          <input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
          <button type="submit" class="rp-box" aria-label="Cross off"></button>
        </form>
        <div class="rp-nb">
          <div class="rp-nt">{{ $n->body }}</div>
          <div class="rp-nm">
            @if($n->customer)
              <span class="rp-who">{{ $n->customer->first_name }} {{ $n->customer->last_name }}</span>
            @endif
            <span>{{ $n->author?->name ?? 'someone' }}</span>
            <span class="rp-old">{{ $n->ageInDays() }} days</span>
          </div>
        </div>
      </div>
    @empty
      <div class="rp-clear">Nothing has been sitting more than a week. The pad is being cleared.</div>
    @endforelse
  </div>
</div>

<style>
  .np-tabs { display:flex; gap:6px; margin-bottom:16px; }
  .np-tab { padding:6px 13px; border-radius:999px; font-size:12.5px; text-decoration:none;
            border:.5px solid var(--ia-border); color:var(--ia-text-dim); }
  .np-tab.on { background:#B8860B; border-color:#B8860B; color:#fff; font-weight:650; }

  .rp-strip { display:grid; grid-template-columns:repeat(4,1fr); gap:11px; margin-bottom:16px; }
  @media (max-width:800px){ .rp-strip { grid-template-columns:repeat(2,1fr); } }
  .rp-stat { border:.5px solid var(--ia-border); border-radius:10px; padding:13px 15px; }
  .rp-stat .n { font-size:25px; font-weight:700; letter-spacing:-.02em; font-variant-numeric:tabular-nums; }
  .rp-stat .l { font-size:11.5px; color:var(--ia-text-dim); margin-top:3px; line-height:1.45; }
  .rp-stat.warn { border-color:rgba(217,164,65,.45); background:rgba(217,164,65,.08); }
  .rp-stat.warn .n { color:#d9a441; }
  .rp-stat.good .n { color:#4ade80; }

  .rp-sec { border:.5px solid var(--ia-border); border-radius:11px; margin-bottom:16px; overflow:hidden; }
  .rp-h { padding:11px 15px; border-bottom:.5px solid var(--ia-border); font-size:12.5px; font-weight:600;
          display:flex; align-items:center; gap:9px; flex-wrap:wrap; }
  .rp-h span { margin-left:auto; font-size:11px; color:var(--ia-text-dim); font-weight:400; }
  .rp-b { padding:15px; }

  .rp-arow { display:flex; align-items:center; gap:12px; font-size:12.5px; margin-bottom:9px; }
  .rp-arow:last-child { margin-bottom:0; }
  .rp-arow .lbl { width:130px; color:var(--ia-text-dim); flex:none; }
  .rp-arow .track { flex:1; height:20px; border-radius:5px; background:rgba(127,127,127,.12); overflow:hidden; }
  .rp-arow .fill { display:block; height:100%; border-radius:5px; }
  .rp-arow .ct { width:34px; text-align:right; font-variant-numeric:tabular-nums; flex:none; }

  .rp-trend { display:flex; align-items:flex-end; gap:10px; height:150px; }
  .rp-trend .wk { flex:1; display:flex; flex-direction:column; align-items:center; gap:5px; height:100%; }
  .rp-trend .bars { flex:1; display:flex; align-items:flex-end; gap:3px; justify-content:center; width:100%; }
  .rp-trend .bars i { width:13px; border-radius:3px 3px 0 0; display:block; }
  .rp-trend .bars .w { background:#3f3f49; }
  .rp-trend .bars .c { background:#4ade80; }
  .rp-trend .lb { font-size:10px; color:var(--ia-text-dim); }
  .rp-legend { display:flex; gap:14px; font-size:11.5px; color:var(--ia-text-dim); margin-top:10px; }
  .rp-legend i { display:inline-block; width:9px; height:9px; border-radius:2px; margin-right:5px; }

  .rp-ppl { width:100%; border-collapse:collapse; font-size:12.5px; }
  .rp-ppl th { text-align:left; padding:8px 10px; font-size:10px; letter-spacing:.06em;
               text-transform:uppercase; color:var(--ia-text-dim); border-bottom:.5px solid var(--ia-border); }
  .rp-ppl td { padding:10px; border-bottom:.5px solid var(--ia-border); }
  .rp-ppl tr:last-child td { border-bottom:0; }
  .rp-ppl .num { text-align:right; font-variant-numeric:tabular-nums; }
  .rp-ppl .lag { color:#d9a441; font-weight:600; }
  .rp-caveat { font-size:11.5px; color:var(--ia-text-dim); line-height:1.6; padding:12px 0 4px; }

  .rp-note { background:#F4ECD8; color:#2A2419; border-radius:9px; padding:11px 12px; margin-bottom:8px;
             display:flex; gap:11px; align-items:flex-start; }
  .rp-box { width:19px; height:19px; border:1.6px solid #8D8267; border-radius:4px; background:#FBF7EC;
            flex:none; margin-top:1px; cursor:pointer; padding:0; }
  .rp-box:hover { background:#8D8267; }
  .rp-nb { flex:1; min-width:0; }
  .rp-nt { font-size:13.5px; line-height:1.5; word-break:break-word; }
  .rp-nm { display:flex; gap:9px; flex-wrap:wrap; margin-top:5px; font-size:10.5px; color:#7A7159; }
  .rp-who { background:#DBE6D5; color:#33452C; border-radius:4px; padding:1px 7px; font-weight:600; }
  .rp-old { color:#A8622A; font-weight:600; }
  .rp-clear { padding:22px; text-align:center; font-size:13px; color:var(--ia-text-dim); }
</style>

@endsection
EOF
echo "created resources/views/tenant/notes/report.blade.php"

echo
echo "--- wiring ---"
grep -n "MARKER-OLD-SCHOOL-REPORT" app/Http/Controllers/Tenant/NoteController.php resources/views/tenant/notes/index.blade.php | head

echo
echo "--- every variable the report view uses is provided ---"
python3 - <<'PY'
import io, re
ctl  = io.open('app/Http/Controllers/Tenant/NoteController.php', encoding='utf-8').read()
view = io.open('resources/views/tenant/notes/report.blade.php', encoding='utf-8').read()

block = re.search(r"return \[\n(.*?)\n        \];", ctl, re.S).group(1)
provided = set(re.findall(r"'(\w+)'\s*=>", block)) | {'tab', 'openCount', 'doneCount'}

local = set(re.findall(r'@php \$(\w+)', view)) | set(re.findall(r'as \$(\w+)', view)) | {'meta', 'k', 'loop'}
used = set(re.findall(r'\$(\w+)', view)) - local - {'p','n','w'}

missing = sorted(u for u in used if u not in provided)
print('  provided:', ', '.join(sorted(provided)))
print('  missing :', missing or 'none')
PY

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
pat = re.compile(r'\B@(@?\w+(?:::\w+)?)([ \t]*)(\()?', re.X)
OPEN  = {'if','unless','isset','auth','guest','forelse','foreach','for','while','php','section','error','once'}
CLOSE = {'endif','endunless','endisset','endempty','endauth','endguest','endforelse','endforeach','endfor','endwhile','endphp','endsection','enderror','endonce'}
for f in ['resources/views/tenant/notes/report.blade.php',
          'resources/views/tenant/notes/index.blade.php']:
    raw = io.open(f, encoding='utf-8').read()
    s = re.sub(r'\{\{--.*?--\}\}', lambda m: ' '*len(m.group(0)), raw, flags=re.S)
    g = len(re.findall(r'\w@(?:if|endif|foreach|endforeach|forelse|endforelse|else|elseif|php|endphp|csrf)\b', s))
    d = 0
    for m in re.finditer(r'@(\w+)', s):
        if not pat.match(s, m.start()): continue
        if m.group(1) in OPEN: d += 1
        elif m.group(1) in CLOSE: d -= 1
    print('  %-34s glued=%d depth=%d %s' % (f.split('/')[-1], g, d, 'OK' if (g==0 and d==0) else '*** CHECK ***'))
    for m in re.finditer(r'@php(.*?)@endphp', raw, re.S):
        if '{{--' in m.group(1): print('     *** blade comment in @php ***')
PY

echo
echo "--- php balance ---"
python3 - <<'PY'
import io
s = io.open('app/Http/Controllers/Tenant/NoteController.php', encoding='utf-8').read()
i, n, d, par, brk = 0, len(s), 0, 0, 0
while i < n:
    c = s[i]
    if c == '#' or (c == '/' and i+1 < n and s[i+1] == '/'):
        while i < n and s[i] != '\n': i += 1
    elif c == '/' and i+1 < n and s[i+1] == '*':
        i += 2
        while i+1 < n and not (s[i] == '*' and s[i+1] == '/'): i += 1
        i += 2
    elif c in '"\'':
        q = c; i += 1
        while i < n and s[i] != q:
            if s[i] == '\\': i += 1
            i += 1
        i += 1
    else:
        if c == '{': d += 1
        elif c == '}': d -= 1
        elif c == '(': par += 1
        elif c == ')': par -= 1
        elif c == '[': brk += 1
        elif c == ']': brk -= 1
        i += 1
print('NoteController braces', d, 'parens', par, 'brackets', brk)
PY

echo
echo "apply-old-school-report-tab: OK"
