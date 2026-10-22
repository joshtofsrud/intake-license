{{-- MARKER-CLASSES-P3: share a pass's buy link (ads, social posts, a QR at the desk).
     Open with clShare(name, url, isIntro). Uses the page's cl-modal styles. --}}
<div class="cl-modal-overlay" id="share-modal" onclick="if(event.target===this)clShareClose()">
  <div class="cl-modal">
    <div class="cl-modal-title" id="share-title">Share link</div>
    <p style="font-size:12.5px;color:var(--ia-text-muted);margin:-8px 0 14px" id="share-help">Anyone with this link lands on checkout for this pass.</p>
    <div style="display:flex;gap:6px">
      <input type="text" class="cl-input" id="share-url" readonly onfocus="this.select()">
      <button type="button" class="ia-btn ia-btn--secondary" id="share-copy" onclick="clShareCopy()">Copy</button>
    </div>
    <div style="display:flex;gap:16px;align-items:center;margin-top:16px">
      <div id="share-qr" style="width:132px;height:132px;background:#fff;padding:8px;border-radius:8px;flex-shrink:0"></div>
      <div style="font-size:12.5px;color:var(--ia-text-muted);line-height:1.55">
        Print the QR code for the front desk or a flyer.
        <div style="margin-top:8px"><button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="clShareDownload()">Download QR</button></div>
      </div>
    </div>
    <div class="cl-modal-footer">
      <button type="button" class="ia-btn ia-btn--ghost" onclick="clShareClose()">Done</button>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script>
(function () {
  var svg = '', name = '';
  window.clShare = function (n, url, intro) {
    name = n;
    document.getElementById('share-title').textContent = 'Share ' + n;
    document.getElementById('share-help').textContent = intro
      ? 'Use this in your ads and social posts. It opens checkout for this offer; people who have had it before are shown your other passes.'
      : 'Anyone with this link lands on checkout for this pass.';
    document.getElementById('share-url').value = url;
    document.getElementById('share-copy').textContent = 'Copy';
    var box = document.getElementById('share-qr');
    box.innerHTML = '';
    if (typeof qrcode === 'function') {
      var qr = qrcode(0, 'M'); qr.addData(url); qr.make();
      svg = qr.createSvgTag({ scalable: true, margin: 0 });
      box.innerHTML = svg;
      box.querySelector('svg').style.cssText = 'width:100%;height:100%';
    }
    document.getElementById('share-modal').classList.add('is-open');
  };
  window.clShareClose = function () { document.getElementById('share-modal').classList.remove('is-open'); };
  window.clShareCopy = function () {
    var i = document.getElementById('share-url'), b = document.getElementById('share-copy');
    var done = function () { b.textContent = 'Copied'; };
    if (navigator.clipboard) { navigator.clipboard.writeText(i.value).then(done, function () { i.select(); document.execCommand('copy'); done(); }); }
    else { i.select(); document.execCommand('copy'); done(); }
  };
  window.clShareDownload = function () {
    if (!svg) return;
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
    a.download = (name || 'pass').replace(/[^a-z0-9]+/gi, '-').toLowerCase() + '-qr.svg';
    document.body.appendChild(a); a.click(); a.remove();
  };
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') clShareClose(); });
})();
</script>
