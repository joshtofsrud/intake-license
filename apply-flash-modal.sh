#!/bin/bash
# flash-modal — animated confirmation instead of a banner, and an honest status.
#
#   1. THE BANNER. Success messages were an inline green bar pushed in above
#      the page, which shoves the content down and competes with the
#      clock-in nudge — on the connection page you get two stacked bars
#      before the heading. Replaced with a centred modal: a circle that draws
#      itself, then a check for success or a cross for failure.
#
#      Success auto-dismisses after 1.6s; errors WAIT for acknowledgement.
#      That difference is deliberate and predates this — the old code routed
#      errors through a blocking alert precisely so they can't be missed on a
#      long page. Keeping it.
#
#      Both go through one partial now, so success and failure look like two
#      states of the same thing rather than two unrelated mechanisms.
#
#   2. "CONNECTED" WAS A LIE. The distributor card printed "connected"
#      whenever a credential was STORED — not when it worked. So BTI showed
#      "connected" in the header and "auth_failed" on the same card. Now the
#      header reflects last_sync_status: connected, credentials rejected, or
#      saved, not tested.
# NO MIGRATION. Server: view:clear
set -e
if grep -q "MARKER-FLASH-MODAL" resources/views/layouts/tenant/app.blade.php; then
  echo "flash-modal already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ partial
cat > 'resources/views/layouts/tenant/_flash-modal.blade.php' <<'FM_0_EOF'
{{-- MARKER-FLASH-MODAL — one confirmation surface for success and failure.
     Success dismisses itself; an error waits to be acknowledged, which is
     why errors were a blocking alert before this and still behave that way. --}}
@php
  $flashOk  = session('success');
  $flashErr = session('error');
@endphp

