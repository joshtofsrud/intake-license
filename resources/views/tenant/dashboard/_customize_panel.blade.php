{{-- MARKER-DASH-AUDIENCE: the Customize panel. What one person hides or
     reorders applies to them only, on this template only. Saved with Save;
     nothing changes until then. --}}
@if (! empty($customize))
@push('styles')
<style>
  .ia-dash-cust { white-space: nowrap; }
  @media (max-width: 1023px) { .ia-dash-cust { padding: 5px 8px !important; } .ia-dash-cust-lb { display: none; } }
  .dc-scrim { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 1000; }
  .dc-panel { position: fixed; top: 0; right: 0; bottom: 0; width: min(420px, 94vw); z-index: 1001; background: var(--ia-surface); color: var(--ia-text);
    border-left: 0.5px solid var(--ia-border); display: flex; flex-direction: column; box-shadow: -8px 0 24px rgba(0,0,0,.18); }
  .dc-head { padding: 18px 20px 12px; border-bottom: 0.5px solid var(--ia-border); }
  .dc-head h2 { font-size: 16px; font-weight: 600; margin: 0; }
  .dc-head p { font-size: 12.5px; color: var(--ia-text-muted); margin: 4px 0 0; line-height: 1.5; }
  .dc-body { flex: 1; overflow-y: auto; padding: 8px 20px 16px; }
  .dc-grp { font-size: 10.5px; letter-spacing: .1em; text-transform: uppercase; color: var(--ia-text-muted); font-weight: 600; padding: 16px 0 6px; }
  .dc-row { display: flex; align-items: center; gap: 8px; padding: 9px 0; border-bottom: 0.5px solid var(--ia-border); font-size: 14px; }
  .dc-row.off .dc-nm { color: var(--ia-text-dim); text-decoration: line-through; }
  .dc-nm { flex: 1; min-width: 0; }
  .dc-lock { font-size: 12px; color: var(--ia-text-muted); }
  .dc-row button { background: none; border: 0.5px solid var(--ia-border-strong); color: var(--ia-text); border-radius: 6px; font: inherit; font-size: 12px; padding: 3px 9px; cursor: pointer; }
  .dc-row button[disabled] { opacity: .3; cursor: default; }
  .dc-foot { padding: 14px 20px; border-top: 0.5px solid var(--ia-border); display: flex; gap: 8px; align-items: center; }
  .dc-foot .sp { margin-right: auto; }
  /* above the phone nav bar and the round + button */
  .dc-foot { padding-bottom: calc(14px + env(safe-area-inset-bottom, 0px)); }
</style>
@endpush

<div id="dc-wrap" hidden>
  <div class="dc-scrim" data-dc-close></div>
  <aside class="dc-panel" role="dialog" aria-modal="true" aria-labelledby="dc-title">
    <div class="dc-head">
      <h2 id="dc-title">Customize {{ $customize['name'] }}</h2>
      <p>Only for you. Other people in your shop keep their own view.
        {{ $customize['mode'] === 'both' ? 'Hide blocks you don\'t need, or change their order within a column.' : 'Hide the blocks you don\'t need.' }}
        Blocks marked “Always shown” are set by Intake.</p>
    </div>
    <div class="dc-body" id="dc-body">
      @foreach ($customize['groups'] as $g)
        <div class="dc-grp">{{ $g['label'] }}</div>
        <div data-dc-col="{{ $g['key'] }}">
          @foreach ($g['blocks'] as $b)
            <div class="dc-row {{ $b['hidden'] ? 'off' : '' }}" data-dc-block="{{ $b['key'] }}" data-dc-locked="{{ $b['locked'] ? 1 : 0 }}">
              <span class="dc-nm">{{ $b['name'] }}</span>
              @if ($customize['mode'] === 'both')
                <button type="button" data-dc-move="-1" aria-label="Move {{ $b['name'] }} up">↑</button>
                <button type="button" data-dc-move="1" aria-label="Move {{ $b['name'] }} down">↓</button>
              @endif
              @if ($b['locked'])
                <span class="dc-lock">Always shown</span>
              @else
                <button type="button" data-dc-toggle>{{ $b['hidden'] ? 'Show' : 'Hide' }}</button>
              @endif
            </div>
          @endforeach
        </div>
      @endforeach
    </div>
    <div class="dc-foot">
      <span class="sp"><button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-dc-reset>Reset to the template</button></span>
      <button type="button" class="ia-btn ia-btn--sm" data-dc-close>Cancel</button>
      <button type="button" class="ia-btn ia-btn--primary ia-btn--sm" data-dc-save>Save</button>
    </div>
  </aside>
