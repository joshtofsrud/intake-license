/*
 * MARKER-CAMERA-SCAN — read a barcode with the device camera.
 *
 *   IntakeScan.attach(input, onCode, { inset, placeholder })
 *     Adds a camera button to a search box when this device has a camera
 *     (nothing is added otherwise). onCode(code) gets the barcode's digits.
 *   IntakeScan.open({ onCode, onType })
 *     The full-screen camera view on its own.
 *
 * Reads UPC-A/E, EAN-13/8, Code 128 and Code 39. Uses the browser's own
 * BarcodeDetector where it exists (Android Chrome). Elsewhere — iPhone
 * Safari has none — it loads the ZXing reader bundled with Intake from
 * /vendor/zxing/ on first use. No outside service is involved, and the
 * offline service worker caches the reader once it has been opened.
 * A code must read the same twice in a row before it is accepted.
 */
(function () {
  'use strict';

  var FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39'];
  var ZXING_SRC = '/vendor/zxing/zxing-library.min.js?v=0.23.0';
  var zxingLoading = null;
  var cameraCheck = null;

  function hasCamera() {
    if (cameraCheck) { return cameraCheck; }
    cameraCheck = (async function () {
      if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { return false; }
      try {
        var devices = await navigator.mediaDevices.enumerateDevices();
        return devices.some(function (d) { return d.kind === 'videoinput'; });
      } catch (e) { return false; }
    })();
    return cameraCheck;
  }

  function loadZxing() {
    if (window.ZXing) { return Promise.resolve(window.ZXing); }
    if (zxingLoading) { return zxingLoading; }
    zxingLoading = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = ZXING_SRC;
      s.async = true;
      s.onload = function () { window.ZXing ? resolve(window.ZXing) : reject(new Error('reader did not load')); };
      s.onerror = function () { zxingLoading = null; reject(new Error('reader did not load')); };
      document.head.appendChild(s);
    });
    return zxingLoading;
  }

  async function nativeDetector() {
    if (!('BarcodeDetector' in window)) { return null; }
    try {
      var supported = await window.BarcodeDetector.getSupportedFormats();
      var want = FORMATS.filter(function (f) { return supported.indexOf(f) !== -1; });
      return want.length ? new window.BarcodeDetector({ formats: want }) : null;
    } catch (e) { return null; }
  }

  function el(tag, style, text) {
    var n = document.createElement(tag);
    if (style) { n.style.cssText = style; }
    if (text) { n.textContent = text; }
    return n;
  }

  var BTN = 'background:transparent;border:0.5px solid #888780;border-radius:10px;color:#F1EFE8;'
    + 'font:inherit;font-size:14px;padding:10px 16px;cursor:pointer';

  function open(opts) {
    opts = opts || {};
    var closed = false, stream = null, track = null, reader = null, timer = null;
    var last = null, audio = null;

    try { audio = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { audio = null; }

    var overlay = el('div', 'position:fixed;inset:0;z-index:10000;background:#000;display:flex;flex-direction:column;'
      + 'font-family:inherit;color:#F1EFE8');
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-label', 'Scan a barcode');

    var head = el('div', 'display:flex;justify-content:space-between;align-items:center;padding:16px 18px;'
      + 'padding-top:calc(16px + env(safe-area-inset-top, 0px));position:relative;z-index:2');
    head.appendChild(el('span', 'font-size:16px', 'Scan a barcode'));
    var closeBtn = el('button', 'background:transparent;border:0;color:#F1EFE8;font-size:28px;line-height:1;cursor:pointer;padding:0 4px', '×');
    closeBtn.setAttribute('aria-label', 'Close the camera');
    head.appendChild(closeBtn);

    var stage = el('div', 'position:relative;flex:1;overflow:hidden;display:flex;align-items:center;justify-content:center');
    var video = el('video', 'position:absolute;inset:0;width:100%;height:100%;object-fit:cover');
    video.setAttribute('playsinline', '');
    video.setAttribute('muted', '');
    video.muted = true;
    video.autoplay = true;
    var frame = el('div', 'position:relative;width:min(78vw,340px);height:min(40vw,170px);border:2px solid #EF9F27;'
      + 'border-radius:12px;box-shadow:0 0 0 100vmax rgba(0,0,0,.45)');
    frame.appendChild(el('div', 'position:absolute;left:10px;right:10px;top:50%;height:2px;background:#E24B4A'));
    var hint = el('div', 'position:absolute;left:0;right:0;bottom:24px;text-align:center;font-size:14px;color:#D3D1C7;padding:0 24px',
      'Hold steady over the barcode');
    stage.appendChild(video);
    stage.appendChild(frame);
    stage.appendChild(hint);

    var foot = el('div', 'display:flex;gap:12px;justify-content:center;padding:16px;'
      + 'padding-bottom:calc(16px + env(safe-area-inset-bottom, 0px));position:relative;z-index:2');
    var lightBtn = el('button', BTN + ';display:none', 'Light');
    var typeBtn = el('button', BTN, 'Type it');
    foot.appendChild(lightBtn);
    foot.appendChild(typeBtn);

    overlay.appendChild(head);
    overlay.appendChild(stage);
    overlay.appendChild(foot);
    document.body.appendChild(overlay);
    var prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function close() {
      if (closed) { return; }
      closed = true;
      if (timer) { clearTimeout(timer); }
      try { if (reader) { reader.reset(); } } catch (e) {}
      if (stream) { stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} }); }
      try { if (audio) { audio.close(); } } catch (e) {}
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prevOverflow;
      overlay.remove();
    }

    function beep() {
      try { if (navigator.vibrate) { navigator.vibrate(40); } } catch (e) {}
      if (!audio) { return; }
      try {
        var o = audio.createOscillator(), g = audio.createGain();
        o.frequency.value = 1100;
        g.gain.value = 0.08;
        o.connect(g); g.connect(audio.destination);
        o.start(); o.stop(audio.currentTime + 0.07);
      } catch (e) {}
    }

    // Accept a code only when two reads in a row agree.
    function seen(code) {
      code = String(code || '').trim();
      if (!code || closed) { return; }
      if (last !== code) { last = code; return; }
      beep();
      close();
      if (typeof opts.onCode === 'function') { opts.onCode(code); }
    }

    function fail(message) {
      hint.textContent = message;
      hint.style.color = '#F09595';
      frame.style.display = 'none';
    }

    function onKey(e) { if (e.key === 'Escape') { close(); } }
    document.addEventListener('keydown', onKey);
    closeBtn.addEventListener('click', close);
    typeBtn.addEventListener('click', function () {
      close();
      if (typeof opts.onType === 'function') { opts.onType(); }
    });
    lightBtn.addEventListener('click', function () {
      if (!track) { return; }
      var on = lightBtn.getAttribute('aria-pressed') !== 'true';
      track.applyConstraints({ advanced: [{ torch: on }] }).then(function () {
        lightBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
        lightBtn.style.borderColor = on ? '#EF9F27' : '#888780';
      }).catch(function () { lightBtn.style.display = 'none'; });
    });

    (async function start() {
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          audio: false,
          video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
        });
      } catch (e) {
        fail(e && e.name === 'NotAllowedError'
          ? 'Camera access is blocked for this site. Allow it in your browser settings, or tap Type it.'
          : 'The camera could not start. Tap Type it to enter the code.');
        return;
      }
      if (closed) { stream.getTracks().forEach(function (t) { t.stop(); }); return; }

      track = stream.getVideoTracks()[0] || null;
      try {
        var caps = track && track.getCapabilities ? track.getCapabilities() : {};
        if (caps && caps.torch) { lightBtn.style.display = ''; }
      } catch (e) {}

      var detector = await nativeDetector();
      if (detector) {
        video.srcObject = stream;
        try { await video.play(); } catch (e) {}
        var tick = async function () {
          if (closed) { return; }
          try {
            if (video.readyState >= 2) {
              var found = await detector.detect(video);
              if (found && found.length) { seen(found[0].rawValue); }
            }
          } catch (e) {}
          if (!closed) { timer = setTimeout(tick, 120); }
        };
        tick();
        return;
      }

      try {
        var ZX = await loadZxing();
        if (closed) { return; }
        var hints = new Map();
        hints.set(ZX.DecodeHintType.POSSIBLE_FORMATS, [
          ZX.BarcodeFormat.EAN_13, ZX.BarcodeFormat.EAN_8, ZX.BarcodeFormat.UPC_A,
          ZX.BarcodeFormat.UPC_E, ZX.BarcodeFormat.CODE_128, ZX.BarcodeFormat.CODE_39,
        ]);
        hints.set(ZX.DecodeHintType.TRY_HARDER, true);
        reader = new ZX.BrowserMultiFormatReader(hints, 120);
        reader.decodeFromStream(stream, video, function (result) {
          if (result) { seen(result.getText()); }
        }).catch(function () {});
      } catch (e) {
        fail('The barcode reader could not load. Tap Type it to enter the code.');
      }
    })();

    return { close: close };
  }

  var STYLE_ID = 'intake-scan-style';
  function injectStyle() {
    if (document.getElementById(STYLE_ID)) { return; }
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent =
      '.intake-scan-btn{display:inline-flex;align-items:center;justify-content:center;width:38px;height:34px;flex:0 0 auto;'
      + 'border-radius:8px;border:0.5px solid var(--ia-accent,#d4a24c);background:transparent;color:var(--ia-accent,#d4a24c);cursor:pointer;padding:0}'
      + '.intake-scan-btn:hover{background:rgba(212,162,76,.12)}'
      + '.intake-scan-btn--inset{position:absolute;right:6px;top:50%;transform:translateY(-50%)}'
      + '.intake-scan-btn svg{width:19px;height:19px}';
    document.head.appendChild(s);
  }

  var CAMERA_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    + '<path d="M5 7h2l2-3h6l2 3h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2"/><circle cx="12" cy="13" r="3.5"/></svg>';

  function attach(input, onCode, options) {
    options = options || {};
    if (!input || input.dataset.scanAttached) { return; }
    input.dataset.scanAttached = '1';
    hasCamera().then(function (ok) {
      if (!ok) { return; }
      injectStyle();
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'intake-scan-btn' + (options.inset ? ' intake-scan-btn--inset' : '');
      btn.setAttribute('aria-label', 'Scan a barcode with the camera');
      btn.title = 'Scan a barcode';
      btn.innerHTML = CAMERA_SVG;
      if (options.inset) {
        var parent = input.parentNode;
        if (parent && getComputedStyle(parent).position === 'static') { parent.style.position = 'relative'; }
        input.style.paddingRight = '52px';
      }
      input.insertAdjacentElement('afterend', btn);
      if (options.placeholder) { input.placeholder = options.placeholder; }
      btn.addEventListener('click', function () {
        open({
          onCode: onCode,
          onType: function () { input.focus(); },
        });
      });
    });
  }

  window.IntakeScan = { attach: attach, open: open, hasCamera: hasCamera };
})();
