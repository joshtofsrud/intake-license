{{-- MARKER-PATCH-225 — staff alerts bell. In-app alerts always render the
     bell (critical events reach even non-addon tenants); the feed itself
     is empty when nothing has been emitted. --}}
<div class="sa-bell" data-sa-bell>
  <button type="button" class="sa-bell-btn" data-sa-toggle aria-label="Notifications">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
    </svg>
    <span class="sa-bell-badge" data-sa-badge hidden>0</span>
  </button>
  <div class="sa-panel" data-sa-panel hidden>
    <div class="sa-panel-head">
      <span>Notifications</span>
      <button type="button" class="sa-mark-all" data-sa-mark-all>Mark all read</button>
    </div>
    <div class="sa-panel-list" data-sa-list>
      <div class="sa-empty">You're all caught up.</div>
    </div>
    <a href="{{ route('tenant.notifications') }}" class="sa-panel-foot">See all notifications</a>
  </div>
</div>

<style>
  .sa-bell { position:relative; }
  .sa-bell-btn { position:relative; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center;
                 background:transparent; border:none; cursor:pointer; color:var(--ia-text); opacity:.55;
                 transition:opacity .15s ease, background .15s ease; }
  .sa-bell-btn:hover { opacity:1; background:rgba(127,127,127,.09); }
  .sa-bell-btn:focus { outline:none; }
  .sa-bell-btn:focus-visible { outline:none; opacity:1; background:rgba(127,127,127,.12); }
  .sa-bell-badge { position:absolute; top:-4px; right:-4px; min-width:16px; height:16px; padding:0 4px; border-radius:999px;
                   background:#A32D2D; color:#fff; font-size:10px; font-weight:700; display:flex; align-items:center; justify-content:center; }
  .sa-panel { position:fixed; width:300px; z-index:9000; background:var(--ia-surface, #fff); border-radius:12px;
              box-shadow:0 8px 30px rgba(0,0,0,.18), inset 0 0 0 .5px var(--ia-border); overflow:hidden; }
  .sa-panel-head { display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-bottom:.5px solid var(--ia-border); font-size:12px; font-weight:600; }
  .sa-mark-all { background:none; border:none; cursor:pointer; font-size:11px; opacity:.6; color:inherit; }
  .sa-mark-all:hover { opacity:1; }
  .sa-panel-list { max-height:340px; overflow-y:auto; }
  .sa-item { display:block; padding:10px 14px; border-bottom:.5px solid var(--ia-border); text-decoration:none; color:inherit; }
  .sa-item:hover { background:rgba(127,127,127,.06); }
  .sa-item.unread { background:rgba(120,160,240,.07); }
  .sa-item.critical .sa-item-title { color:#A32D2D; }
  .sa-item-title { font-size:12.5px; font-weight:600; }
  .sa-item-body { font-size:11.5px; opacity:.6; margin-top:2px; }
  .sa-item-ago { font-size:10.5px; opacity:.4; margin-top:3px; }
  .sa-empty { padding:24px 14px; text-align:center; font-size:12px; opacity:.5; }
  .sa-panel-foot { display:block; padding:9px 14px; text-align:center; font-size:11.5px; border-top:.5px solid var(--ia-border); text-decoration:none; color:inherit; opacity:.7; }
  .sa-panel-foot:hover { opacity:1; }
</style>

<script>
(function () {
  var root = document.querySelector('[data-sa-bell]');
  if (!root || root.dataset.saInit) return;
  root.dataset.saInit = '1';

  var toggle = root.querySelector('[data-sa-toggle]');
  var panel  = root.querySelector('[data-sa-panel]');
  var badge  = root.querySelector('[data-sa-badge]');
  var list   = root.querySelector('[data-sa-list]');
  var feedUrl = '{{ route('tenant.alerts.feed') }}';
  var readAllUrl = '{{ route('tenant.alerts.read-all') }}';
  var readUrlTpl = '{{ route('tenant.alerts.read', ['id' => 'ID']) }}';
  var csrf = '{{ csrf_token() }}';

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  function render(data) {
    var n = data.unread || 0;
    if (n > 0) { badge.hidden = false; badge.textContent = n > 99 ? '99+' : n; }
    else { badge.hidden = true; }

    if (!data.alerts || !data.alerts.length) {
      list.innerHTML = '<div class="sa-empty">No notifications yet.</div>';
      return;
    }
    list.innerHTML = data.alerts.map(function (a) {
      var cls = 'sa-item' + (a.read ? '' : ' unread') + (a.is_critical ? ' critical' : '');
      var tag = a.link ? 'a' : 'div';
      var href = a.link ? ' href="' + esc(a.link) + '"' : '';
      return '<' + tag + ' class="' + cls + '"' + href + ' data-sa-id="' + a.id + '">' +
        '<div class="sa-item-title">' + esc(a.title) + '</div>' +
        (a.body ? '<div class="sa-item-body">' + esc(a.body) + '</div>' : '') +
        '<div class="sa-item-ago">' + esc(a.ago) + '</div>' +
        '</' + tag + '>';
    }).join('');
  }

  function load() {
    // MARKER-OFFLINE-SYNC-PIN — the 60s poll is not human activity; without
    // this flag it kept the idle PIN lock from ever engaging server-side.
    fetch(feedUrl, { headers: { 'Accept': 'application/json', 'X-Intake-Background': '1' } })
      .then(function (r) { return r.json(); }).then(render).catch(function () {});
  }

  // MARKER-PATCH-231F — portal the panel to <body> so position:fixed escapes
  // the sidebar's stacking/overflow trap, then place it next to the bell.
  function positionPanel() {
    var r = toggle.getBoundingClientRect();
    if (panel.parentNode !== document.body) document.body.appendChild(panel);
    panel.style.top  = (r.top) + 'px';
    panel.style.left = (r.right + 8) + 'px';
    // keep it on-screen if the viewport is narrow
    var pw = 300;
    if (r.right + 8 + pw > window.innerWidth) {
      panel.style.left = Math.max(8, window.innerWidth - pw - 8) + 'px';
    }
  }

  toggle.addEventListener('click', function (e) {
    e.stopPropagation();
    panel.hidden = !panel.hidden;
    if (!panel.hidden) { positionPanel(); load(); }
  });
  window.addEventListener('resize', function () { if (!panel.hidden) positionPanel(); });
  document.addEventListener('click', function (e) {
    if (!root.contains(e.target) && !panel.contains(e.target)) panel.hidden = true;
  });

  list.addEventListener('click', function (e) {
    var item = e.target.closest('[data-sa-id]');
    if (!item) return;
    var id = item.getAttribute('data-sa-id');
    // Mark read (fire and forget); navigation proceeds for anchors.
    fetch(readUrlTpl.replace('ID', id), { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } });
  });

  root.querySelector('[data-sa-mark-all]').addEventListener('click', function () {
    fetch(readAllUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
      .then(function () { load(); });
  });

  load();
  setInterval(function () { if (panel.hidden) load(); }, 60000);
})();
</script>
