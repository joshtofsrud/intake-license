{{-- marketing-site funnel tracking. Mirrors the tenant
     tracker's contract but posts to the platform endpoint, since /funnel/track
     is tenant-host only. Anonymous: a random session id in sessionStorage, no
     third party, no fingerprinting. --}}
@include('marketing._analytics')
<script>
(function () {
  if (window.__intakeMktFunnel) { return; }
  window.__intakeMktFunnel = true;

  var KEY = 'intake_mkt_sid';
  var sid;
  try {
    sid = sessionStorage.getItem(KEY);
    if (!sid) {
      sid = (Date.now().toString(36) + Math.random().toString(36).slice(2, 12));
      sessionStorage.setItem(KEY, sid);
    }
  } catch (e) {
    // storage unavailable: send NO id and let the server's
    // mkt_sid cookie identify this visitor. The old 'nostore' literal made
    // every storage-blocked visitor share one session.
    sid = null;
  }

  var params = new URLSearchParams(window.location.search);

  function device() {
    var ua = navigator.userAgent || '';
    if (/bot|crawl|spider|slurp/i.test(ua)) { return 'bot'; }
    if (/tablet|ipad/i.test(ua)) { return 'tablet'; }
    if (/mobi|android|iphone/i.test(ua)) { return 'mobile'; }
    return 'desktop';
  }

  function send(eventType, step) {
    var payload = JSON.stringify({
      session_id:   sid || null,
      event_type:   eventType,
      path:         window.location.pathname,
      referrer_url: document.referrer || null,
      utm_source:   params.get('utm_source') || null,
      utm_medium:   params.get('utm_medium') || null,
      utm_campaign: params.get('utm_campaign') || null,
      device:       device(),
      step:         step || null
    });

    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon('/mkt/track', new Blob([payload], { type: 'application/json' }));
        return;
      }
    } catch (e) { /* fall through */ }

    fetch('/mkt/track', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: payload,
      keepalive: true
    }).catch(function () {});
  }

  window.__intakeMktTrack = send;

  // MARKER-PRERENDER-VIEWS: Chrome can load a page in the background while
  // someone types its address (prerendering), running this script before
  // anyone sees the page. Count it only once it's actually shown; a
  // background load that's never opened isn't a view at all.
  function whenShown(fn) {
    if (document.prerendering) {
      document.addEventListener('prerenderingchange', function () { fn(); }, { once: true });
    } else {
      fn();
    }
  }

  var shown = false;
  whenShown(function () { shown = true; send('page_view'); });

  // clicks on the things worth knowing about. Delegated, so
  // it covers markup added later; label comes from data-track when set, and
  // otherwise from the destination, which keeps builder sections working
  // without anyone remembering to annotate them.
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a,button[data-track]') : null;
    if (!a) { return; }
    var label = a.getAttribute('data-track');
    if (!label) {
      var href = a.getAttribute('href') || '';
      if (/^\/demo(\/|$)/.test(href))        { label = 'demo'; }
      else if (/^\/book(\/|$)/.test(href))   { label = 'book'; }
      else if (/^\/pricing/.test(href))      { label = 'pricing'; }
      else if (/^\/invest/.test(href))       { label = 'invest'; }
      else { return; } // everything else is ordinary navigation
    }
    send('cta_click', label);
  }, true);

  // without an end event every single-page session reads 0:00
  // however long it was actually read. One beacon on the way out fixes it.
  var exited = false;
  function exit() {
    if (exited || ! shown) { return; } // a background load that was never opened has nothing to end
    exited = true;
    send('page_exit');
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') { exit(); }
  });
  window.addEventListener('pagehide', exit);

  // MARKER-OUTREACH-SCANNERS: email security filters open every link in a
  // hidden browser and run this script, but nobody moves a mouse there. For a
  // visit that came from an outreach email, say once when a person actually
  // moves, scrolls, taps or types. Nothing about where or what is sent.
  var OUT = 'intake_mkt_out';
  try { if (params.get('utm_source') === 'outreach') sessionStorage.setItem(OUT, '1'); } catch (e) {}
  var fromOutreach = false;
  try { fromOutreach = sessionStorage.getItem(OUT) === '1'; } catch (e) {}
  if (fromOutreach) {
    var moves = 0, lastXY = '', told = false;
    var evs = ['pointermove', 'touchstart', 'keydown', 'wheel'];
    var tell = function () {
      if (told || ! shown) { return; }
      told = true;
      evs.forEach(function (n) { window.removeEventListener(n, onAct, true); });
      send('interact');
    };
    var onAct = function (e) {
      if (! e.isTrusted) { return; }
      if (e.type === 'pointermove') {
        var xy = e.clientX + ',' + e.clientY;
        if (xy === lastXY) { return; }
        lastXY = xy;
        if (++moves < 3) { return; } // a real hand moves; one synthetic jump doesn't count
      }
      tell();
    };
    evs.forEach(function (n) { window.addEventListener(n, onAct, { capture: true, passive: true }); });
  }

  // Pricing is the clearest intent signal the marketing site has today.
  if (/^\/pricing/.test(window.location.pathname)) {
    whenShown(function () { send('pricing_viewed'); });
  }
})();
</script>
