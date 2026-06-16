{{-- MARKER-PATCH-314 — service-tag print modal. Loads the slip in an iframe
     (embed mode) so printing is isolated to the slip's own @page rules.
     Mirrors the invoice modal pattern. --}}
<style>
  .tag-dialog{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);margin:0;border:0.5px solid var(--ia-border-strong,rgba(255,255,255,.22));border-radius:16px;background:var(--ia-surface,#1c1c1c);color:var(--ia-text,#f0f0f0);padding:0;width:min(380px,96vw);max-width:96vw;max-height:92vh;box-shadow:0 30px 80px rgba(0,0,0,.6)}
  .tag-dialog::backdrop{background:rgba(0,0,0,.6)}
  .tag-h{display:flex;align-items:flex-start;justify-content:space-between;padding:16px 22px;border-bottom:0.5px solid var(--ia-border,rgba(255,255,255,.13))}
  .tag-h .t{font-size:15px;font-weight:600}.tag-h .s{font-size:12px;color:var(--ia-text-dim,rgba(255,255,255,.55));margin-top:2px}
  .tag-x{background:none;border:none;color:var(--ia-text-dim,rgba(255,255,255,.55));font-size:18px;cursor:pointer;line-height:1;padding:2px 6px}
  .tag-x:hover{color:var(--ia-text,#f0f0f0)}
  .tag-preview{background:#3a3b3e;padding:20px;overflow:auto;max-height:62vh;display:flex;align-items:flex-start;justify-content:center}
  .tag-frame{border:none;background:#fff;display:block;width:302px;height:60vh;min-height:380px;box-shadow:0 10px 40px rgba(0,0,0,.4)}
  .tag-f{display:flex;gap:8px;align-items:center;padding:14px 22px;border-top:0.5px solid var(--ia-border,rgba(255,255,255,.13));background:var(--ia-surface-2,#262626)}
  .tag-f .spacer{flex:1}
  .tag-btn{padding:9px 16px;border-radius:8px;border:0.5px solid var(--ia-border,rgba(255,255,255,.18));background:transparent;color:var(--ia-text,#f0f0f0);font-size:13px;cursor:pointer;font-family:inherit}
  .tag-btn:hover{background:var(--ia-hover,rgba(255,255,255,.06))}
  .tag-btn--primary{background:var(--ia-accent,#BEF264);color:#0a0a0a;font-weight:600;border-color:transparent}
  .tag-btn--primary:hover{filter:brightness(1.05);background:var(--ia-accent,#BEF264)}
</style>
<dialog id="tagModal" class="tag-dialog">
  <div class="tag-h">
    <div>
      <div class="t">Service tag</div>
      <div class="s">{{ $appointment->ra_number }} · {{ $appointment->customerName() }}</div>
    </div>
    <button type="button" class="tag-x" onclick="document.getElementById('tagModal').close()" aria-label="Close">&times;</button>
  </div>
  <div class="tag-preview">
    <iframe id="tagFrame" class="tag-frame" title="Service tag preview"></iframe>
  </div>
  <div class="tag-f">
    <span class="spacer"></span>
    <button type="button" class="tag-btn" onclick="document.getElementById('tagModal').close()">Close</button>
    <button type="button" class="tag-btn tag-btn--primary" id="tagPrintBtn">Print</button>
  </div>
</dialog>
<script>
(function () {
  var dlg   = document.getElementById('tagModal');
  var frame = document.getElementById('tagFrame');
  var btn   = document.getElementById('tagPrintBtn');
  if (!dlg || !frame) return;
  var embedSrc = "{{ route('tenant.appointments.tag', $appointment->id) }}?embed=1";

  window.openTagModal = function () {
    if (frame.getAttribute('src') !== embedSrc) frame.setAttribute('src', embedSrc);
    dlg.showModal();
  };
  dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
  btn.addEventListener('click', function () {
    try { frame.contentWindow.focus(); frame.contentWindow.print(); }
    catch (e) { window.open(embedSrc.replace('?embed=1', ''), '_blank'); }
  });
})();
</script>
