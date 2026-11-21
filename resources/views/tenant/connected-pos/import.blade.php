@extends('layouts.tenant.app')
@php
  $pageTitle = 'Import from Connected POS';
  $distLabel = fn ($c) => app(\App\Services\Distributors\DistributorRegistry::class)->label((string) $c);
  $st = $state ?? [];
  $running = (bool) ($st['running'] ?? false);
  $stalled = $running && $conn && $conn->updated_at && $conn->updated_at->lt(now()->subMinutes(15));
  $stopped = ! $running && ! empty($st['stopped']);
  $pct = ($st['total'] ?? 0) ? min(100, (int) round(100 * ($st['done'] ?? 0) / $st['total'])) : 0;
  $todo = $plan ? $plan['new'] + $plan['merge'] : 0;
  $planJs = $plan ?: ['new' => 0, 'merge' => 0, 'check' => 0, 'linked' => 0, 'twin' => 0]; // built here: @json can't take a nested array inline
@endphp

@push('styles')
<link rel="stylesheet" href="{{ asset('css/tenant/connected-pos.css') }}?v={{ filemtime(public_path('css/tenant/connected-pos.css')) }}">
@endpush

@section('content')
{{-- MARKER-CONNECTED-POS-SECTION: Inventory › Import with the shop's Connected POS as the source. --}}
<div class="ia-section">
  <h1 style="font-size:20px;font-weight:600;margin-bottom:14px">Import from Connected POS</h1>

  <div style="display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap">
    <span style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600">Source</span>
    @foreach ($distCodes as $c)
      <a href="{{ route('tenant.distributors.import', ['code' => $c]) }}"
         style="padding:5px 13px;border-radius:20px;font-size:12.5px;font-weight:600;text-decoration:none;border:1px solid var(--ia-border);background:var(--ia-surface-2);color:var(--ia-text-dim)">{{ $distLabel($c) }}</a>
    @endforeach
    <span style="padding:5px 13px;border-radius:20px;font-size:12.5px;font-weight:600;border:1px solid var(--ia-text);background:var(--ia-text);color:var(--ia-bg)">Connected POS</span>
  </div>
  @include('layouts.tenant._inventory-tabs')

  @if(session('success'))<div class="im-banner im-ok">{{ session('success') }}</div>@endif
  @if(session('error'))<div class="im-banner im-err">{{ session('error') }}</div>@endif
  @if(! empty($expired))<div class="im-banner im-err">That preview has expired. Choose your filters and press Preview again.</div>@endif

  <details class="cp-legend" style="max-width:none">
    <summary>What importing from your Connected POS does</summary>
    <ul>
      <li><b>Nothing comes in unless you add it here.</b> Reading your Connected POS only fills this list.</li>
      <li>A product that is plainly an item you already carry (same barcode, or same part number) is <b>linked</b> to that item, never duplicated. Only its blank fields are filled.</li>
      <li>A product that only shares a <b>SKU</b> with one of your items is a suggestion. It is linked when you press Link on its row or tick it; "import everything" leaves those for you to check.</li>
      <li>Anything else becomes a <b>new item</b>, off the online store, with your Connected POS's name, identifiers, brand, supplier, cost, price and stock. It takes your category of the same name when you have one; otherwise it lands in Uncategorized.</li>
      <li>@if($conn && $conn->isTwoWay()) <b>2 Way Data:</b> once linked, an item takes your Connected POS's price and stock at each mapped location, and keeps following them. @else <b>Reading Only:</b> a new item starts with the last price and count read from your Connected POS; after that, Intake owns them. An item you already had keeps its own. @endif</li>
      <li>A product your Connected POS has <b>more than once</b> (same barcode, or same part number and brand) is never decided for you, because each copy can hold its own stock. You pick which copy your item follows, or make a copy an item of its own. "Import everything" leaves those alone.</li>
      <li>Every run lands on one batch you can <b>undo</b>, below and in catalog history. Nothing is ever changed in your Connected POS by importing.</li>
    </ul>
  </details>

  @if(! $conn)
    <div class="im-card">
      <h2 class="im-h">No Connected POS yet</h2>
      <p class="im-sub">Connect the POS your shop uses, read its catalog, then choose here what to bring in.</p>
      <a class="im-btn primary" href="{{ route('tenant.cpos.home') }}" style="text-decoration:none">Open Connected POS</a>
    </div>
  @elseif($counts['total'] === 0)
    <div class="im-card">
      <h2 class="im-h">Your catalog hasn't been read yet</h2>
      <p class="im-sub">Reading records every product in your Connected POS and links the ones you already carry. It never creates an item. When it finishes, everything else is listed here.</p>
      <a class="im-btn primary" href="{{ route('tenant.cpos.home', ['tab' => 'items']) }}" style="text-decoration:none">Read the catalog</a>
    </div>
  @else

    {{-- the run: live, with Stop and Resume --}}
    @if($st && ($running || $stopped || ! empty($st['error']) || ! empty($st['finished_at'])))
      <div class="im-card" id="ciRun" data-status="{{ route('tenant.inventory.cpos.status') }}" data-running="{{ $running && ! $stalled ? 1 : 0 }}">
        <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap">
          <div style="flex:1;min-width:260px">
            <h2 class="im-h">
              @if($stalled) This import stopped responding at {{ number_format($st['done'] ?? 0) }} of {{ number_format($st['total'] ?? 0) }}
              @elseif($running) Importing from Connected POS · <span data-k="done">{{ number_format($st['done'] ?? 0) }}</span> of {{ number_format($st['total'] ?? 0) }}
              @elseif($stopped) Stopped at {{ number_format($st['done'] ?? 0) }} of {{ number_format($st['total'] ?? 0) }}
              @elseif(! empty($st['error'])) The import stopped with an error at {{ number_format($st['done'] ?? 0) }} of {{ number_format($st['total'] ?? 0) }}
              @else Last import finished {{ \Illuminate\Support\Carbon::parse($st['finished_at'])->diffForHumans() }} @endif
            </h2>
            @if($running || $stopped || ! empty($st['error']))<div class="cpx-prog"><i data-bar style="width:{{ $pct }}%"></i></div>@endif
            @if(! empty($st['error']))<p class="im-sub" style="color:#f0a3a3;margin-bottom:8px">{{ $st['error'] }}</p>@endif
          </div>
          <div style="display:flex;gap:8px;align-items:center">
            @if($running && ! $stalled)
              <form method="POST" action="{{ route('tenant.inventory.cpos.stop') }}">@csrf<button class="im-btn" type="submit">Stop</button></form>
            @elseif($stopped || $stalled || ! empty($st['error']))
              <form method="POST" action="{{ route('tenant.inventory.cpos.resume') }}">@csrf<button class="im-btn primary" type="submit">Resume from {{ number_format($st['done'] ?? 0) }}</button></form>
            @endif
          </div>
        </div>
        <div class="av-sum" style="margin:10px 0 6px">
          <div>New items<b data-k="created">{{ number_format($st['created'] ?? 0) }}</b></div>
          <div>Linked to yours<b data-k="linked">{{ number_format($st['linked'] ?? 0) }}</b></div>
          <div>In Uncategorized<b data-k="uncategorized">{{ number_format($st['uncategorized'] ?? 0) }}</b></div>
          <div class="w">Left for you to check<b data-k="check">{{ number_format($st['check'] ?? 0) }}</b></div>
          <div class="w">There more than once<b data-k="twins">{{ number_format($st['twins'] ?? 0) }}</b></div>
          <div class="w">Couldn't bring in<b data-k="errors">{{ number_format($st['errors'] ?? 0) }}</b></div>
        </div>
        <p class="im-sub" style="margin:0">
          @if($running && ! $stalled) Stop keeps everything already in; Resume carries on from the same product.
          @elseif($stopped || $stalled) What is in stays in. Resume carries on from product {{ number_format(($st['done'] ?? 0) + 1) }}.
          @else
            @if(($st['uncategorized'] ?? 0) > 0)<a class="cpx-lnk" href="{{ route('tenant.inventory.uncategorized') }}">Sort the {{ number_format($st['uncategorized']) }} in Uncategorized →</a> · @endif
            @if(($st['check'] ?? 0) > 0) {{ number_format($st['check']) }} share only a SKU with one of your items: preview, open <b>Links to yours</b>, and press Link on the ones that are right. · @endif
            @if(($st['twins'] ?? 0) > 0) {{ number_format($st['twins']) }} are in your Connected POS more than once and were left alone: preview, open <b>There more than once</b>, and choose for each. · @endif
            @if(($st['errors'] ?? 0) > 0) {{ number_format($st['errors']) }} couldn't be brought in and have been reported. · @endif
            It can be undone under Recent imports below.
          @endif
        </p>
      </div>
    @endif

    <div class="im-card">
      <h2 class="im-h">Import items</h2>
      <p class="im-sub">Pick what to bring in, preview what Import would do with each product, then import one, the ones you tick, or all of them.
        <b>{{ number_format($counts['waiting']) }}</b> {{ $counts['waiting'] === 1 ? 'product is' : 'products are' }} waiting{{ $counts['new'] ? ', ' . number_format($counts['new']) . ' of them new since your last import' : '' }}. {{ number_format($counts['linked']) }} are already linked to your items.</p>

      <form method="POST" action="{{ route('tenant.inventory.cpos.preview') }}">
        @csrf
        <div class="im-row">
          <div class="im-field"><label>Brand</label>
            <x-tenant.searchable-select name="brand" :options="$options['brands']" :selected="old('brand', $filters['brand'] ?? '')" any="Any brand" noun="brands" />
          </div>
          <div class="im-field"><label>Category (as your Connected POS has it)</label>
            <x-tenant.searchable-select name="category" :options="$options['categories']" :selected="old('category', $filters['category'] ?? '')" any="Any category" noun="categories" />
          </div>
        </div>
        <label class="im-check"><input type="checkbox" name="in_stock" value="1" @checked(old('in_stock', ! empty($filters['in_stock'])))> Only products with stock in your Connected POS</label>
        <label class="im-check"><input type="checkbox" name="new_only" value="1" @checked(old('new_only', ! empty($filters['new_only'])))> Only new since my last import ({{ number_format($counts['new']) }})</label>
        <label class="im-check"><input type="checkbox" name="include_removed" value="1" @checked(old('include_removed', ! empty($filters['include_removed'])))> Include products removed from your Connected POS ({{ number_format($counts['removed']) }})</label>
        <div style="margin-top:16px;display:flex;gap:10px"><button class="im-btn primary" type="submit">Preview</button></div>
        <p class="im-sub" style="margin:10px 0 0">Leave everything as it is to preview the whole list. Preview changes nothing.</p>
      </form>
    </div>

    @if($planKey)
      <div class="im-card" id="ipList" data-key="{{ $planKey }}">
        <h2 class="im-h">Preview</h2>
        <div class="im-stats">
          <div class="im-stat"><div class="v">{{ number_format($plan['new']) }}</div><div class="k">New items</div></div>
          <div class="im-stat"><div class="v">{{ number_format($plan['merge']) }}</div><div class="k">Link to yours</div>@if($plan['check'])<div class="s">{{ number_format($plan['check']) }} by SKU only: yours to check</div>@endif</div>
          <div class="im-stat"><div class="v">{{ number_format($plan['linked']) }}</div><div class="k">Already linked</div></div>
          @if($plan['twin'])<div class="im-stat"><div class="v">{{ number_format($plan['twin']) }}</div><div class="k">There more than once</div><div class="s">yours to choose, one at a time</div></div>@endif
        </div>

        <div class="ip-bar">
          <div class="ip-tabs" role="tablist">
            <button type="button" class="on" data-tab="todo">To bring in {{ number_format($todo + $plan['twin']) }}</button>
            <button type="button" data-tab="new">New {{ number_format($plan['new']) }}</button>
            <button type="button" data-tab="merge">Links to yours {{ number_format($plan['merge']) }}</button>
            @if($plan['twin'])<button type="button" data-tab="twin">There more than once {{ number_format($plan['twin']) }}</button>@endif
            <button type="button" data-tab="linked">Already linked {{ number_format($plan['linked']) }}</button>
          </div>
          <input type="search" class="im-input" style="flex:1;min-width:220px;width:auto" data-ip-q placeholder="Search name, SKU, part number or barcode">
        </div>
        <table class="ip-tbl">
          <thead><tr><th class="ck"><input type="checkbox" data-ip-all title="Tick every row on this page"></th><th>Connected POS item</th><th>What Import does</th><th class="r">Price</th><th class="r">Stock there</th><th></th></tr></thead>
          <tbody data-ip-body><tr><td colspan="6" class="ip-empty">Loading…</td></tr></tbody>
        </table>
        <div class="ip-pager"><span data-ip-count></span><button type="button" class="im-btn" data-ip-prev>‹ Prev</button><button type="button" class="im-btn" data-ip-next>Next ›</button></div>

        <form method="POST" action="{{ route('tenant.inventory.cpos.run') }}" id="ipRun" style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          @csrf
          <input type="hidden" name="key" value="{{ $planKey }}">
          <span id="ipIds"></span>
          @if($running && ! $stalled)
            <span class="im-sub" style="margin:0">An import is running. Start another when it finishes.</span>
          @else
            <button type="button" class="im-btn primary" data-ip-run-all @disabled($todo - $plan['check'] <= 0)>Import all {{ number_format(max(0, $todo - $plan['check'])) }}</button>
            <button type="button" class="im-btn" data-ip-run-ticked disabled>Import ticked (0)</button>
            <span class="im-sub" style="margin:0">@if($plan['check']) "Import all" leaves the {{ number_format($plan['check']) }} SKU-only matches for you to check. @endif @if($plan['twin']) It also leaves the {{ number_format($plan['twin']) }} your Connected POS has more than once. @endif It runs in the background and shows its progress at the top of this page.</span>
          @endif
        </form>
      </div>
    @endif

    {{-- recent imports, and the way back --}}
    @if($batches->count())
      <div class="im-card">
        <h2 class="im-h">Recent imports</h2>
        <p class="im-sub">Undo removes the links an import made, and the items it made along with the stock your Connected POS gave them. Anything sold or adjusted since is kept. Nothing changes in your Connected POS. Kept for 90 days.</p>
        <table class="ip-tbl">
          <tbody>
            @foreach($batches as $b)
              @php $live = $running && ($st['batch_id'] ?? null) === $b->id; @endphp
              <tr>
                <td><div>{{ number_format($b->item_count) }} {{ \Illuminate\Support\Str::plural('item', $b->item_count) }} · {{ ! empty($b->filter['picked']) ? 'picked one at a time' : (! empty($b->filter['ids']) ? 'ticked rows' : 'everything matching the filters') }}</div>
                  <div class="m">{{ $b->created_at->diffForHumans() }}@if($b->run_by) · {{ $b->run_by }}@endif</div></td>
                <td class="ip-out">
                  @if($b->isUndone()) Undone: {{ number_format($b->restored_count) }} removed{{ $b->kept_count ? ', ' . number_format($b->kept_count) . " kept because they've been sold or adjusted since" : '' }}
                  @elseif($live) Running now
                  @else Applied @endif
                </td>
                <td class="r" style="white-space:nowrap">
                  <a class="im-btn" href="{{ route('tenant.distributors.attention.history.show', $b->id) }}" style="text-decoration:none">See the items</a>
                  @if($b->isReversible() && ! $live)
                    <form method="POST" action="{{ route('tenant.distributors.attention.history.undo', $b->id) }}" style="display:inline">@csrf
                      <button class="im-btn" type="submit" data-confirm="Undo this import of {{ number_format($b->item_count) }} {{ \Illuminate\Support\Str::plural('item', $b->item_count) }}? Links to items you already had are removed. Items it made are removed too, with the stock your Connected POS gave them. Anything sold or adjusted since is kept, and you'll be told how many. Nothing changes in your Connected POS.">Undo</button>
                    </form>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
        <div class="ip-pager"><a class="cpx-lnk" href="{{ route('tenant.distributors.attention.history') }}">All catalog changes →</a></div>
      </div>
    @endif
  @endif
</div>

{{-- live progress for a run --}}
<script>
(function () {
  var box = document.getElementById('ciRun');
  if (!box || box.dataset.running !== '1') { return; }
  var bar = box.querySelector('[data-bar]');
  function tick() {
    if (document.hidden) { setTimeout(tick, 2000); return; }
    fetch(box.dataset.status, { headers: { 'Accept': 'application/json', 'X-Intake-Background': '1' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        var s = j && j.import; if (!s) { return; }
        box.querySelectorAll('[data-k]').forEach(function (b) { b.textContent = (s[b.dataset.k] || 0).toLocaleString(); });
        if (bar && s.total) { bar.style.width = Math.min(100, Math.round(100 * (s.done || 0) / s.total)) + '%'; }
        if (!s.running && !document.querySelector('.ia-confirm-backdrop')) { location.reload(); return; }
        setTimeout(tick, 2000);
      })
      .catch(function () { setTimeout(tick, 4000); });
  }
  setTimeout(tick, 1200);
})();
</script>

{{-- the preview list: paging, search, tabs, ticking rows, and importing one --}}
<script>
(function () {
  var box = document.getElementById('ipList');
  if (!box) { return; }
  var planUrl = @json(route('tenant.inventory.cpos.plan'));
  var oneUrl  = @json(route('tenant.inventory.cpos.one'));
  var csrf    = @json(csrf_token());
  var P = @json($planJs);
  var st = { tab: 'todo', q: '', page: 1, pages: 1 };
  var ticked = {};
  var body = box.querySelector('[data-ip-body]'), count = box.querySelector('[data-ip-count]');
  var prev = box.querySelector('[data-ip-prev]'), next = box.querySelector('[data-ip-next]');
  var all = box.querySelector('[data-ip-all]'), runAll = box.querySelector('[data-ip-run-all]'), runTicked = box.querySelector('[data-ip-run-ticked]');
  var form = document.getElementById('ipRun'), idsBox = document.getElementById('ipIds');
  var esc = function (v) { var d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; };
  var money = function (c) { return c == null ? '—' : '$' + (c / 100).toFixed(2); };
  var how = { barcode: 'by barcode', part: 'by part number', sku: 'by SKU · check' };
  var can = function (r) { return r.action === 'new' || r.action === 'merge'; };

  function outcome(r) {
    if (r.action === 'linked') {
      return 'Already linked' + (r.item_url ? ' · <a href="' + r.item_url + '">' + esc(r.item_name || 'open item') + '</a>' : '');
    }
    if (r.action === 'twin') {
      /* MARKER-POS-TWINS: every copy, with its stock, so the choice is an informed one */
      var copies = (r.twins || []).map(function (t) {
        return '<div class="m" style="margin-top:2px">' + esc(t.name) + (t.sku ? ' · SKU ' + esc(t.sku) : '') + ' · ' + (t.stock == null ? 'non-stock' : 'stock ' + t.stock.toLocaleString())
          + (t.linked ? ' · <span style="color:var(--ia-accent)">your item follows this one now</span>' : ' · waiting') + '</div>';
      }).join('');
      var about = r.item
        ? (r.kind === 'held' ? 'Your item <a href="' + r.item_url + '">' + esc(r.item_name || 'open item') + '</a> follows another copy now.'
                             : 'Your item <a href="' + r.item_url + '">' + esc(r.item_name || 'open item') + '</a> isn\'t following any copy yet.')
        : 'No copy is in Intake yet.';
      return 'In your Connected POS ' + ((r.twins || []).length + 1) + ' times<span class="ip-tag part">you choose</span><div style="margin-top:4px">' + about + '</div>'
        + '<div class="m" style="margin-top:6px;font-family:inherit">The other ' + ((r.twins || []).length === 1 ? 'copy' : 'copies') + ':</div>' + copies;
    }
    if (r.action === 'merge') {
      return 'Links to <a href="' + r.item_url + '">' + esc(r.item_name || 'your item') + '</a>'
        + '<span class="ip-tag' + (r.kind === 'sku' ? ' part' : '') + '">' + esc(how[r.kind] || '') + '</span>';
    }
    var cat = r.cat_to ? ' · <span style="color:var(--ia-text-dim)">category: ' + esc(r.cat_to) + '</span>'
      : ' · <span style="color:#fbbf24">' + (r.category ? 'you have no category called “' + esc(r.category) + '”: lands in Uncategorized' : 'no category there: lands in Uncategorized') + '</span>';
    return 'New item' + cat + (r.stock == null ? '<span class="ip-tag">non-stock</span>' : '');
  }
  function button(r) {
    if (r.action === 'linked') { return r.item_url ? '<a class="im-btn" href="' + r.item_url + '">Open</a>' : ''; }
    if (r.action === 'twin') {
      return '<button type="button" class="im-btn" data-ip-twin="follow" data-id="' + r.id + '" data-item="' + esc(r.item_name || '') + '" data-held="' + (r.kind === 'held' ? 1 : 0) + '">Use this one</button> '
        + '<button type="button" class="im-btn" data-ip-twin="own" data-id="' + r.id + '">Own item</button>';
    }
    if (!can(r)) { return ''; }
    return '<button type="button" class="im-btn" data-ip-one="' + r.id + '">' + (r.action === 'new' ? 'Import' : 'Link') + '</button>';
  }
  function syncTicked() {
    var ids = Object.keys(ticked);
    if (runTicked) {
      runTicked.disabled = !ids.length;
      runTicked.textContent = 'Import ticked (' + ids.length.toLocaleString() + ')';
    }
    var boxes = body.querySelectorAll('[data-ip-tick]');
    if (all) { all.checked = boxes.length > 0 && Array.prototype.every.call(boxes, function (b) { return b.checked; }); }
  }
  function load() {
    body.innerHTML = '<tr><td colspan="6" class="ip-empty">Loading…</td></tr>';
    var u = planUrl + '?key=' + encodeURIComponent(box.dataset.key) + '&tab=' + st.tab + '&q=' + encodeURIComponent(st.q) + '&page=' + st.page;
    fetch(u, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { body.innerHTML = '<tr><td colspan="6" class="ip-empty">' + esc(d.error || 'Could not load the list.') + '</td></tr>'; return; }
        st.page = d.page; st.pages = d.pages;
        count.textContent = d.total.toLocaleString() + ' product' + (d.total === 1 ? '' : 's') + ' · page ' + d.page + ' of ' + d.pages;
        prev.disabled = d.page <= 1; next.disabled = d.page >= d.pages;
        if (!d.rows.length) {
          body.innerHTML = '<tr><td colspan="6" class="ip-empty">' + (st.q
            ? 'Nothing in this preview matches “' + esc(st.q) + '”. Try the part number without dashes, or widen the filters above and preview again.'
            : (st.tab === 'todo' ? 'Nothing left to bring in from this preview: everything it matched is already linked.' : 'Nothing in this group.')) + '</td></tr>';
          syncTicked();
          return;
        }
        body.innerHTML = d.rows.map(function (r) {
          var ids = [r.brand, r.part ? 'SKU ' + r.part : '', r.mpn ? 'MPN ' + r.mpn : '', r.code].filter(Boolean).map(esc).join(' · ');
          return '<tr><td class="ck">' + (can(r) ? '<input type="checkbox" data-ip-tick="' + r.id + '"' + (ticked[r.id] ? ' checked' : '') + '>' : '') + '</td>'
            + '<td><div>' + esc(r.name) + (r.is_new ? '<span class="cpx-new">new</span>' : '') + (r.removed ? '<span class="ip-tag part">removed there</span>' : '') + '</div><div class="m">' + ids + '</div></td>'
            + '<td class="ip-out" data-ip-out>' + outcome(r) + '</td><td class="r">' + money(r.price) + '</td>'
            + '<td class="r">' + (r.stock == null ? '—' : r.stock.toLocaleString()) + '</td><td class="r" data-ip-btn>' + button(r) + '</td></tr>';
        }).join('');
        syncTicked();
      })
      .catch(function () { body.innerHTML = '<tr><td colspan="6" class="ip-empty">Could not load the list. Try again.</td></tr>'; });
  }

  box.querySelectorAll('.ip-tabs button').forEach(function (b) {
    b.addEventListener('click', function () {
      box.querySelectorAll('.ip-tabs button').forEach(function (x) { x.classList.toggle('on', x === b); });
      st.tab = b.dataset.tab; st.page = 1; load();
    });
  });
  var t = null;
  box.querySelector('[data-ip-q]').addEventListener('input', function (e) {
    clearTimeout(t); t = setTimeout(function () { st.q = e.target.value.trim(); st.page = 1; load(); }, 250);
  });
  prev.addEventListener('click', function () { if (st.page > 1) { st.page--; load(); } });
  next.addEventListener('click', function () { if (st.page < st.pages) { st.page++; load(); } });

  body.addEventListener('change', function (e) {
    var c = e.target.closest('[data-ip-tick]'); if (!c) { return; }
    if (c.checked) { ticked[c.dataset.ipTick] = true; } else { delete ticked[c.dataset.ipTick]; }
    syncTicked();
  });
  if (all) {
    all.addEventListener('change', function () {
      body.querySelectorAll('[data-ip-tick]').forEach(function (c) {
        c.checked = all.checked;
        if (all.checked) { ticked[c.dataset.ipTick] = true; } else { delete ticked[c.dataset.ipTick]; }
      });
      syncTicked();
    });
  }

  body.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ip-one]'); if (!b) { return; }
    var tr = b.closest('tr'), out = tr.querySelector('[data-ip-out]'), was = b.textContent;
    b.disabled = true; b.textContent = 'Working…';
    fetch(oneUrl, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify({ id: parseInt(b.dataset.ipOne, 10), key: box.dataset.key }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok || !res.j.ok) { throw new Error((res.j && res.j.error) || 'That product could not be brought in.'); }
        out.textContent = res.j.did === 'created' ? 'Imported as a new item' : (res.j.did === 'already' ? 'Already linked' : 'Linked');
        tr.querySelector('[data-ip-btn]').innerHTML = res.j.url ? '<a class="im-btn" href="' + res.j.url + '">Open</a>' : '';
        var c = tr.querySelector('[data-ip-tick]'); if (c) { delete ticked[c.dataset.ipTick]; c.remove(); syncTicked(); }
      })
      .catch(function (err) {
        b.disabled = false; b.textContent = was;
        out.innerHTML = ''; var s = document.createElement('span'); s.style.color = '#f0a3a3'; s.textContent = err.message; out.appendChild(s);
      });
  });

  /* MARKER-POS-TWINS: one copy of a product the Connected POS has more than once */
  body.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ip-twin]'); if (!b) { return; }
    var as = b.dataset.ipTwin, tr = b.closest('tr'), out = tr.querySelector('[data-ip-out]');
    var twoWay = @json((bool) ($conn?->isTwoWay() ?? true));
    var msg = as === 'own'
      ? 'This copy becomes a new item of its own, with its own price and stock. The other ' + 'copies are left as they are. Both items will carry the same barcode, so they may show under Duplicates.'
      : (b.dataset.held === '1'
          ? '“' + b.dataset.item + '” stops following the copy it follows now and follows this one instead.' + (twoWay ? ' Its price and stock become this copy\'s.' : '') + ' The other copy goes back to waiting; its stock is no longer counted.'
          : (b.dataset.item ? '“' + b.dataset.item + '” follows this copy.' + (twoWay ? ' Its price and stock become this copy\'s.' : '') : 'A new item is made from this copy.') + ' The other copies keep waiting; their stock is not counted.');
    IntakeConfirm.show({ title: as === 'own' ? 'Make this copy its own item?' : 'Have your item follow this copy?', message: msg + ' You can undo it under Recent imports.', confirmText: as === 'own' ? 'Make the item' : 'Use this one' })
      .then(function (ok) {
        if (!ok) { return; }
        tr.querySelectorAll('[data-ip-twin]').forEach(function (x) { x.disabled = true; });
        fetch(oneUrl, { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify({ id: parseInt(b.dataset.id, 10), key: box.dataset.key, as: as }) })
          .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (!res.ok || !res.j.ok) { throw new Error((res.j && res.j.error) || 'That could not be done.'); }
            location.reload(); // the other copies' rows, and the counts, all change
          })
          .catch(function (err) {
            tr.querySelectorAll('[data-ip-twin]').forEach(function (x) { x.disabled = false; });
            var sp = document.createElement('div'); sp.style.color = '#f0a3a3'; sp.style.marginTop = '6px'; sp.textContent = err.message; out.appendChild(sp);
          });
      });
  });

  function submit(ids) {
    idsBox.innerHTML = '';
    ids.forEach(function (id) { var i = document.createElement('input'); i.type = 'hidden'; i.name = 'ids[]'; i.value = id; idsBox.appendChild(i); });
    form.submit();
  }
  if (runAll) {
    runAll.addEventListener('click', function () {
      IntakeConfirm.show({
        title: 'Import everything in this preview?',
        message: P.new.toLocaleString() + ' new ' + (P.new === 1 ? 'item is' : 'items are') + ' made and ' + Math.max(0, P.merge - P.check).toLocaleString() + ' ' + (P.merge - P.check === 1 ? 'product is' : 'products are') + ' linked to items you already have.'
          + (P.check ? ' The ' + P.check.toLocaleString() + ' SKU-only ' + (P.check === 1 ? 'match is' : 'matches are') + ' left for you to check.' : '')
          + ' You can stop it part-way, and undo it afterwards. Nothing changes in your Connected POS.',
        confirmText: 'Import'
      }).then(function (ok) { if (ok) { submit([]); } });
    });
  }
  if (runTicked) {
    runTicked.addEventListener('click', function () {
      var ids = Object.keys(ticked);
      if (!ids.length) { return; }
      IntakeConfirm.show({
        title: 'Import the ' + ids.length.toLocaleString() + ' ticked ' + (ids.length === 1 ? 'product' : 'products') + '?',
        message: 'New ones become items; the rest are linked to the items shown, SKU-only matches included, because you picked them. You can undo it afterwards.',
        confirmText: 'Import'
      }).then(function (ok) { if (ok) { submit(ids); } });
    });
  }

  load();
})();
</script>
@endsection
