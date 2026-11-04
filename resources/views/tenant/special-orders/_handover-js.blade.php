{{-- MARKER-SO-HANDOVER: actions on arrived orders, shared by the Arrived
     bench and the order page. Buttons carry data-so-act; the server decides
     and the shared dialog (layouts/tenant/_so-choices) asks when it must. --}}
@once
<script>
(function () {
  'use strict';
  var R = {
    handover: @json(route('tenant.special-orders.hand-over', ['id' => '__ID__'])),
    cancel:   @json(route('tenant.special-orders.cancel', ['id' => '__ID__'])),
    hold:     @json(route('tenant.special-orders.hold-now', ['id' => '__ID__'])),
    missing:  @json(route('tenant.special-orders.missing', ['id' => '__ID__'])),
    register: @json(route('tenant.register.index')),
  };
  function url(k, id) { return R[k].replace('__ID__', id); }
  function after(d) { if (d && d.ok) { setTimeout(function () { location.reload(); }, 600); } }

  function handOver(b) {
    var d = b.dataset;
    var qty = parseInt(d.qty || '1', 10);
    var units = qty + ' ' + d.item;
    if (d.kind === 'paid') {
      IntakeConfirm.show({
        title: 'Hand over ' + d.so + ' to ' + d.who + '?',
        message: 'It\'s paid on ' + (d.sale || 'its sale') + '. The hold is released and ' + units + ' leaves stock' + (d.at ? ' at ' + d.at : '') + '. Nothing is charged.',
        confirmText: 'Hand over', cancelText: 'Not now',
      }).then(function (ok) { if (ok) { IntakeSO.act(url('handover', d.id), {}).then(after); } });
      return;
    }
    var canRing = d.kind === 'unpaid' && d.customer && d.cataloged === '1';
    var opts = [];
    if (canRing) {
      opts.push({ value: 'ring', label: 'Ring it up at the register', help: 'Opens the register with ' + d.who + ' attached and this part in the cart. It\'s handed over at checkout.' });
    }
    opts.push({ value: 'free', label: 'Hand over without a sale', help: 'For a warranty swap or a part paid somewhere else. A reason is required and is kept on the order. ' + units + ' leaves stock.' });
    IntakeSO.choose({
      title: d.so + ' hasn\'t been paid',
      message: d.who + ' owes for this one.' + (canRing ? ' Ringing it up hands it over at checkout.' : ''),
      fields: [{ id: 'ho', label: '', options: opts, 'default': opts[0].value }],
      reason: { placeholder: 'Reason (required for hand over without a sale)', when: 'free' },
      confirm: 'Continue',
    }).then(function (ans) {
      if (!ans) { return; }
      if (ans.picks.ho === 'ring') {
        location.href = R.register + '?customer_id=' + encodeURIComponent(d.customer) + '&pickup=' + encodeURIComponent(d.id);
        return;
      }
      IntakeSO.act(url('handover', d.id), { reason: ans.reason }).then(after);
    });
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-so-act]');
    if (!b || !window.IntakeSO) { return; }
    var act = b.dataset.soAct, id = b.dataset.id;
    e.preventDefault();
    if (act === 'handover') { handOver(b); }
    else if (act === 'putaway') {
      IntakeConfirm.show({
        title: 'Put ' + (b.dataset.so || 'it') + ' away?',
        message: 'It\'s shop stock and already counted in stock. This closes the order; stock doesn\'t change.',
        confirmText: 'Put away', cancelText: 'Go back',
      }).then(function (ok) { if (ok) { IntakeSO.act(url('handover', id), {}).then(after); } });
    }
    else if (act === 'tell') { IntakeSO.notify([id], { reload: true }); }
    else if (act === 'hold') { IntakeSO.act(url('hold', id), {}).then(after); }
    else if (act === 'missing') {
      IntakeConfirm.show({
        title: 'Not on the shelf?',
        message: (b.dataset.so || 'This order') + ' goes back to Needed so it can be ordered again. Stock isn\'t changed; count it if the number is wrong.',
        confirmText: 'Back to Needed', cancelText: 'Go back', danger: true,
      }).then(function (ok) { if (ok) { IntakeSO.act(url('missing', id), {}).then(after); } });
    }
    else if (act === 'cancel') { IntakeSO.act(url('cancel', id), {}).then(after); }
  });
})();
</script>
@endonce
