{{-- MARKER-MKTTRAFFIC — marketing-site funnel tracking. Mirrors the tenant
     tracker's contract but posts to the platform endpoint, since /funnel/track
     is tenant-host only. Anonymous: a random session id in sessionStorage, no
     third party, no fingerprinting. --}}
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
    // MARKER-MKTSID -- storage unavailable: send NO id and let the server's
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
      session_id:   sid || null, // MARKER-MKTSID
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

  send('page_view');

  // Pricing is the clearest intent signal the marketing site has today.
  if (/^\/pricing/.test(window.location.pathname)) {
    send('pricing_viewed');
  }
})();
</script>