@if($flashOk || $flashErr)
<style>
  .ia-fm-bg{position:fixed;inset:0;z-index:400;display:grid;place-items:center;
    background:rgba(0,0,0,.5);opacity:0;transition:opacity .16s ease}
  .ia-fm-bg.on{opacity:1}
  .ia-fm{background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:16px;padding:30px 34px 26px;min-width:290px;max-width:min(430px,calc(100vw - 32px));
    text-align:center;transform:scale(.94);transition:transform .16s cubic-bezier(.2,.9,.3,1.2)}
  .ia-fm-bg.on .ia-fm{transform:scale(1)}
  .ia-fm svg{width:62px;height:62px;display:block;margin:0 auto 16px}
  .ia-fm circle{fill:none;stroke-width:3;stroke-dasharray:166;stroke-dashoffset:166;
    animation:ia-fm-circle .5s cubic-bezier(.65,0,.45,1) forwards}
  .ia-fm path{fill:none;stroke-width:3.4;stroke-linecap:round;stroke-linejoin:round;
    stroke-dasharray:52;stroke-dashoffset:52;
    animation:ia-fm-mark .3s cubic-bezier(.65,0,.45,1) .42s forwards}
  .ia-fm.ok circle,.ia-fm.ok path{stroke:var(--ia-accent)}
  .ia-fm.bad circle,.ia-fm.bad path{stroke:#E24B4A}
  @keyframes ia-fm-circle{to{stroke-dashoffset:0}}
  @keyframes ia-fm-mark{to{stroke-dashoffset:0}}
  .ia-fm-msg{font-size:14.5px;line-height:1.5;color:var(--ia-text)}
  .ia-fm-t{font-size:11px;letter-spacing:.1em;text-transform:uppercase;font-weight:700;
    color:var(--ia-text-dim);margin-bottom:7px}
  .ia-fm-btn{margin-top:18px;background:var(--ia-accent);color:var(--ia-accent-text);
    border:none;border-radius:9px;padding:9px 20px;font-size:13px;font-weight:600;cursor:pointer}
  @media (prefers-reduced-motion:reduce){
    .ia-fm circle,.ia-fm path{animation-duration:.01s;animation-delay:0s}
    .ia-fm,.ia-fm-bg{transition:none}
  }
</style>

<div class="ia-fm-bg" id="ia-fm-bg" role="dialog" aria-modal="true">
  <div class="ia-fm {{ $flashErr ? 'bad' : 'ok' }}">
    @if($flashErr)
      <svg viewBox="0 0 60 60" aria-hidden="true">
        <circle cx="30" cy="30" r="26"></circle>
        <path d="M21 21 L39 39 M39 21 L21 39"></path>
      </svg>
      <div class="ia-fm-t">Couldn't do that</div>
    @else
      <svg viewBox="0 0 60 60" aria-hidden="true">
        <circle cx="30" cy="30" r="26"></circle>
        <path d="M19 31 L27 39 L42 22"></path>
      </svg>
    @endif
    <div class="ia-fm-msg">{{ $flashErr ?: $flashOk }}</div>
    @if($flashErr)
      <button type="button" class="ia-fm-btn" id="ia-fm-ok">OK</button>
    @endif
  </div>
</div>

<script>
(function () {
  var bg = document.getElementById('ia-fm-bg');
  if (!bg) return;
  requestAnimationFrame(function () { bg.classList.add('on'); });

  function close() {
    bg.classList.remove('on');
    setTimeout(function () { bg.remove(); }, 180);
  }

  bg.addEventListener('click', function (e) { if (e.target === bg) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

  var ok = document.getElementById('ia-fm-ok');
  if (ok) { ok.addEventListener('click', close); ok.focus(); }

  // An error waits to be acknowledged; a success gets out of the way on its
  // own, once the animation has actually played.
  @if(!$flashErr)
    setTimeout(close, 1600);
  @endif
}());
</script>
@endif
FM_0_EOF

# ------------------------------------------------------------------ layout
python3 - <<'FM_1_EOF'
import io
p = 'resources/views/layouts/tenant/app.blade.php'
s = io.open(p, encoding='utf-8').read()

# success banner out
old = """      @if(session('success'))
        <div class="ia-flash ia-flash--success">{{ session('success') }}</div>
      @endif"""
assert s.count(old) == 1, ('success banner', s.count(old))
new = """      {{-- MARKER-FLASH-MODAL — the inline success bar pushed the page down and
           stacked with the clock-in nudge. Both success and error now render
           through one animated modal. --}}
      @include('layouts.tenant._flash-modal')"""
s = s.replace(old, new)

# error alert push out — the modal covers it
start = s.index("""      @if(session('error'))
        @push('scripts')""")
end = s.index("@endif", s.index("@endpush", start)) + len("@endif")
block = s[start:end]
assert 'IntakeConfirm' in block, 'error block not matched'
s = s[:start] + """      {{-- MARKER-FLASH-MODAL — errors used a blocking IntakeConfirm.alert so they
           couldn't be missed on a long page. The modal keeps that: it waits for
           acknowledgement, while a success dismisses itself. --}}""" + s[end:]

io.open(p, 'w', encoding='utf-8').write(s)
print('layout ok')
FM_1_EOF

# ------------------------------------------------------- honest status label
python3 - <<'FM_2_EOF'
import io
p = 'resources/views/tenant/distributors/connection.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """          @if ($b['hasKey'])
            <span style="color:var(--ia-accent)">connected</span><br>
          @endif"""
assert s.count(old) == 1, ('status label', s.count(old))
new = """          {{-- MARKER-FLASH-MODAL — this said "connected" whenever a credential
               was STORED, so a distributor could show connected and
               auth_failed on the same card. It now reflects the last test. --}}
          @php $st = $b['sub']->last_sync_status; @endphp
          @if ($st === 'connected')
            <span style="color:var(--ia-accent)">connected</span><br>
          @elseif ($st === 'auth_failed')
            <span style="color:#E24B4A">credentials rejected</span><br>
          @elseif ($b['hasKey'])
            <span style="color:var(--ia-text-dim)">saved, not tested</span><br>
          @endif"""
s = s.replace(old, new)

# the footer line duplicates it now
old = """          @if ($b['sub']->last_sync_status)
            <span style="font-size:11.5px;color:var(--ia-text-dim)">
              last check: {{ $b['sub']->last_sync_status }}
            </span>
          @endif"""
if s.count(old) == 1:
    s = s.replace(old, "")
    print('removed duplicate last-check line')

io.open(p, 'w', encoding='utf-8').write(s)
print('status label ok')
FM_2_EOF

echo
echo "flash-modal applied."
