@extends('layouts.tenant.app')
@php $pageTitle = 'Import from a distributor'; @endphp

{{-- MARKER-PATCH-HLC7B --}}

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
</style>
@endpush

@section('content')
<div style="max-width:880px">
  <h1 style="font-size:20px;font-weight:600;margin-bottom:14px">Import from {{ $importCode }}</h1>

  {{-- MARKER-IMPORTER-PER-CODE — brands, categories and counts are per
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
                  color:{{ $c === $importCode ? 'var(--ia-bg)' : 'var(--ia-text-dim)' }}">{{ $c }}</a>
      @endforeach
    </div>
  @endif
  @include('layouts.tenant._inventory-tabs')

  @if(session('error'))<div class="im-banner im-err">{{ session('error') }}</div>@endif
  {{-- MARKER-CATALOG-IMPORT-ALL — errors set on the view, not only flashed. --}}
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
    <p class="im-sub">Pick a brand and/or category, preview what comes in, then import. Items land catalog-only (no stock) — your normal product search finds them after. <b>{{ number_format($catalogTotal) }}</b> {{ $importCode }} items in the shared catalog.
      An item another distributor already supplies is added as a second source rather than duplicated.</p>

    <form method="POST" action="{{ route('tenant.distributors.import.run') }}">
            <input type="hidden" name="code" value="{{ $importCode }}">
      @csrf
      <input type="hidden" name="mode" value="preview" id="im-mode">
      <div class="im-row">
        <div class="im-field"><label>Brand</label>
          {{-- MARKER-SSEL — searchable picker, same field name --}}
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
      {{-- MARKER-IMPORT-PREVIEW-TOTAL --}}
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
        // MARKER-IMPORT-PREVIEW-TOTAL — when the preview only inspected a
        // leading sample, the honest headline is the number that WILL be
        // imported, not the size of the sample.
        $sampled = (int) ($result['sampled'] ?? 0);
        $candTotal = (int) ($result['candidate_total'] ?? 0);
        $isSample = $mode !== 'commit' && $candTotal > 0 && $sampled > 0 && $candTotal > $sampled;
      @endphp

      <div class="im-stats">
        <div class="im-stat">
          <div class="v" style="color:var(--ia-accent)">{{ number_format($isSample ? $candTotal : $result['created']) }}</div>
          <div class="k">{{ $mode==='commit' ? 'Created' : 'Will be imported' }}</div>
        </div>
        <div class="im-stat"><div class="v">{{ number_format($result['merged']) }}</div><div class="k">{{ $mode==='commit' ? 'Source added' : 'Already carried' }}</div></div>
        <div class="im-stat"><div class="v" style="color:var(--ia-text-dim)">{{ number_format($result['skipped']) }}</div><div class="k">Skipped</div></div>
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
          {{-- MARKER-IMPORT-PREVIEW-TOTAL --}}
          <div class="im-banner im-info">
            Preview only — nothing imported yet.
            @if($isSample)
              Importing brings in all <b>{{ number_format($candTotal) }}</b> matching items, in the background.
            @else
              Confirm to add {{ number_format($result['created']) }} new item(s).
            @endif
          </div>
          {{-- MARKER-BULK-WORKING --}}
          <form method="POST" action="{{ route('tenant.distributors.import.run') }}"
                data-bulk-count="{{ (int) ($result['candidate_total'] ?? 0) }}">
            <input type="hidden" name="code" value="{{ $importCode }}">
            @csrf
            <input type="hidden" name="mode" value="commit">
            <input type="hidden" name="brand" value="{{ $filters['brand'] ?? '' }}">
            <input type="hidden" name="category" value="{{ $filters['category'] ?? '' }}">
            <input type="hidden" name="include_unsellable" value="{{ !empty($filters['include_unsellable']) ? '1' : '' }}">
            {{-- MARKER-IMPORT-PREVIEW-TOTAL — the button must name what it does. --}}
            <button class="im-btn primary" type="submit">
              @if($isSample)
                Import all {{ number_format($candTotal) }} items
              @else
                Import {{ number_format($result['created']) }} item(s)
              @endif
            </button>
          </form>
        @endif
      @endif
    </div>
  @endisset
</div>

{{-- MARKER-SSEL-SCOPE — picking a brand narrows the category list live --}}
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
