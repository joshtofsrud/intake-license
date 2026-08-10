#!/usr/bin/env bash
set -euo pipefail
# apply-marketing-sessions-restyle.sh — MARKER-MKTSESSTYLE
# Restyles the marketing sessions panel to match tenant admin's booking
# sessions explorer, and — the point Josh raised — CONTAINS it so a busy
# window can't run away down the page.
#
# Values are copied from resources/views/tenant/reports/traffic.blade.php
# (.rse-* block, lines ~488-514) rather than invented, so the two surfaces can
# be diffed against each other:
#   .rse-scroll  max-height 430px, own border + inset background  <-- containment
#   .rse-row     flex, 12px 15px, hover tint, wraps for the detail panel
#   .rse-time    82px, 700, with a dimmer day underneath
#   .rse-status  uppercase 10px pill, 100px radius, tinted + .5px border
#   .rse-detail  hidden until .open, inset panel, 10px radius
#   .rse-chip    filter chips; .on goes solid lime with dark text
#
# Swaps my <details>/<summary> for the same row+click-toggle markup the tenant
# version uses, so the interaction matches too. Filter chips carry live counts
# and gain an "All" plus one per status. Row cap raised from 60 to 200 since
# the scroll box now bounds the height rather than the row count.
#
# REQUIRES apply-marketing-sessions-explorer (MARKER-MKTSESSIONS).

VIEW=resources/views/filament/pages/marketing-traffic.blade.php
SVC=app/Services/Platform/MarketingSessionsService.php
PAGE=app/Filament/Pages/MarketingTraffic.php

for f in "$VIEW" "$SVC" "$PAGE"; do
  [ -f "$f" ] || { echo "PRECONDITION FAILED: deploy apply-marketing-sessions-explorer.sh first ($f missing)"; exit 1; }
done

if grep -q "MARKER-MKTSESSTYLE" "$VIEW"; then
  echo "Already applied (MARKER-MKTSESSTYLE present) — no-op."
  exit 0
fi

# ---------------------------------------------------------------- row cap
python3 - "$PAGE" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """            ))->recent(),"""
new = """            ))->recent(200), // MARKER-MKTSESSTYLE — the scroll box bounds height now"""
n = src.count(old)
if n != 1:
    print(f"FAIL row cap: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   row cap raised to 200")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- panel
python3 - "$VIEW" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

start = src.index("{{-- MARKER-MKTSESSIONS")
end   = src.index("@endif\n</x-filament-panels::page>")

