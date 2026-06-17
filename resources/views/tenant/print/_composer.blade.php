{{-- MARKER-PATCH-337 — Print & Send composer. One surface for every printed/
     emailed document. Opened via window.openPrintComposer(source, id, ctx).
     source = 'appointment' | 'sale'. ctx = { type, format, number }.
     Fetches the document's assets from /print/{source}/{id}/meta, builds the
     option set, previews live by pointing an iframe at /print/... ?embed=1,
     and prints via a hidden iframe. Email/download activate in later patches. --}}
<dialog id="pcomposer" class="pc-dialog">
  <div class="pc-wrap">
    <div class="pc-head">
      <div><div class="pc-title">Print &amp; Send</div><div class="pc-ctx" id="pc-ctx">—</div></div>
      <button type="button" class="pc-x" onclick="document.getElementById('pcomposer').close()">&times;</button>
    </div>

    <div class="pc-body">
      <div class="pc-controls">

        <div class="pc-grp">
          <div class="pc-lbl">Document</div>
          <div class="pc-seg" id="pc-doc"></div>
        </div>

        <div class="pc-grp">
          <div class="pc-lbl">Format</div>
          <div class="pc-seg pc-col" id="pc-fmt"></div>
        </div>

        <div class="pc-grp" id="pc-assets-grp" style="display:none">
          <div class="pc-lbl">Assets <a href="#" id="pc-asset-all">all</a></div>
          <div id="pc-assets"></div>
          <label class="pc-tg" id="pc-split"><span>Separate slip per asset</span><i class="pc-sw"></i></label>
        </div>

        <div class="pc-grp">
          <div class="pc-lbl">Include</div>
          <label class="pc-tg on" data-inc="notes_customer"><span>Customer notes</span><i class="pc-sw"></i></label>
          <label class="pc-tg" data-inc="notes_staff"><span>Staff notes</span><i class="pc-sw"></i></label>
          <label class="pc-tg on" data-inc="prices"><span>Prices &amp; totals</span><i class="pc-sw"></i></label>
          <label class="pc-tg" data-inc="ledger" id="pc-ledger-row"><span>Payment history</span><i class="pc-sw"></i></label>
          <label class="pc-tg" data-inc="qr" id="pc-qr-row"><span>Status QR (tag)</span><i class="pc-sw"></i></label>
        </div>

        <div class="pc-grp">
          <div class="pc-lbl">Send to</div>
          <div class="pc-send" id="pc-send">
            <button type="button" data-send="print" class="on">Print</button>
            <button type="button" data-send="email">Email</button>
          </div>
          <div id="pc-emailbox" style="display:none;margin-top:8px">
            <input type="email" id="pc-email" class="pc-input" placeholder="customer email">
          </div>
          <button type="button" class="pc-go" id="pc-go">Print now</button>
        </div>

      </div>

      <div class="pc-preview">
        <div class="pc-pv-head">Preview</div>
        <iframe id="pc-frame" title="preview"></iframe>
      </div>
    </div>
  </div>
</dialog>

