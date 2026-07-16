{{-- MARKER-PATCH-150 — tenant public-page analytics + funnel tracking --}}
@php
  $ga4Id = $currentTenant->settings['analytics_ga4_id'] ?? null;
@endphp

@if($ga4Id)
{{-- Google tag (gtag.js) — only when tenant has configured GA-4 --}}
<script async src="https://www.googletagmanager.com/gtag/js?id={{ $ga4Id }}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '{{ $ga4Id }}', { 'anonymize_ip': true });
</script>
@endif

{{-- Native funnel tracking — anonymous, no third-party --}}
<script>
(function(){
  if (window.__intakeFunnelLoaded) return;
  window.__intakeFunnelLoaded = true;

  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  // Pull UTM params off the URL once on page load
  var params = new URLSearchParams(window.location.search);
  var utm = {
    utm_source:   params.get('utm_source')   || null,
    utm_medium:   params.get('utm_medium')   || null,
    utm_campaign: params.get('utm_campaign') || null,
  };

  // MARKER-FUNNEL-SESSION-FIX — the session id is minted CLIENT-side, once,
  // and rides in every payload. Server-minted ids fragmented first visits:
  // several beacons raced out before any Set-Cookie landed, so one person's
  // click became two or three phantom "sessions" and the started tile could
  // never reconcile with the funnel steps.
  function fnlSid() {
    try {
      var m = document.cookie.match(/(?:^|;\s*)fnl_sid=([a-zA-Z0-9]{20,64})/);
      if (m) return m[1];
      var v = localStorage.getItem('ia_fnl_sid');
      if (!v || !/^[a-zA-Z0-9]{20,64}$/.test(v)) {
        v = '';
        if (window.crypto && crypto.getRandomValues) {
          var a = new Uint8Array(20); crypto.getRandomValues(a);
          for (var i = 0; i < a.length; i++) v += ('0' + a[i].toString(16)).slice(-2);
        } else {
          while (v.length < 40) v += Math.random().toString(36).slice(2);
          v = v.slice(0, 40);
        }
        localStorage.setItem('ia_fnl_sid', v);
      }
      document.cookie = 'fnl_sid=' + v + ';path=/;max-age=' + (60*60*24*90) + ';samesite=lax' + (location.protocol === 'https:' ? ';secure' : '');
      return v;
    } catch (e) { return null; }
  }
  var SID = fnlSid();
  window.__intakeFunnelSid = SID;

  function send(eventType, extra) {
    extra = extra || {};
    var body = Object.assign({
      event_type:   eventType,
      path:         window.location.pathname,
      referrer_url: document.referrer || null,
      session_id:   SID,
    }, utm, extra);

    // Strip nulls so the server-side validator stays happy
    Object.keys(body).forEach(function(k){ if (body[k] === null) delete body[k]; });

    try {
      // Use sendBeacon when available — fire-and-forget, survives nav
      if (navigator.sendBeacon) { // MARKER-FUNNEL-SESSION-FIX — one transport for everything
        var blob = new Blob([JSON.stringify(body)], { type: 'application/json' });
        navigator.sendBeacon('/funnel/track', blob);
        return;
      }
      fetch('/funnel/track', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'Accept':       'application/json',
        },
        body: JSON.stringify(body),
        keepalive: true,
        credentials: 'same-origin',
      }).catch(function(){ /* swallow — tracking should never break UX */ });
    } catch(e) { /* same */ }
  }

  // Expose for booking-page hooks
  window.__intakeFunnel = { send: send };

  // Always fire page_view on every public page
  send('page_view');

  // Booking-page hooks. The tracker loads in <head>, so the booking form
  // isn't in the DOM yet — defer setup until DOM-ready, and detect the booking
  // page by its surface (not the URL) so this fires on /book, custom domains,
  // and any page-builder slug. MARKER-PATCH-449
  function initBookingHooks() {
    if (!document.getElementById('bk-progress') && !document.querySelector('.bk-section')) return;
    send('booking_page_viewed');

    // MARKER-FUNNEL-SESSION-FIX — the old "first interaction = started"
    // emitter is gone. "Started" now has exactly one meaning: entered the
    // flow (choice-page click or direct landing), emitted by those pages.

    // MARKER-PATCH-452 — record each wizard step the anonymous session reaches,
    // so drop-off is visible even before any contact info exists. Deduped per step.
    (function trackSteps(){
      var seenSteps = {};
      function fireStep(){
        var active = document.querySelector('.bk-section.active');
        if (!active) return;
        var sections = Array.prototype.slice.call(document.querySelectorAll('.bk-section'));
        var idx = sections.indexOf(active);
        if (idx < 0) return;
        var h = active.querySelector('.bk-section-title');
        var label = (h ? h.textContent : (active.id || '')).replace(/\s+/g, ' ').trim().slice(0, 44);
        var key = (idx < 10 ? '0' + idx : '' + idx) + ' ' + label;
        if (seenSteps[key]) return;
        seenSteps[key] = true;
        send('booking_step', { step: key });
      }
      fireStep();
      try {
        var mo = new MutationObserver(function(){ fireStep(); });
        mo.observe(document.body, { attributes: true, attributeFilter: ['class'], subtree: true });
      } catch (e) {}
    })();

    // MARKER-RECOVERY — capture partial booking once contact info is entered.
    function readContact() {
      function v(id){ var el = document.getElementById(id); return el ? el.value.trim() : ''; }
      var first = v('bk-first-name'), last = v('bk-last-name');
      var name  = (first + ' ' + last).trim();
      var email = v('bk-email') || v('bk-pre-email');
      var phone = v('bk-phone');
      return { name: name, email: email, phone: phone };
    }
    function currentStep() {
      var active = document.querySelector('.bk-section.active');
      return active ? (active.id || '').replace('bk-step-','step ') : '';
    }
    function isEmail(s){ return /.+@.+\..+/.test(s); }
    function sendAbandon() {
      var c = readContact();
      if (!isEmail(c.email) && (c.phone || '').replace(/\D/g,'').length < 7) return; // need a real contact
      var payload = JSON.stringify({
        name: c.name, email: c.email, phone: c.phone,
        step_reached: currentStep(),
      });
      try {
        if (navigator.sendBeacon) {
          navigator.sendBeacon('/booking/abandon', new Blob([payload], { type: 'application/json' }));
        } else {
          fetch('/booking/abandon', { method:'POST', headers:{'Content-Type':'application/json'}, body: payload, keepalive: true, credentials:'same-origin' }).catch(function(){});
        }
      } catch(e){}
    }
    // Fire when they finish a contact field, and on leave.
    ['bk-email','bk-phone','bk-first-name','bk-last-name','bk-pre-email'].forEach(function(id){
      var el = document.getElementById(id);
      if (el) el.addEventListener('blur', sendAbandon);
    });
    document.addEventListener('visibilitychange', function(){ if (document.visibilityState === 'hidden') sendAbandon(); });
  }

  // Run now if the DOM is already parsed, otherwise wait for it. MARKER-PATCH-449
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBookingHooks);
  } else {
    initBookingHooks();
  }
})();
</script>