panel = '''{{-- MARKER-MKTSESSTYLE — mirrors tenant admin's booking sessions explorer
     (resources/views/tenant/reports/traffic.blade.php, .rse-* block). Same
     class names and values, so the two surfaces stay comparable. The scroll
     box is what keeps this section from running away as traffic grows. --}}
<style>
.rse-zone{margin-top:26px}
.rse-zone-title{font-size:15px;font-weight:700;letter-spacing:-.01em}
.rse-zone-sub{font-size:12px;color:var(--ia-text-dim,rgba(255,255,255,.42));font-weight:500;margin-top:2px}
.rse-filters{display:flex;gap:7px;margin:12px 0;flex-wrap:wrap}
.rse-chip{font-size:11.5px;font-weight:600;border:.5px solid var(--ia-border);border-radius:100px;padding:5px 12px;color:var(--ia-text-2,rgba(255,255,255,.6));cursor:pointer}
.rse-chip.on{background:var(--ia-lime,#BEF264);color:#0B0B0B;border-color:var(--ia-lime,#BEF264);font-weight:700}
.rse-scroll{max-height:430px;overflow-y:auto;border:.5px solid var(--ia-border);border-radius:12px;background:rgba(0,0,0,.18)}
.rse-row{display:flex;align-items:center;gap:13px;padding:12px 15px;border-bottom:.5px solid rgba(255,255,255,.05);cursor:pointer;flex-wrap:wrap}
.rse-row:hover{background:rgba(255,255,255,.03)}
.rse-row:last-child{border-bottom:none}
.rse-time{width:82px;flex:none;font-size:12.5px;font-weight:700}
.rse-time span{display:block;font-size:10.5px;font-weight:400;color:var(--ia-text-dim,rgba(255,255,255,.42))}
.rse-land{flex:1;min-width:170px;font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rse-pages{font-size:11px;color:var(--ia-text-dim,rgba(255,255,255,.5));flex:none}
.rse-dev{color:var(--ia-text-dim,rgba(255,255,255,.42));font-size:11px;flex:none;width:52px;text-align:right}
.rse-dur{font-size:12px;font-variant-numeric:tabular-nums;color:var(--ia-text-2,rgba(255,255,255,.72));flex:none;width:56px;text-align:right}
.rse-status{flex:none;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;border-radius:100px;padding:4px 10px}
.rse-status.converted{background:rgba(127,217,143,.11);color:#7FD98F;border:.5px solid rgba(127,217,143,.32)}
.rse-status.browsed{background:rgba(190,242,100,.09);color:var(--ia-lime,#BEF264);border:.5px solid rgba(190,242,100,.32)}
.rse-status.bounced{background:rgba(255,255,255,.05);color:var(--ia-text-dim,rgba(255,255,255,.42));border:.5px solid rgba(255,255,255,.10)}
.rse-detail{display:none;flex-basis:100%;background:rgba(0,0,0,.25);border:.5px solid var(--ia-border);border-radius:10px;margin-top:9px;padding:12px 15px}
.rse-row.open .rse-detail{display:block}
.rse-dmeta{display:flex;gap:16px;flex-wrap:wrap;font-size:11px;color:var(--ia-text-dim,rgba(255,255,255,.42));margin-bottom:9px;border-bottom:.5px solid rgba(255,255,255,.06);padding-bottom:9px}
.rse-ev{display:flex;gap:11px;font-size:12px;padding:4px 0;align-items:baseline}
.rse-ev .t{width:76px;flex:none;color:var(--ia-text-dim,rgba(255,255,255,.42));font-size:11px}
.rse-ev .w{color:var(--ia-text-2,rgba(255,255,255,.72))}
.rse-none{padding:22px;text-align:center;font-size:13px;color:var(--ia-text-dim,rgba(255,255,255,.42))}
</style>

@php
  $msCounts = collect($sessions)->countBy('status');
@endphp

<div class="rse-zone">
  <div class="rse-zone-title">Sessions</div>
  <div class="rse-zone-sub">
    Newest first &middot; duration is first event to last, so a single-page visit reads 0:00 &middot;
    &ldquo;pages&rdquo; means pages visited, not in-page clicks
  </div>

  <div class="rse-filters" id="mktSessFilters">
    <span class="rse-chip on" data-f="all">All ({{ count($sessions) }})</span>
    <span class="rse-chip" data-f="converted">Converted ({{ $msCounts['converted'] ?? 0 }})</span>
    <span class="rse-chip" data-f="browsed">Browsed ({{ $msCounts['browsed'] ?? 0 }})</span>
    <span class="rse-chip" data-f="bounced">Bounced ({{ $msCounts['bounced'] ?? 0 }})</span>
  </div>

  <div class="rse-scroll">
    @forelse($sessions as $sess)
      <div class="rse-row" data-status="{{ $sess['status'] }}" onclick="this.classList.toggle('open')">
        <span class="rse-time">{{ $sess['time'] }}<span>{{ $sess['day'] }}</span></span>
        <span class="rse-land">{{ $sess['landing'] }}</span>
        <span class="rse-pages">{{ $sess['page_count'] }} {{ \\Illuminate\\Support\\Str::plural('page', $sess['page_count']) }}</span>
        <span class="rse-dev">{{ $sess['device'] }}</span>
        <span class="rse-dur">{{ $sess['duration'] }}</span>
        <span class="rse-status {{ $sess['status'] }}">{{ $sess['status'] }}</span>

        <div class="rse-detail" onclick="event.stopPropagation()">
          <div class="rse-dmeta">
            <span>Session {{ $sess['session'] }}</span>
            @if($sess['referrer'])<span>From {{ $sess['referrer'] }}</span>@endif
            @if($sess['utm'])<span>utm_source {{ $sess['utm'] }}</span>@endif
            <span>Duration {{ $sess['duration'] }}</span>
          </div>
          @foreach($sess['timeline'] as $ev)
            <div class="rse-ev"><span class="t">{{ $ev['at'] }}</span><span class="w">{{ $ev['what'] }}</span></div>
          @endforeach
        </div>
      </div>
    @empty
      <div class="rse-none">No sessions in this window yet.</div>
    @endforelse
  </div>
</div>

<script>
  // MARKER-MKTSESSTYLE — same filter behaviour as the tenant explorer.
  (function () {
    var wrap = document.getElementById('mktSessFilters');
    if (!wrap) { return; }
    wrap.addEventListener('click', function (e) {
      var chip = e.target.closest('.rse-chip');
      if (!chip) { return; }
      wrap.querySelectorAll('.rse-chip').forEach(function (c) { c.classList.remove('on'); });
      chip.classList.add('on');
      var f = chip.getAttribute('data-f');
      document.querySelectorAll('.rse-row').forEach(function (r) {
        r.style.display = (f === 'all' || r.getAttribute('data-status') === f) ? 'flex' : 'none';
      });
    });
  })();
</script>

'''

src = src[:start] + panel + src[end:]
print("ok   sessions panel restyled to .rse-* ")

open(path, 'w').write(src)
PY

echo ""
echo "SUCCESS — apply-marketing-sessions-restyle applied."
echo "Deploy's optimize covers the view cache."