<style>
  .pc-dialog{border:0;border-radius:16px;padding:0;background:#141414;color:#f0f0f0;
    width:min(940px,94vw);max-width:94vw;box-shadow:0 30px 80px rgba(0,0,0,.6)}
  .pc-dialog::backdrop{background:rgba(0,0,0,.6)}
  .pc-wrap{font-family:Inter,system-ui,sans-serif}
  .pc-head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid rgba(255,255,255,.08)}
  .pc-title{font-size:16px;font-weight:600}
  .pc-ctx{font-size:12px;color:rgba(255,255,255,.5);margin-top:2px}
  .pc-x{background:none;border:0;color:rgba(255,255,255,.5);font-size:24px;cursor:pointer;line-height:1}
  .pc-body{display:grid;grid-template-columns:300px 1fr;gap:0}
  @media(max-width:720px){.pc-body{grid-template-columns:1fr}.pc-preview{display:none}}
  .pc-controls{padding:6px 18px 18px;max-height:70vh;overflow:auto}
  .pc-grp{padding:14px 0;border-bottom:1px solid rgba(255,255,255,.07)}
  .pc-grp:last-child{border-bottom:0}
  .pc-lbl{font-size:10px;letter-spacing:.13em;text-transform:uppercase;color:rgba(255,255,255,.32);font-weight:600;margin-bottom:10px;display:flex;justify-content:space-between}
  .pc-lbl a{color:#BEF264;text-decoration:none;text-transform:none;letter-spacing:0;font-size:11px}
  .pc-seg{display:flex;gap:6px;flex-wrap:wrap}
  .pc-seg.pc-col{flex-direction:column}
  .pc-seg button{flex:1;background:#0a0a0a;border:1px solid rgba(255,255,255,.08);color:rgba(255,255,255,.5);
    border-radius:9px;padding:9px 10px;font-size:12.5px;font-weight:500;cursor:pointer;white-space:nowrap}
  .pc-seg button.on{background:#BEF264;border-color:#BEF264;color:#0c0c0c;font-weight:600}
  .pc-seg button[disabled]{opacity:.3;cursor:not-allowed}
  .pc-asset{display:flex;align-items:center;gap:9px;background:#0a0a0a;border:1px solid rgba(255,255,255,.08);
    border-radius:9px;padding:9px 11px;margin-bottom:7px;cursor:pointer;font-size:13px}
  .pc-asset.on{border-color:rgba(190,242,100,.5);background:rgba(190,242,100,.05)}
  .pc-asset i{width:16px;height:16px;border-radius:4px;border:1.5px solid rgba(255,255,255,.3);flex:0 0 auto}
  .pc-asset.on i{background:#BEF264;border-color:#BEF264}
  .pc-tg{display:flex;align-items:center;justify-content:space-between;padding:8px 0;cursor:pointer;font-size:13px}
  .pc-tg+.pc-tg{border-top:1px solid rgba(255,255,255,.07)}
  .pc-sw{width:36px;height:21px;border-radius:99px;background:#2a2a2a;border:1px solid rgba(255,255,255,.08);position:relative;flex:0 0 auto}
  .pc-sw::after{content:'';position:absolute;top:2px;left:2px;width:15px;height:15px;border-radius:50%;background:#6b6b6b;transition:.13s}
  .pc-tg.on .pc-sw{background:#BEF264;border-color:#BEF264}
  .pc-tg.on .pc-sw::after{left:18px;background:#0c0c0c}
  .pc-tg.dim{opacity:.35;pointer-events:none}
  .pc-send{display:flex;gap:6px}
  .pc-send button{flex:1;background:#0a0a0a;border:1px solid rgba(255,255,255,.08);color:rgba(255,255,255,.5);border-radius:9px;padding:9px;font-size:12.5px;cursor:pointer}
  .pc-send button.on{background:#191919;border-color:rgba(255,255,255,.14);color:#f0f0f0}
  .pc-input{width:100%;background:#0a0a0a;border:1px solid rgba(255,255,255,.08);color:#f0f0f0;border-radius:9px;padding:10px 12px;font-size:13px}
  .pc-go{width:100%;margin-top:12px;background:#BEF264;color:#0c0c0c;border:0;border-radius:10px;padding:13px;font-size:14px;font-weight:600;cursor:pointer}
  .pc-preview{background:repeating-linear-gradient(45deg,#0e0e0e 0 12px,#0b0b0b 12px 24px);border-left:1px solid rgba(255,255,255,.08);padding:14px;display:flex;flex-direction:column}
  .pc-pv-head{font-size:11px;color:rgba(255,255,255,.5);margin-bottom:8px}
  #pc-frame{flex:1;width:100%;min-height:62vh;border:0;border-radius:8px;background:#fff}
</style>

<script>
(function () {
  const dlg = document.getElementById('pcomposer');
  if (!dlg || window.openPrintComposer) return;

  const DOC = {
    receipt: { label:'Receipt', fmts:['t80','t58','full','inv'], def:'t80' },
    tag:     { label:'Service tag', fmts:['t80','t58'], def:'t80' },
    invoice: { label:'Invoice', fmts:['full','inv'], def:'inv' },
  };
  const FMT = { t80:'Thermal 80mm', t58:'Thermal 58mm', full:'Full page', inv:'Graphical invoice' };
  // url format token -> builder format param
  const FMTPARAM = { t80:'t80', t58:'t58', full:'full', inv:'invoice' };

  let S = null; // state

  function base() { return window.location.origin + '/admin/print/' + S.source + '/' + S.id; }

  function params(embed) {
    const p = new URLSearchParams();
    p.set('type', S.type);
    p.set('format', FMTPARAM[S.fmt]);
    if (embed) p.set('embed', '1');
    p.set('prices', S.inc.prices ? '1' : '0');
    p.set('notes_customer', S.inc.notes_customer ? '1' : '0');
    p.set('notes_staff', S.inc.notes_staff ? '1' : '0');
    p.set('ledger', S.inc.ledger ? '1' : '0');
    p.set('qr', (S.type === 'tag' && S.inc.qr) ? '1' : '0');
    if (S.split) p.set('split', '1');
    Object.keys(S.assets).filter(k => S.assets[k]).forEach(k => p.append('assets[]', k));
    return p.toString();
  }

  function preview() {
    document.getElementById('pc-frame').src = base() + '?' + params(true);
  }

  function renderDoc() {
    const wrap = document.getElementById('pc-doc'); wrap.innerHTML = '';
    S.allowedDocs.forEach(d => {
      const b = document.createElement('button');
      b.textContent = DOC[d].label; b.className = d === S.type ? 'on' : '';
      b.onclick = () => { S.type = d; if (!DOC[d].fmts.includes(S.fmt)) S.fmt = DOC[d].def; sync(); };
      wrap.appendChild(b);
    });
  }
  function renderFmt() {
    const wrap = document.getElementById('pc-fmt'); wrap.innerHTML = '';
    const allowed = DOC[S.type].fmts;
    ['t80','t58','full','inv'].forEach(f => {
      const b = document.createElement('button');
      b.textContent = FMT[f]; b.disabled = !allowed.includes(f);
      b.className = f === S.fmt ? 'on' : '';
      b.onclick = () => { if (b.disabled) return; S.fmt = f; sync(); };
      wrap.appendChild(b);
    });
  }
  function renderAssets() {
    const grp = document.getElementById('pc-assets-grp');
    if (!S.assetList.length) { grp.style.display = 'none'; return; }
    grp.style.display = '';
    const wrap = document.getElementById('pc-assets'); wrap.innerHTML = '';
    S.assetList.forEach(a => {
      const row = document.createElement('div');
      row.className = 'pc-asset' + (S.assets[a.id] ? ' on' : '');
      row.innerHTML = '<i></i><span>' + (a.name || 'Asset') + '</span>';
      row.onclick = () => { S.assets[a.id] = !S.assets[a.id]; sync(); };
      wrap.appendChild(row);
    });
  }

  function sync() {
    renderDoc(); renderFmt(); renderAssets();
    // QR only for tag; ledger only when payments exist
    const qr = document.getElementById('pc-qr-row');
    qr.classList.toggle('dim', S.type !== 'tag');
    document.getElementById('pc-ledger-row').classList.toggle('dim', !S.hasPayments);
    document.querySelectorAll('.pc-tg[data-inc]').forEach(t => t.classList.toggle('on', !!S.inc[t.dataset.inc]));
    document.getElementById('pc-split').classList.toggle('on', S.split);
    document.getElementById('pc-go').textContent = (S.action === 'email' ? 'Send email' : 'Print now');
    document.getElementById('pc-emailbox').style.display = S.action === 'email' ? '' : 'none';
    preview();
  }

  document.querySelectorAll('.pc-tg[data-inc]').forEach(t => t.addEventListener('click', () => {
    if (t.classList.contains('dim')) return;
    S.inc[t.dataset.inc] = !S.inc[t.dataset.inc]; sync();
  }));
  document.getElementById('pc-split').addEventListener('click', () => { S.split = !S.split; sync(); });
  document.getElementById('pc-asset-all').addEventListener('click', e => {
    e.preventDefault(); S.assetList.forEach(a => S.assets[a.id] = true); sync();
  });
  document.querySelectorAll('#pc-send button').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('#pc-send button').forEach(x => x.classList.remove('on'));
    b.classList.add('on'); S.action = b.dataset.send; sync();
  }));

  document.getElementById('pc-go').addEventListener('click', () => {
    if (S.action === 'email') { alert('Email sending activates in a later step.'); return; }
    // thermal/full: print via hidden iframe; invoice PDF: open in new tab
    if (S.fmt === 'inv') { window.open(base() + '?' + params(false), '_blank'); return; }
    const f = document.createElement('iframe');
    f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    f.src = base() + '?' + params(true);
    f.onload = function () { try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) {} setTimeout(() => f.remove(), 2000); };
    document.body.appendChild(f);
  });

  window.openPrintComposer = function (source, id, ctx) {
    ctx = ctx || {};
    S = {
      source, id,
      type: ctx.type || 'receipt',
      fmt: ctx.format || 't80',
      assetList: [], assets: {}, split: false,
      action: 'print', hasPayments: false,
      allowedDocs: source === 'sale' ? ['receipt'] : ['receipt','tag','invoice'],
      inc: { notes_customer:true, notes_staff:false, prices:true, ledger:false, qr:false },
    };
    if (!DOC[S.type]) S.type = 'receipt';
    if (!DOC[S.type].fmts.includes(S.fmt)) S.fmt = DOC[S.type].def;
    document.getElementById('pc-ctx').textContent = (ctx.number ? (ctx.number + ' · ') : '') + (source === 'sale' ? 'Sale' : 'Work order');
    if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open','');

    // fetch assets + payment presence
    fetch(window.location.origin + '/admin/print/' + source + '/' + id + '/meta',
          { headers: { 'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json' } })
      .then(r => r.ok ? r.json() : {assets:[],has_payments:false})
      .then(meta => {
        S.assetList = meta.assets || [];
        S.assetList.forEach(a => S.assets[a.id] = true);
        S.hasPayments = !!meta.has_payments;
        if (!ctx.number && meta.number) document.getElementById('pc-ctx').textContent = meta.number + ' · ' + (source === 'sale' ? 'Sale' : 'Work order');
        sync();
      })
      .catch(() => sync());
  };
})();
</script>
