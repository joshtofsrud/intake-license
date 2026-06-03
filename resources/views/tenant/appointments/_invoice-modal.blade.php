{{-- MARKER-PATCH-205 / 206 — two-pane invoice composer with live preview. --}}
@php
  $invPaid  = $appointment->isPaid();
  $invTerms = $appointment->invoice_terms ?? 'on_completion';
@endphp

<style>
  .inv-dialog{border:0.5px solid var(--ia-border-strong,rgba(255,255,255,.22));border-radius:16px;background:var(--ia-surface,#1c1c1c);color:var(--ia-text,#f0f0f0);padding:0;width:min(1040px,96vw);max-width:96vw;box-shadow:0 30px 80px rgba(0,0,0,.6)}
  .inv-dialog::backdrop{background:rgba(0,0,0,.6)}
  .inv-h{display:flex;align-items:flex-start;justify-content:space-between;padding:16px 22px;border-bottom:0.5px solid var(--ia-border,rgba(255,255,255,.13))}
  .inv-h .t{font-size:15px;font-weight:600}.inv-h .s{font-size:12px;color:var(--ia-text-dim,rgba(255,255,255,.55));margin-top:2px}
  .inv-x{background:none;border:none;color:var(--ia-text-dim,rgba(255,255,255,.55));font-size:18px;cursor:pointer;line-height:1;padding:2px 6px}
  .inv-x:hover{color:var(--ia-text,#f0f0f0)}
  .inv-body{display:grid;grid-template-columns:320px 1fr;min-height:480px}
  .inv-ctrls{padding:20px;border-right:0.5px solid var(--ia-border,rgba(255,255,255,.13));overflow-y:auto;max-height:74vh}
  .inv-preview{background:#3a3b3e;padding:22px;overflow:auto;max-height:74vh;display:flex;align-items:flex-start;justify-content:center}
  .inv-frame-wrap{margin:0 auto;box-shadow:0 10px 40px rgba(0,0,0,.4)}
  .inv-frame{border:none;background:#fff;display:block}
  .inv-grp{margin-bottom:16px}.inv-grp:last-child{margin-bottom:0}
  .inv-lbl{font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:600;color:var(--ia-text-dim,rgba(255,255,255,.55));display:block;margin-bottom:8px}
  .inv-seg{display:flex;gap:6px;background:var(--ia-input-bg,rgba(255,255,255,.07));border:0.5px solid var(--ia-border,rgba(255,255,255,.13));border-radius:8px;padding:4px}
  .inv-seg label{flex:1;text-align:center;padding:9px;border-radius:5px;font-size:13px;cursor:pointer;color:var(--ia-text-muted,rgba(255,255,255,.78));position:relative}
  .inv-seg input{position:absolute;opacity:0;pointer-events:none}
  .inv-seg label:has(input:checked){background:var(--ia-accent,#BEF264);color:#0a0a0a;font-weight:600}
  .inv-paid{font-size:13px;color:#86efac;background:rgba(74,222,128,.10);border:0.5px solid rgba(74,222,128,.25);border-radius:8px;padding:11px 13px}
  .inv-ta,.inv-inp{width:100%;padding:9px 11px;border-radius:8px;border:0.5px solid var(--ia-border,rgba(255,255,255,.13));background:var(--ia-input-bg,rgba(255,255,255,.07));color:var(--ia-text,#f0f0f0);font-size:13px;font-family:inherit}
  .inv-ta{min-height:72px;resize:vertical;line-height:1.5}
  .inv-ta:focus,.inv-inp:focus{outline:none;border-color:var(--ia-accent,#BEF264);box-shadow:0 0 0 3px var(--ia-accent-soft,rgba(190,242,100,.12))}
  .inv-hlp{font-size:11.5px;color:var(--ia-text-dim,rgba(255,255,255,.55));margin-top:6px}
  .inv-cap{font-size:11px;color:#d9b15e;margin-top:7px;line-height:1.4}
  .inv-f{display:flex;gap:8px;align-items:center;padding:14px 22px;border-top:0.5px solid var(--ia-border,rgba(255,255,255,.13));background:var(--ia-surface-2,#262626);flex-wrap:wrap}
  .inv-f .spacer{flex:1}
  @media(max-width:760px){ .inv-body{grid-template-columns:1fr} .inv-preview{display:none} }
</style>

<dialog id="invoiceModal" class="inv-dialog">
  <form method="POST" id="invoiceForm" action="{{ route('tenant.appointments.invoice.email', $appointment->id) }}">
    @csrf
    <div class="inv-h">
      <div><div class="t">Export invoice</div><div class="s">{{ $appointment->ra_number }} · {{ $appointment->customerName() }}</div></div>
      <button type="button" class="inv-x" onclick="document.getElementById('invoiceModal').close()">&times;</button>
    </div>

    <div class="inv-body">
      <div class="inv-ctrls">
        <div class="inv-grp">
          <span class="inv-lbl">Document style</span>
          <div class="inv-seg">
            <label><input type="radio" name="pstyle" value="print" checked onchange="invRefreshNow()"><span>Print</span></label>
            <label><input type="radio" name="pstyle" value="branded" onchange="invRefreshNow()"><span>Branded</span></label>
          </div>
          <div class="inv-cap" id="invCap" style="display:none">Preview only — invoices export in Print style for now.</div>
        </div>

        <div class="inv-grp">
          <span class="inv-lbl">Payment terms</span>
          @if($invPaid)
            <div class="inv-paid">&checkmark; Paid in full — prints as a paid receipt.</div>
          @else
            <div class="inv-seg">
              <label><input type="radio" name="terms" value="due_now" {{ $invTerms === 'due_now' ? 'checked' : '' }} onchange="invRefresh()"><span>Due now</span></label>
              <label><input type="radio" name="terms" value="on_completion" {{ $invTerms !== 'due_now' ? 'checked' : '' }} onchange="invRefresh()"><span>On completion</span></label>
            </div>
          @endif
        </div>

        <div class="inv-grp">
          <span class="inv-lbl">Note on invoice</span>
          <textarea class="inv-ta" name="note" oninput="invRefresh()" placeholder="Optional — prints on the customer's invoice.">{{ $appointment->invoice_note }}</textarea>
        </div>

        <div class="inv-grp">
          <span class="inv-lbl">Email to</span>
          <input class="inv-inp" type="email" name="email" value="{{ $appointment->customer_email }}" placeholder="customer@email.com">
          <div class="inv-hlp">Sends as a PDF attachment through your Postmark stream.</div>
        </div>
      </div>

      <div class="inv-preview" id="invPreview">
        <div class="inv-frame-wrap" id="invFrameWrap"><iframe id="invFrame" class="inv-frame" title="Invoice preview"></iframe></div>
      </div>
    </div>

    <div class="inv-f">
      <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="invGo('preview')">Open PDF</button>
      <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" onclick="invGo('download')">Download</button>
      <div class="spacer"></div>
      <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Email invoice</button>
    </div>
  </form>
</dialog>

<script>
(function(){
  var APPT = @json($appointment->id);
  var URL_PREVIEW_HTML = @json(route('tenant.appointments.invoice.preview-html', $appointment->id));
  var URL_PDF          = @json(route('tenant.appointments.invoice.preview', $appointment->id));
  var URL_DOWNLOAD     = @json(route('tenant.appointments.invoice.download', $appointment->id));
  var refreshTimer;

  function params(){
    var f = document.getElementById('invoiceForm');
    var styleEl = f.querySelector('input[name=pstyle]:checked');
    var termEl  = f.querySelector('input[name=terms]:checked');
    return {
      style: styleEl ? styleEl.value : 'print',
      terms: termEl ? termEl.value : '',
      note:  f.querySelector('[name=note]').value || ''
    };
  }
  function qs(p){ return '?style=' + encodeURIComponent(p.style) + '&terms=' + encodeURIComponent(p.terms) + '&note=' + encodeURIComponent(p.note); }

  function setSrc(){
    var p = params();
    document.getElementById('invFrame').src = URL_PREVIEW_HTML + qs(p);
    document.getElementById('invCap').style.display = (p.style === 'branded') ? 'block' : 'none';
  }
  window.invRefresh = function(){ clearTimeout(refreshTimer); refreshTimer = setTimeout(setSrc, 350); };
  window.invRefreshNow = function(){ clearTimeout(refreshTimer); setSrc(); };

  function sizeFrame(){
    var pane = document.getElementById('invPreview');
    var frame = document.getElementById('invFrame');
    var wrap = document.getElementById('invFrameWrap');
    if(!pane || pane.offsetParent === null) return; // hidden on mobile
    var avail = pane.clientWidth - 44;
    var scale = Math.min(1, avail / 816);
    frame.style.width = '816px';
    frame.style.height = '1056px';
    frame.style.transform = 'scale(' + scale + ')';
    frame.style.transformOrigin = 'top left';
    wrap.style.width  = (816 * scale) + 'px';
    wrap.style.height = (1056 * scale) + 'px';
  }
  window.invSizeFrame = sizeFrame;

  // Export actions always use Print (the only style dompdf renders today).
  window.invGo = function(kind){
    var p = params();
    var base = (kind === 'preview') ? URL_PDF : URL_DOWNLOAD;
    var url = base + '?terms=' + encodeURIComponent(p.terms) + '&note=' + encodeURIComponent(p.note);
    if(kind === 'preview'){ window.open(url, '_blank'); } else { window.location = url; }
  };

  // Init when the (patch-205) trigger opens the dialog.
  var dlg = document.getElementById('invoiceModal');
  if(dlg){
    var _show = dlg.showModal.bind(dlg);
    dlg.showModal = function(){
      _show();
      setSrc();
      requestAnimationFrame(sizeFrame);
    };
    dlg.addEventListener('click', function(e){ if(e.target === dlg) dlg.close(); });
    document.getElementById('invFrame').addEventListener('load', sizeFrame);
    window.addEventListener('resize', sizeFrame);
  }
})();
</script>
