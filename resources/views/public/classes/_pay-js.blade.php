{{-- MARKER-CLASSES-P3: the studio's Stripe card field and pay flow, shared by checkout, the class page and My passes.
     The card field shows at once; the payment is only made on Pay (Stripe's deferred intent flow). --}}
<script src="https://js.stripe.com/v3/"></script>
<script>
window.ClassPay = (function () {
  var stripe = null, elements = null, mode = 'payment';
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;

  function mount(o) {
    if (!o.pk || typeof Stripe !== 'function') return false;
    mode = o.mode || 'payment';
    stripe = stripe || Stripe(o.pk);
    var eo = { mode: mode, currency: 'usd', appearance: { variables: { fontFamily: getComputedStyle(document.body).fontFamily, borderRadius: '8px' } } };
    if (mode === 'payment') { eo.amount = o.amount; }
    if (o.saveCard) { eo.setupFutureUsage = 'off_session'; }
    if (o.saveCard || mode === 'setup') { eo.paymentMethodTypes = ['card']; }
    elements = stripe.elements(eo);
    elements.create('payment', { layout: 'tabs' }).mount(o.el);
    return true;
  }

  function show(errEl, msg) { errEl.textContent = msg; errEl.style.display = 'block'; }

  /** Validate the card, start the payment on the server, then confirm it. */
  function pay(url, payload, errEl, btn) {
    errEl.style.display = 'none';
    if (!elements) { show(errEl, 'Card payments are not available right now.'); return; }
    btn.disabled = true;
    elements.submit().then(function (r) {
      if (r.error) { show(errEl, r.error.message); btn.disabled = false; return; }
      return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(payload || {})
      }).then(function (res) { return res.json().then(function (d) { return { ok: res.ok, d: d }; }); })
        .then(function (res) {
          if (!res.ok || !res.d.ok) {
            var m = res.d && (res.d.message || (res.d.errors && Object.values(res.d.errors)[0][0]));
            show(errEl, m || 'Could not start the payment.'); btn.disabled = false; return;
          }
          var args = { elements: elements, clientSecret: res.d.client_secret, confirmParams: { return_url: res.d.return_url }, redirect: 'if_required' };
          var go = mode === 'setup' ? stripe.confirmSetup(args) : stripe.confirmPayment(args);
          return go.then(function (out) {
            if (out.error) { show(errEl, out.error.message || 'The payment didn\'t go through.'); btn.disabled = false; return; }
            var sep = res.d.return_url.indexOf('?') === -1 ? '?' : '&';
            window.location = res.d.return_url + sep + (mode === 'setup'
              ? 'setup_intent=' + encodeURIComponent(out.setupIntent.id)
              : 'payment_intent=' + encodeURIComponent(out.paymentIntent.id));
          });
        });
    }).catch(function () { show(errEl, 'Network error. Try again.'); btn.disabled = false; });
  }

  function update(o) { if (elements) elements.update(o); }

  return { mount: mount, pay: pay, update: update };
})();
</script>
