#!/bin/bash
# apply-offline-pill-flash.sh
#
# MARKER-IOFLASH — the online/offline status pill pops in after the page has
# already painted. Josh noticed it once the font flash stopped masking it.
#
# WHY IT FLASHES: both mounts ship EMPTY from the server —
# <div id="ioMountSidebar"></div> and the mobile <span> — and
# public/js/offline-sync.js loads at the very bottom of the layout, then
# injects a <style> element into the head and writes the markup with
# innerHTML. So every load runs: paint with nothing there -> script parses ->
# styles inject -> pill appears. In the sidebar the pill is in normal flow,
# so its arrival also pushes the rows beneath it down.
#
# THE FIX, three parts:
#   1. The io-* CSS moves into base.css, where it is parsed with everything
#      else instead of being created by JS after paint. The JS injector is
#      removed — offline-sync.js is only ever loaded by the tenant admin
#      layout, which always loads base.css, so there is no page that gets
#      the script without the stylesheet.
#   2. Both mounts render their default markup server-side, byte-identical
#      to what renderSidebarBlock()/renderMobilePill() produce in the
#      online state. The script's first render then replaces identical
#      markup with identical markup — nothing moves.
#   3. That server-side render is wrapped in the SAME $ioEnabled check the
#      script uses (renderMounts() returns early when the addon is off), so
#      a tenant without the offline_sync addon still gets nothing at all.
#      Rendering it unconditionally would put a pill on shops that do not
#      have the feature.
#
# The mobile mount is absolutely positioned, so only the sidebar actually
# shifted its neighbours; the mobile pill just appeared. Both are fixed.
set -e

MARKER="MARKER-IOFLASH"
JS="public/js/offline-sync.js"
CSS="public/css/tenant/base.css"
SIDEBAR="resources/views/layouts/tenant/_sidebar.blade.php"
MHDR="resources/views/layouts/tenant/_mobile-header.blade.php"

for f in "$JS" "$CSS" "$SIDEBAR" "$MHDR"; do
  [ -f "$f" ] || { echo "ERROR: missing $f — run from the repo root"; exit 1; }
done
if grep -q "$MARKER" "$CSS" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

# ---------------------------------------------------------------
# 1. CSS into base.css (verbatim rules, just no longer built by JS)
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'public/css/tenant/base.css'
src = io.open(p, encoding='utf-8').read()

block = """
/* --------------------------------------------------------------------------
   MARKER-IOFLASH — offline sync status pill.
   These rules used to be assembled in JS and appended to <head> after the
   page had painted, which is half of why the pill visibly popped in. They
   are unchanged apart from living here now.
   -------------------------------------------------------------------------- */
@keyframes ioFlash { 0%,100% { opacity:1 } 50% { opacity:.25 } }
.io-status { cursor:pointer; -webkit-tap-highlight-color:transparent; user-select:none }
.io-srow {
  position:relative; display:flex; align-items:center; gap:9px;
  margin:2px 10px 8px; padding:8px 10px; border-radius:9px;
  font:600 12px Inter,-apple-system,sans-serif; color:var(--ia-dim,#6e6e6e);
}
.io-srow:hover { background:rgba(127,127,127,.09); color:var(--ia-muted,#9c9c9c) }
.io-srow.io-off { color:#F5C56B; background:rgba(245,197,107,.06) }
.io-srow.io-sync { color:#BEF264 }
.io-srow .io-chev { margin-left:auto; font-size:10px; color:var(--ia-dim,#6e6e6e) }
.io-dot { width:8px; height:8px; border-radius:50%; background:#7FD98F; flex:none }
.io-off .io-dot { background:#F5C56B; animation:ioFlash 1.1s infinite }
.io-sync .io-dot { background:#BEF264; animation:ioFlash 1.1s infinite }
.io-paused .io-dot { background:#6E6E6E }
.io-bubble {
  position:absolute; top:-6px; right:-6px; min-width:17px; height:17px;
  border-radius:100px; background:#E5484D; color:#fff;
  font:800 10px Inter,-apple-system,sans-serif;
  display:flex; align-items:center; justify-content:center;
  padding:0 4px; border:2px solid var(--ia-bg,#0b0b0b);
}
.io-mstat { position:relative; display:inline-flex; align-items:center; gap:7px; padding:9px 6px }
.io-mstat.io-loud {
  border:1px solid rgba(245,197,107,.45); background:rgba(245,197,107,.07);
  border-radius:100px; padding:5px 12px;
  font:700 11.5px Inter,-apple-system,sans-serif; color:#F5C56B;
}
.io-mstat.io-loud.io-sync { border-color:rgba(190,242,100,.4); background:rgba(190,242,100,.06); color:#BEF264 }
"""

src = src.rstrip() + "\n" + block
io.open(p, 'w', encoding='utf-8').write(src)
print('ok: io-* styles moved into base.css')
PY