</div>

@push('scripts')
<script>
(function () {
  var wrap = document.getElementById('dc-wrap');
  if (!wrap) return;
  var body = document.getElementById('dc-body');
  var saveUrl = @json(route('tenant.dashboard.customize'));
  var tpl = @json($customize['template']);
  var initial = body.innerHTML;

  function open() { body.innerHTML = initial; refresh(); wrap.hidden = false; document.body.style.overflow = 'hidden'; }
  function close() { wrap.hidden = true; document.body.style.overflow = ''; }
  function refresh() {
    body.querySelectorAll('[data-dc-col]').forEach(function (col) {
      var rows = col.querySelectorAll('[data-dc-block]');
      rows.forEach(function (row, i) {
        var up = row.querySelector('[data-dc-move="-1"]'), dn = row.querySelector('[data-dc-move="1"]');
        if (up) up.disabled = i === 0;
        if (dn) dn.disabled = i === rows.length - 1;
      });
    });
  }
  function fail(msg) {
    if (window.IntakeToast) { window.IntakeToast.error(msg); return; }
    var p = wrap.querySelector('.dc-head p'); if (p) p.textContent = msg;
  }
  function send(payload) {
    payload.template = tpl;
    return fetch(saveUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': (window.IntakeAdmin && window.IntakeAdmin.csrfToken) || '' },
      body: JSON.stringify(payload)
    }).then(function (r) {
      if (r.ok) { window.location.reload(); return; }
      fail('Could not save. Try again.');
    }).catch(function () { fail('Could not save. Check your connection.'); });
  }

  document.querySelectorAll('[data-dash-customize]').forEach(function (b) { b.addEventListener('click', open); });
  wrap.addEventListener('click', function (e) {
    if (e.target.closest('[data-dc-close]')) { close(); return; }
    var t = e.target.closest('[data-dc-toggle]');
    if (t) { var row = t.closest('[data-dc-block]'); var off = row.classList.toggle('off'); t.textContent = off ? 'Show' : 'Hide'; return; }
    var m = e.target.closest('[data-dc-move]');
    if (m) {
      var row = m.closest('[data-dc-block]'), dir = +m.getAttribute('data-dc-move');
      var sib = dir < 0 ? row.previousElementSibling : row.nextElementSibling;
      if (sib) { if (dir < 0) row.parentNode.insertBefore(row, sib); else row.parentNode.insertBefore(sib, row); refresh(); }
      return;
    }
    if (e.target.closest('[data-dc-save]')) {
      var hidden = [], order = {};
      body.querySelectorAll('[data-dc-col]').forEach(function (col) {
        var keys = [];
        col.querySelectorAll('[data-dc-block]').forEach(function (row) {
          keys.push(row.getAttribute('data-dc-block'));
          if (row.classList.contains('off')) hidden.push(row.getAttribute('data-dc-block'));
        });
        order[col.getAttribute('data-dc-col')] = keys;
      });
      e.target.disabled = true;
      send({ hidden: hidden, order: order });
      return;
    }
    if (e.target.closest('[data-dc-reset]')) { send({ reset: true }); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !wrap.hidden) close(); });
})();
</script>
@endpush
@endif
