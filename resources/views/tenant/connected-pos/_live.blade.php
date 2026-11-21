{{-- MARKER-CONNECTED-POS-SECTION: live numbers for whatever is running (catalog read, customers, import).
     [data-live="state.items_seen"] takes that value from the status reply; [data-live-bar="import"] is a
     progress bar. When something starts or finishes, the page reloads so its buttons match — never while a
     dialog is open or a setting is part-way changed. --}}
@php
  $lpc = $pos['conn'] ?? null;
  $lsig = $lpc ? implode('|', [
    (int) (($lpc->sync_state ?? [])['running'] ?? 0), (int) (($lpc->customer_sync ?? [])['running'] ?? 0),
    (int) (($lpc->import_state ?? [])['running'] ?? 0), $lpc->mode,
  ]) : '';
@endphp
@if($lpc)
<script>
(function(){
  var url = @json(route('tenant.cpos.status')), sig = @json($lsig);
  var busy = sig.split('|').slice(0, 3).indexOf('1') !== -1;
  var dirty = false;
  document.addEventListener('input', function(){ dirty = true; }, true);
  function open(){ return !!document.querySelector('.ia-confirm-backdrop'); }
  function get(o, path){ return path.split('.').reduce(function(a, k){ return a == null ? a : a[k]; }, o); }
  function tick(){
    if (document.hidden || open()) { setTimeout(tick, 2000); return; }
    fetch(url, {headers: {'Accept': 'application/json', 'X-Intake-Background': '1'}, credentials: 'same-origin'})
      .then(function(r){ return r.ok ? r.json() : null; })
      .then(function(j){
        if (!j) { return; }
        document.querySelectorAll('[data-live]').forEach(function(el){
          var v = get(j, el.dataset.live);
          if (typeof v === 'number') { el.textContent = v.toLocaleString(); }
          else if (typeof v === 'string') { el.textContent = v; }
        });
        document.querySelectorAll('[data-live-bar]').forEach(function(el){
          var s = j[el.dataset.liveBar];
          if (s && s.total) { el.style.width = Math.min(100, Math.round(100 * (s.done || 0) / s.total)) + '%'; }
        });
        var now = [(j.state && j.state.running) ? 1 : 0, (j.customers && j.customers.running) ? 1 : 0, (j.import && j.import.running) ? 1 : 0, j.mode || ''].join('|');
        if (now !== sig && !dirty && !open()) { location.reload(); return; }
        busy = now.split('|').slice(0, 3).indexOf('1') !== -1;
      })
      .catch(function(){})
      .finally(function(){ setTimeout(tick, busy ? 2500 : 20000); });
  }
  setTimeout(tick, busy ? 1500 : 20000);
})();
</script>
@endif