# ---------------------------------------------------------------
# 2. Remove the JS style injector
# ---------------------------------------------------------------
python3 - <<'PY'
import io, re
p = 'public/js/offline-sync.js'
src = io.open(p, encoding='utf-8').read()

start = src.index('  function injectStyles() {')
end = src.index('    document.head.appendChild(st);\n  }\n', start) + len('    document.head.appendChild(st);\n  }\n')

src = src[:start] + """  // MARKER-IOFLASH — the pill's CSS now lives in base.css, parsed with the
  // rest of the stylesheet instead of being appended to <head> after paint.
  // Kept as a no-op so the existing call sites need no changes.
  function injectStyles() {}
""" + src[end:]

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: JS style injection removed')
PY

# ---------------------------------------------------------------
# 3. Server-render the default (online) markup at both mounts
# ---------------------------------------------------------------
python3 - <<'PY'
import io

# --- sidebar: mirrors renderSidebarBlock() in the online state -----------
p = 'resources/views/layouts/tenant/_sidebar.blade.php'
src = io.open(p, encoding='utf-8').read()
a = """    {{-- MARKER-OFFLINE-SYNC stage 6 — status row just below the user block --}}
    <div id="ioMountSidebar"></div>"""
assert src.count(a) == 1, 'sidebar mount'
src = src.replace(a, """    {{-- MARKER-OFFLINE-SYNC stage 6 — status row just below the user block --}}
    {{-- MARKER-IOFLASH — rendered server-side in the online state, identical
         to renderSidebarBlock()'s output, so the pill is present at first
         paint instead of appearing once the script runs. offline-sync.js
         replaces this with the same markup on init; if the connection is
         actually down, navigator.onLine corrects it in milliseconds.
         Gated on the same addon check renderMounts() uses. --}}
    <div id="ioMountSidebar">
      @if($ioStatusEnabled ?? false)
        <div class="io-status io-srow" role="button" tabindex="0" aria-label="Offline sync status and settings">
          <span class="io-dot"></span>
          <span>Online</span>
          <span class="io-chev">&#9662;</span>
        </div>
      @endif
    </div>""", 1)
io.open(p, 'w', encoding='utf-8').write(src)
print('ok: sidebar mount pre-rendered')

# --- mobile: renderMobilePill() online == dot only, no label ------------
p2 = 'resources/views/layouts/tenant/_mobile-header.blade.php'
s2 = io.open(p2, encoding='utf-8').read()
b = """    <span id="ioMountMobile" style="position:absolute;right:102px;top:50%;transform:translateY(-50%);display:inline-flex;align-items:center"></span>"""
assert s2.count(b) == 1, 'mobile mount'
s2 = s2.replace(b, """    {{-- MARKER-IOFLASH — see the sidebar mount. Online renders as a bare dot
         (renderMobilePill only shows a label when something is wrong). --}}
    <span id="ioMountMobile" style="position:absolute;right:102px;top:50%;transform:translateY(-50%);display:inline-flex;align-items:center">
      @if($ioStatusEnabled ?? false)
        <span class="io-status io-mstat" role="button" tabindex="0" aria-label="Offline sync status and settings">
          <span class="io-dot"></span>
        </span>
      @endif
    </span>""", 1)
io.open(p2, 'w', encoding='utf-8').write(s2)
print('ok: mobile mount pre-rendered')

# --- share the flag with both partials -----------------------------------
p3 = 'resources/views/layouts/tenant/app.blade.php'
s3 = io.open(p3, encoding='utf-8').read()
c = """  $ioEnabled = app()->bound('tenant')
      ? app(\\App\\Services\\FeatureAccessService::class)->hasAddon(app('tenant'), 'offline_sync')
      : false;
@endphp"""
assert s3.count(c) == 1, 'ioEnabled block'
s3 = s3.replace(c, c, 1)
io.open(p3, 'w', encoding='utf-8').write(s3)

# The partials render BEFORE that @php block runs, so the flag has to be
# resolved where they can see it — share it from a view composer-free spot:
# compute it inline in each partial instead of relying on layout ordering.
for path, needle in [
    ('resources/views/layouts/tenant/_sidebar.blade.php', '@if($ioStatusEnabled ?? false)'),
    ('resources/views/layouts/tenant/_mobile-header.blade.php', '@if($ioStatusEnabled ?? false)'),
]:
    s = io.open(path, encoding='utf-8').read()
    assert s.count(needle) == 1, 'flag use in ' + path
    s = s.replace(needle, """@php
          // MARKER-IOFLASH — resolved here rather than inherited: these
          // partials render before the layout's $ioEnabled block runs.
          $ioStatusEnabled = app()->bound('tenant')
              && app(\\App\\Services\\FeatureAccessService::class)->hasAddon(app('tenant'), 'offline_sync');
        @endphp
        @if($ioStatusEnabled)""", 1)
    io.open(path, 'w', encoding='utf-8').write(s)
    print('ok: addon check inlined in ' + path.split('/')[-1])
PY

echo ""
echo "== offline pill flash fixed =="
echo "Post-deploy: php artisan optimize:clear"
