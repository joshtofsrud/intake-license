{{-- MARKER-PATCH-231 — attention row: Search · Inbox · Alerts. Things that
     demand attention right now (each with a live count), not destinations. --}}
@php
  $inboxUnread = 0;
  if (tenant()->unified_inbox_enabled) {
      $inboxUnread = (int) \App\Models\Tenant\TenantThread::where('tenant_id', tenant()->id)
          ->where('status', '!=', 'closed')->sum('unread_count');
  }
@endphp
<div class="ar-row">
  {{-- search --}}
  <button type="button" class="ar-btn" data-ar-search aria-label="Search (Cmd/Ctrl-K)" title="Search ⌘K">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
  </button>

  {{-- inbox --}}
  @if(tenant()->unified_inbox_enabled)
    <a href="{{ route('tenant.inbox.index') }}" class="ar-btn" aria-label="Inbox" title="Inbox">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"><path d="M4 5h16v11H12l-3.5 3v-3H4z"/></svg>
      @if($inboxUnread > 0)<span class="ar-badge">{{ $inboxUnread > 99 ? '99+' : $inboxUnread }}</span>@endif
    </a>
  @endif

  {{-- alerts bell (existing dropdown, rebuilt) --}}
  @include('layouts.tenant._staff-alerts-bell')
</div>

{{-- search modal --}}
<div class="ar-modal" data-ar-modal hidden>
  <div class="ar-modal-card" role="dialog" aria-modal="true" aria-label="Search">
    <div class="ar-modal-search">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" style="opacity:.5"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" data-ar-input placeholder="Search customers, sales, appointments, rentals…" autocomplete="off">
      <kbd class="ar-esc">esc</kbd>
    </div>
    <div class="ar-modal-results" data-ar-results>
      <div class="ar-hint">Type at least 2 characters.</div>
    </div>
  </div>
</div>

<style>
  .ar-row{display:flex;align-items:center;gap:4px;margin-bottom:10px}
  .ar-btn{position:relative;width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;background:transparent;border:none;cursor:pointer;color:var(--ia-text);box-shadow:inset 0 0 0 .5px var(--ia-border);text-decoration:none}
  .ar-btn:hover{background:rgba(127,127,127,.08)}
  .ar-badge{position:absolute;top:-4px;right:-4px;min-width:16px;height:16px;padding:0 4px;border-radius:999px;background:#5BA3D0;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center}
  .ar-modal{position:fixed;inset:0;z-index:200;background:rgba(0,0,0,.45);display:flex;align-items:flex-start;justify-content:center;padding-top:12vh}
  .ar-modal-card{width:92%;max-width:560px;background:var(--ia-surface,#1c1c1c);border-radius:14px;box-shadow:0 24px 70px rgba(0,0,0,.45),inset 0 0 0 .5px var(--ia-border);overflow:hidden}
  .ar-modal-search{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:.5px solid var(--ia-border)}
  .ar-modal-search input{flex:1;background:transparent;border:none;outline:none;color:var(--ia-text);font:inherit;font-size:15px}
  .ar-esc{font-size:10px;opacity:.4;border:.5px solid var(--ia-border);border-radius:4px;padding:1px 5px}
  .ar-modal-results{max-height:54vh;overflow-y:auto;padding:6px}
  .ar-hint,.ar-empty{padding:22px 14px;text-align:center;font-size:12.5px;opacity:.5}
  .ar-grp-label{font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;opacity:.5;padding:10px 12px 4px}
  .ar-item{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;text-decoration:none;color:inherit;cursor:pointer}
  .ar-item:hover,.ar-item.ar-sel{background:rgba(127,127,127,.10)}
  .ar-item-title{font-size:13.5px;font-weight:550}
  .ar-item-sub{font-size:11.5px;opacity:.5}
</style>

<script>
(function(){
  var modal = document.querySelector('[data-ar-modal]');
  if (!modal || modal.dataset.arInit) return;
  modal.dataset.arInit = '1';
  // MARKER-PATCH-231C — move the modal to <body> (escape sidebar stacking)
  // and hide it via inline display, because the modal's display:flex rule
  // overrides the [hidden] attribute. DOM/behavior changes, not stylesheet CSS.
  if (modal.parentNode !== document.body) { document.body.appendChild(modal); }
  modal.style.display = 'none';
  // MARKER-PATCH-231C — move the modal out of the sidebar subtree to <body>,
  // so position:fixed escapes the sidebar's stacking/transform context and
  // the modal paints above the dashboard tiles. DOM move, not a style change.
  if (modal.parentNode !== document.body) { document.body.appendChild(modal); }
  var input = modal.querySelector('[data-ar-input]');
  var results = modal.querySelector('[data-ar-results]');
  var searchUrl = '{{ route('tenant.search') }}';
  var t, lastReq = 0;

  function isOpen(){ return modal.style.display !== 'none'; }
  function open(){ modal.style.display = 'flex'; setTimeout(function(){ input.focus(); }, 30); }
  function close(){ modal.style.display = 'none'; input.value=''; results.innerHTML='<div class="ar-hint">Type at least 2 characters.</div>'; }

  document.querySelectorAll('[data-ar-search]').forEach(function(b){ b.addEventListener('click', open); });
  document.addEventListener('keydown', function(e){
    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); isOpen() ? close() : open(); }
    if (e.key === 'Escape' && isOpen()) close();
  });
  modal.addEventListener('click', function(e){ if (e.target === modal) close(); });

  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }

  function render(groups){
    if (!groups || !groups.length){ results.innerHTML='<div class="ar-empty">No matches.</div>'; return; }
    results.innerHTML = groups.map(function(g){
      return '<div class="ar-grp-label">'+esc(g.label)+'</div>' + g.rows.map(function(r){
        return '<a class="ar-item" href="'+esc(r.url)+'">'+
          '<span class="ar-item-title">'+esc(r.title)+'</span>'+
          (r.subtitle ? '<span class="ar-item-sub">'+esc(r.subtitle)+'</span>' : '')+'</a>';
      }).join('');
    }).join('');
  }

  input.addEventListener('input', function(){
    clearTimeout(t);
    var q = input.value.trim();
    if (q.length < 2){ results.innerHTML='<div class="ar-hint">Type at least 2 characters.</div>'; return; }
    var myReq = ++lastReq;
    t = setTimeout(function(){
      fetch(searchUrl + '?q=' + encodeURIComponent(q), { headers:{'Accept':'application/json'} })
        .then(function(r){ return r.json(); })
        .then(function(d){ if (myReq === lastReq) render(d.groups); })
        .catch(function(){});
    }, 200);
  });
})();
</script>
