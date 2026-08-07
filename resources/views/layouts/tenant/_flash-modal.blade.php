{{-- MARKER-FLASH-MODAL — one confirmation surface for success and failure.
     Success dismisses itself; an error waits to be acknowledged, which is
     why errors were a blocking alert before this and still behave that way. --}}
@php
  $flashOk  = session('success');
  $flashErr = session('error') ?: ((isset($errors) && $errors->any()) ? $errors->first() : null);
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
