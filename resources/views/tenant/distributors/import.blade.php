@extends('layouts.tenant.app')
@php
  $pageTitle = 'Import from a distributor';
  // the distributor's name as people write it ("Shimano"), not its code
  $distLabel = fn ($c) => app(\App\Services\Distributors\DistributorRegistry::class)->label((string) $c);
@endphp


@push('styles')
<style>
.im-card{background:var(--ia-surface);border:.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:22px;margin-bottom:18px}
.im-h{font-size:15px;font-weight:600;margin:0 0 4px}
.im-sub{font-size:12.5px;color:var(--ia-text-dim);margin-bottom:16px;line-height:1.5}
.im-row{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end}
.im-field{flex:1;min-width:200px}
.im-field label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;margin-bottom:6px}
.im-input{width:100%;background:var(--ia-input-bg);border:1px solid var(--ia-border);border-radius:var(--ia-r-md);padding:9px 11px;color:var(--ia-text);font-size:13px}
.im-input:focus{outline:none;border-color:var(--ia-accent)}
.im-check{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--ia-text-muted);margin-top:12px}
.im-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:var(--ia-r-md);font-size:13px;font-weight:600;cursor:pointer;border:1px solid var(--ia-border-strong);background:var(--ia-surface-2);color:var(--ia-text)}
.im-btn.primary{background:var(--ia-accent);color:var(--ia-accent-text);border-color:var(--ia-accent)}
.im-btn:disabled{opacity:.5;cursor:not-allowed}
.im-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin:6px 0 16px}
.im-stat{background:var(--ia-surface-2);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:13px 14px;text-align:center}
.im-stat .v{font-size:24px;font-weight:700;font-family:var(--ia-mono)}
.im-stat .k{font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;margin-top:3px}
.im-banner{padding:11px 15px;border-radius:var(--ia-r-md);font-size:13px;margin-bottom:16px;border:1px solid}
.im-ok{background:rgba(99,153,34,.15);border-color:rgba(99,153,34,.4);color:#cfe6ab}
.im-err{background:rgba(226,75,74,.12);border-color:rgba(226,75,74,.4);color:#f0a3a3}
.im-info{background:var(--ia-accent-soft);border-color:rgba(190,242,100,.2);color:var(--ia-text-muted)}
.im-stat .s{font-size:11.5px;color:var(--ia-text-dim);margin-top:6px;line-height:1.45}
/* the preview's row-by-row list */
.ip-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:22px 0 10px}
.ip-tabs{display:inline-flex;border:1px solid var(--ia-border-strong);border-radius:var(--ia-r-md);overflow:hidden}
.ip-tabs button{padding:7px 13px;font-size:12.5px;font-weight:600;border:0;background:transparent;color:var(--ia-text-dim);cursor:pointer}
.ip-tabs button.on{background:var(--ia-accent);color:var(--ia-accent-text)}
.ip-tbl{width:100%;border-collapse:collapse;font-size:13px}
.ip-tbl th{font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;text-align:left;padding:8px 10px;border-bottom:.5px solid var(--ia-border)}
.ip-tbl td{padding:10px;border-bottom:.5px solid var(--ia-border);vertical-align:middle}
.ip-tbl .m{font-size:11.5px;color:var(--ia-text-dim);margin-top:3px;font-family:var(--ia-mono)}
.ip-tbl .r{text-align:right;white-space:nowrap}
.ip-out{font-size:12.5px;line-height:1.45}
.ip-out a{color:var(--ia-accent)}
.ip-tag{display:inline-block;font-size:10.5px;font-weight:600;padding:1px 7px;border-radius:99px;margin-left:6px;background:var(--ia-surface-2);color:var(--ia-text-dim)}
.ip-tag.part{background:rgba(251,191,36,.14);color:#fbbf24}
.ip-pager{display:flex;gap:10px;align-items:center;justify-content:flex-end;margin-top:12px;font-size:12.5px;color:var(--ia-text-dim)}
.ip-empty{padding:22px 10px;color:var(--ia-text-dim);font-size:13px}
</style>
@endpush

@section('content')
<div class="ia-section">
  <h1 style="font-size:20px;font-weight:600;margin-bottom:14px">Import from {{ $distLabel($importCode) }}</h1>

  {{-- brands, categories and counts are per
       distributor, and there are thousands of each, so switching reloads
       rather than shipping every distributor's lists to filter in the
       browser. --}}
  @if (count($importCodes) > 1)
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap">
      <span style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600">Distributor</span>
      @foreach ($importCodes as $c)
        <a href="{{ route('tenant.distributors.import', ['code' => $c]) }}"
           style="padding:5px 13px;border-radius:20px;font-size:12.5px;font-weight:600;text-decoration:none;
                  border:1px solid {{ $c === $importCode ? 'var(--ia-text)' : 'var(--ia-border)' }};
                  background:{{ $c === $importCode ? 'var(--ia-text)' : 'var(--ia-surface-2)' }};
                  color:{{ $c === $importCode ? 'var(--ia-bg)' : 'var(--ia-text-dim)' }}">{{ $distLabel($c) }}</a>
      @endforeach
    </div>
  @endif
  @include('layouts.tenant._inventory-tabs')

  @if(session('error'))<div class="im-banner im-err">{{ session('error') }}</div>@endif
  {{-- errors set on the view, not only flashed. --}}
  @if(!empty($error))<div class="im-banner im-err">{{ $error }}</div>@endif
  @if(!empty($queued))
    <div class="im-banner">
      <b>Importing {{ number_format($queued['total']) }} items from {{ $queued['code'] }}.</b>
      It runs in the background — the bar at the top of the page tracks it, and you can carry on working.
      Everything it adds lands on one batch, so it can be undone in one go from catalog history.
    </div>
  @endif

  <div class="im-card">
    <h2 class="im-h">Import items</h2>
    <p class="im-sub">Pick a brand and/or category, preview what comes in, then import. Items land catalog-only (no stock) — your normal product search finds them after. <b>{{ number_format($catalogTotal) }}</b> {{ $distLabel($importCode) }} items in the shared catalog.
      An item another distributor already supplies is added as a second source rather than duplicated.
      Brands you have switched off are not listed here — <a href="{{ route('tenant.distributors.brands') }}" style="color:var(--ia-accent)">manage brands</a>. Switching a brand off never removes items you already carry.</p>

    <form method="POST" action="{{ route('tenant.distributors.import.run') }}">
            <input type="hidden" name="code" value="{{ $importCode }}">
      @csrf
      <input type="hidden" name="mode" value="preview" id="im-mode">
      <div class="im-row">
        <div class="im-field"><label>Brand</label>
          {{-- searchable picker, same field name --}}
          <x-tenant.searchable-select name="brand" :options="$brands" :selected="$filters['brand'] ?? ''" any="Any brand" noun="brands" />
        </div>
        <div class="im-field"><label>Category</label>
          <x-tenant.searchable-select name="category" :options="$categories" :selected="$filters['category'] ?? ''" any="Any category" noun="categories" />
        </div>
      </div>
      <label class="im-check"><input type="checkbox" name="include_unsellable" value="1" @checked(!empty($filters['include_unsellable']))> Include discontinued / unsellable items</label>

      <div style="margin-top:16px;display:flex;gap:10px">
        <button class="im-btn primary" type="submit" onclick="document.getElementById('im-mode').value='preview'">Preview</button>
      </div>
      <p class="im-sub" style="margin:10px 0 0">Leave both as “Any” to bring in the whole catalog. Importing runs in the background whatever the size — the bar at the top of the page tracks it, and it all lands on one batch you can undo in one go.</p>
    </form>
  </div>

  @isset($result)
    <div class="im-card">
      <h2 class="im-h">{{ $mode === 'commit' ? 'Import receipt' : 'Preview' }}</h2>
      <p class="im-sub">
        {{ $filters['brand'] ?? 'Any brand' }} · {{ $filters['category'] ?? 'any category' }}
        @if(!empty($filters['include_unsellable'])) · incl. unsellable @endif
      </p>

      @php
        // when the preview only inspected a
        // leading sample, the honest headline is the number that WILL be
        // imported, not the size of the sample.
        $sampled = (int) ($result['sampled'] ?? 0);
        $candTotal = (int) ($result['candidate_total'] ?? 0);
        $isSample = $mode !== 'commit' && $candTotal > 0 && $sampled > 0 && $candTotal > $sampled;
      @endphp

      <div class="im-stats">
        <div class="im-stat">
          <div class="v" style="color:var(--ia-accent)">{{ number_format($isSample ? $candTotal : $result['created']) }}</div>
          <div class="k">{{ $mode==='commit' ? 'Created' : 'New items' }}</div>
        </div>
        <div class="im-stat"><div class="v">{{ number_format($result['merged']) }}</div><div class="k">{{ $mode==='commit' ? 'Source added' : 'Adds ' . $distLabel($importCode) . ' to items you carry' }}</div>
          @if($mode !== 'commit' && ($result['merged'] ?? 0) > 0)
            <div class="s">{{ number_format($result['by_barcode'] ?? 0) }} by barcode · {{ number_format($result['by_part'] ?? 0) }} by part number @if(($result['by_sku'] ?? 0) > 0) · {{ number_format($result['by_sku']) }} by your SKU @endif</div>
          @endif
        </div>
        <div class="im-stat"><div class="v" style="color:var(--ia-text-dim)">{{ number_format($result['skipped']) }}</div><div class="k">{{ $mode==='commit' ? 'Skipped' : 'Already linked' }}</div></div>
      </div>

      @if($isSample)
        <p class="im-sub" style="margin:8px 0 0">
          “Already carried” and “Skipped” come from checking the first {{ number_format($sampled) }} rows —
          the rest are counted as they import.
        </p>
      @endif

      @if($mode === 'commit')
        <div class="im-banner im-ok">Done. {{ number_format($result['created']) }} new item(s) added to your catalog.</div>
        <a class="im-btn" href="{{ route('tenant.inventory.index') }}">Go to Inventory</a>
      @else
        @if(! $isSample && $result['created'] + $result['merged'] === 0)
          <div class="im-banner im-info">Nothing new to import for this filter — you already carry these, or none match.</div>
        @else
          <div class="im-banner im-info">
            Preview only — nothing imported yet.
            @if($isSample)
              Importing brings in all <b>{{ number_format($candTotal) }}</b> matching items, in the background.
            @else
              Importing adds {{ number_format($result['created']) }} new item(s) @if(($result['merged'] ?? 0) > 0) and adds {{ $distLabel($importCode) }} as a source on {{ number_format($result['merged']) }} you already carry @endif. Nothing is duplicated.
            @endif
          </div>
          <form method="POST" action="{{ route('tenant.distributors.import.run') }}"
                data-bulk-count="{{ (int) (isset($result['created']) ? ($result['created'] + ($result['merged'] ?? 0)) : ($catalogTotal ?? 0)) }}"{{-- what will actually be written; candidate_total also counts rows you already carry --}}{{-- candidate_total only exists after a preview; fall back to the catalog count so a straight commit still shows it --}}>
            <input type="hidden" name="code" value="{{ $importCode }}">
            @csrf
            <input type="hidden" name="mode" value="commit">
            <input type="hidden" name="brand" value="{{ $filters['brand'] ?? '' }}">
            <input type="hidden" name="category" value="{{ $filters['category'] ?? '' }}">
            <input type="hidden" name="include_unsellable" value="{{ !empty($filters['include_unsellable']) ? '1' : '' }}">
            {{-- the button must name what it does. --}}
            <button class="im-btn primary" type="submit">
              @if($isSample)
                Import all {{ number_format($candTotal) }} items
              @else
                Import {{ number_format($result['created']) }} new @if(($result['merged'] ?? 0) > 0) · add source to {{ number_format($result['merged']) }}@endif
              @endif
            </button>
          </form>
        @endif

        {{-- every row and what Import will do with it; search to check one product --}}
        @isset($planKey)
          <div id="ipList" data-key="{{ $planKey }}" data-code="{{ $importCode }}">
            <div class="ip-bar">
              <div class="ip-tabs" role="tablist">
                <button type="button" class="on" data-tab="all">All {{ number_format($result['created'] + $result['merged'] + $result['skipped']) }}</button>
                <button type="button" data-tab="new">New {{ number_format($result['created']) }}</button>
                <button type="button" data-tab="merge">Adds a source {{ number_format($result['merged']) }}</button>
                <button type="button" data-tab="linked">Already linked {{ number_format($result['skipped']) }}</button>
              </div>
              <input type="search" class="im-input" style="flex:1;min-width:220px;width:auto" data-ip-q placeholder="Search name, part number or barcode">
            </div>
            <table class="ip-tbl">
              <thead><tr><th>{{ $distLabel($importCode) }} item</th><th>What Import does</th><th class="r">MSRP</th><th></th></tr></thead>
              <tbody data-ip-body><tr><td colspan="4" class="ip-empty">Loading…</td></tr></tbody>
            </table>
            <div class="ip-pager"><span data-ip-count></span><button type="button" class="im-btn" data-ip-prev>‹ Prev</button><button type="button" class="im-btn" data-ip-next>Next ›</button></div>
          </div>
        @endisset
      @endif
    </div>
  @endisset
</div>

{{-- the preview list: paging, search, tabs and one-item import --}}
<script>
(function () {
  var box = document.getElementById('ipList');
  if (!box) { return; }
  var planUrl = @json(route('tenant.distributors.import.plan'));
  var oneUrl  = @json(route('tenant.distributors.import.one'));
  var csrf    = @json(csrf_token());
  var dist    = @json($distLabel($importCode));
  var st = { tab: 'all', q: '', page: 1, pages: 1 };
  var body = box.querySelector('[data-ip-body]'), count = box.querySelector('[data-ip-count]');
  var prev = box.querySelector('[data-ip-prev]'), next = box.querySelector('[data-ip-next]');
  var esc = function (v) { var d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; };
  var money = function (c) { return c == null ? '—' : '$' + (c / 100).toFixed(2); };
  var how = { barcode: 'barcode', part: 'part number', sku: 'your SKU', same: 'same product' };

  function outcome(r) {
    if (r.action === 'new') { return 'New item'; }
    if (r.action === 'linked') {
      return 'Already linked' + (r.item_url ? ' · <a href="' + r.item_url + '">' + esc(r.item_name || 'open item') + '</a>' : '');
    }
    if (r.kind === 'same') { return 'Joins another new item in this import'; }
    return 'Adds ' + esc(dist) + ' to <a href="' + r.item_url + '">' + esc(r.item_name || 'your item') + '</a>'
      + '<span class="ip-tag' + (r.kind === 'part' ? ' part' : '') + '">by ' + esc(how[r.kind] || 'barcode') + '</span>';
  }
  function button(r) {
    if (r.action === 'linked') { return r.item_url ? '<a class="im-btn" href="' + r.item_url + '">Open</a>' : ''; }
    return '<button type="button" class="im-btn" data-ip-one="' + esc(r.id) + '">' + (r.action === 'new' ? 'Import' : 'Add source') + '</button>';
  }
  function load() {
    body.innerHTML = '<tr><td colspan="4" class="ip-empty">Loading…</td></tr>';
    var u = planUrl + '?key=' + encodeURIComponent(box.dataset.key) + '&tab=' + st.tab + '&q=' + encodeURIComponent(st.q) + '&page=' + st.page;
    fetch(u, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { body.innerHTML = '<tr><td colspan="4" class="ip-empty">' + esc(d.error || 'Could not load the list.') + '</td></tr>'; return; }
        st.page = d.page; st.pages = d.pages;
        count.textContent = d.total.toLocaleString() + ' item' + (d.total === 1 ? '' : 's') + ' · page ' + d.page + ' of ' + d.pages;
        prev.disabled = d.page <= 1; next.disabled = d.page >= d.pages;
        if (!d.rows.length) {
          body.innerHTML = '<tr><td colspan="4" class="ip-empty">' + (st.q
            ? 'Nothing in this preview matches “' + esc(st.q) + '”. Try the part number without dashes, or widen the brand and category above.'
            : 'Nothing in this group.') + '</td></tr>';
          return;
        }
        body.innerHTML = d.rows.map(function (r) {
          var ids = [r.brand, r.mpn ? 'MPN ' + r.mpn : '', r.part ? dist + ' ' + r.part : '', r.code].filter(Boolean).map(esc).join(' · ');
          return '<tr><td><div>' + esc(r.name) + '</div><div class="m">' + ids + '</div></td>'
            + '<td class="ip-out" data-ip-out>' + outcome(r) + '</td><td class="r">' + money(r.msrp) + '</td><td class="r" data-ip-btn>' + button(r) + '</td></tr>';
        }).join('');
      })
      .catch(function () { body.innerHTML = '<tr><td colspan="4" class="ip-empty">Could not load the list. Try again.</td></tr>'; });
  }

  box.querySelectorAll('[data-tab]').forEach(function (b) {
    b.addEventListener('click', function () {
      box.querySelectorAll('[data-tab]').forEach(function (x) { x.classList.toggle('on', x === b); });
      st.tab = b.dataset.tab; st.page = 1; load();
    });
  });
  var t; box.querySelector('[data-ip-q]').addEventListener('input', function (e) {
    clearTimeout(t); t = setTimeout(function () { st.q = e.target.value.trim(); st.page = 1; load(); }, 300);
  });
  prev.addEventListener('click', function () { if (st.page > 1) { st.page--; load(); } });
  next.addEventListener('click', function () { if (st.page < st.pages) { st.page++; load(); } });

  body.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ip-one]');
    if (!b) { return; }
    var row = b.closest('tr');
    b.disabled = true; b.textContent = 'Adding…';
    var f = new FormData();
    f.append('_token', csrf); f.append('code', box.dataset.code); f.append('id', b.dataset.ipOne); f.append('key', box.dataset.key);
    fetch(oneUrl, { method: 'POST', body: f, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { b.disabled = false; b.textContent = 'Try again'; row.querySelector('[data-ip-out]').insertAdjacentHTML('beforeend', '<div style="color:#f0a3a3;margin-top:4px">' + esc(d.error || 'Not added.') + '</div>'); return; }
        row.querySelector('[data-ip-out]').innerHTML = (d.did === 'created' ? 'Added to your inventory' : 'Source added to your item')
          + (d.url ? ' · <a href="' + d.url + '">Open</a>' : '');
        row.querySelector('[data-ip-btn]').innerHTML = d.url ? '<a class="im-btn" href="' + d.url + '">Open</a>' : '';
      })
      .catch(function () { b.disabled = false; b.textContent = 'Try again'; });
  });

  load();
})();
</script>

{{-- picking a brand narrows the category list live --}}
<script>
  (function () {
    var brand = document.querySelector('.ssel[data-name="brand"]');
    var cat   = document.querySelector('.ssel[data-name="category"]');
    if (!brand || !cat) { return; }
    var url = @json(route('tenant.distributors.import.categories'));
    var code = @json($importCode);

    function refresh() {
      var b = brand.querySelector('.ssel-val').value;
      var catBtn = cat.querySelector('.ssel-btn');
      catBtn.disabled = true;
      catBtn.style.opacity = '.55';
      fetch(url + '?code=' + encodeURIComponent(code) + '&brand=' + encodeURIComponent(b), {
        headers: { 'Accept': 'application/json' }
      })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (data && cat.__sselApi) { cat.__sselApi.setOptions(data.categories || []); }
        })
        .catch(function () {})
        .finally(function () {
          catBtn.disabled = false;
          catBtn.style.opacity = '';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
      brand.querySelector('.ssel-val').addEventListener('change', refresh);
    });
  })();
</script>

@endsection
