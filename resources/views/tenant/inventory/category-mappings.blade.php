@extends('layouts.tenant.app')
@php $pageTitle = 'Category mappings'; @endphp

@section('content')
{{-- MARKER-CAT-MAP — Inventory > Category mappings. The one place a source
     category string maps to one of the shop's categories. Import step 3 and
     the uncategorized mapper both write here; this is where you come back. --}}
<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">Inventory</h1>
    <p class="ia-page-subtitle">Category mappings · {{ $rules->count() }} rule{{ $rules->count() === 1 ? '' : 's' }}{{ $sources->count() ? ' across ' . $sources->count() . ' source' . ($sources->count() === 1 ? '' : 's') : '' }}</p>
  </div>
</div>

@include('layouts.tenant._inventory-tabs')

<p style="font-size:12.5px;color:var(--ia-text-dim);margin:0 0 14px;line-height:1.6;max-width:820px">
  {{-- MARKER-SOURCE-CAT --}}
  Categories your imports brought in that don't match one of yours. Nothing was created — the items are uncategorized and remember what the file called them.
  Pick a category and those items move; you can finish this whenever, in any order.
</p>

<form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
  <input type="text" name="q" value="{{ $q }}" class="ia-input" placeholder="Search source strings or categories" style="width:290px">
  <div style="min-width:180px">
    @php $srcOpts = []; foreach ($sources as $s) { $srcOpts[$s] = $s; } @endphp
    <x-tenant.searchable-select name="source" :options="$srcOpts" :assoc="true" :selected="$src" any="All sources" noun="sources" :searchable="count($srcOpts) >= 12" />
  </div>
  <div style="min-width:150px">
    <x-tenant.searchable-select name="only" :options="['unmapped' => 'Unmapped only']" :assoc="true" :selected="$only" any="All" noun="views" :searchable="false" />
  </div>
  <button type="submit" class="ia-btn ia-btn--sm">Filter</button>
</form>

@if($unmapped->count())
  {{-- MARKER-SOURCE-CAT — overflow:visible, or the card clips the picker panel --}}
  <div class="ia-card" style="padding:0;margin-bottom:16px;overflow:visible">
    <div style="display:flex;align-items:baseline;justify-content:space-between;padding:12px 16px;border-bottom:.5px solid var(--ia-border)">
      <span style="font-size:13px;font-weight:600">Not mapped yet <span style="font-size:10.5px;padding:1px 8px;border-radius:99px;border:.5px solid rgba(240,196,106,.5);color:#F0C46A;margin-left:6px">{{ $unmapped->count() }}</span></span>
      <span style="font-size:11.5px;color:var(--ia-text-dim)">pick a category and the items move</span>
    </div>
    @foreach($unmapped as $u)
      @include('tenant.inventory._category-mapping-row', [
        'kind' => 'import', 'sourceName' => $u->source_name, 'bucket' => $u->source_category,
        'count' => $u->n, 'categoryId' => null, 'categoryName' => null, 'setBy' => null,
      ])
    @endforeach
  </div>
@endif

@forelse($groups as $gk => $rows)
  @php [$kind, $name] = explode('|', $gk, 2); @endphp
  <div class="ia-card" style="padding:0;margin-bottom:16px;overflow:visible">
    <div style="display:flex;align-items:baseline;justify-content:space-between;padding:12px 16px;border-bottom:.5px solid var(--ia-border)">
      <span style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px">
        {{ $name === 'UNKNOWN' ? 'Distributor catalogs' : $name }}
        <span style="font-size:10.5px;padding:1px 8px;border-radius:99px;border:.5px solid {{ $kind === 'import' ? 'rgba(240,196,106,.45)' : 'rgba(159,208,245,.4)' }};color:{{ $kind === 'import' ? '#F0C46A' : '#9fd0f5' }}">{{ $kind === 'import' ? 'CSV import' : 'distributor catalog' }}</span>
      </span>
      <span style="font-size:11.5px;color:var(--ia-text-dim)">{{ $rows->count() }} rule{{ $rows->count() === 1 ? '' : 's' }}</span>
    </div>
    @foreach($rows as $r)
      @include('tenant.inventory._category-mapping-row', [
        'kind' => $r->source_kind, 'sourceName' => $r->source_name, 'bucket' => $r->bucket_key,
        'count' => $counts[$r->id] ?? 0, 'categoryId' => $r->category_id, 'categoryName' => $r->category_name, 'setBy' => $r->set_by,
      ])
    @endforeach
  </div>
@empty
  @if(! $unmapped->count())
    <div class="ia-card" style="padding:28px;text-align:center;color:var(--ia-text-dim);font-size:13px">
      No mappings yet. Assign a whole bucket on the Uncategorized page, or map categories during an inventory import, and the rules appear here.
    </div>
  @endif
@endforelse

{{-- MARKER-CAT-MAP — change dialog. In-app, never confirm(). Asks whether to
     move the items the old rule covered, or only apply from now on. --}}
<div id="cm-dlg" style="display:none;position:fixed;inset:0;z-index:300;background:rgba(0,0,0,.55);align-items:center;justify-content:center;padding:20px">
  <form method="POST" action="{{ route('tenant.inventory.category-mappings.save') }}" id="cm-form"
        style="background:var(--ia-surface);border:.5px solid var(--ia-border);border-radius:14px;width:100%;max-width:520px;padding:18px 20px">
    @csrf
    <input type="hidden" name="source_kind" id="cm-kind">
    <input type="hidden" name="source_name" id="cm-src">
    <input type="hidden" name="bucket_key" id="cm-bucket">
    <input type="hidden" name="category_id" id="cm-cat">
    <div style="font-weight:600;font-size:14px" id="cm-title">Assign these items</div>
    <p style="font-size:12.5px;color:var(--ia-text-muted);margin:8px 0 14px;line-height:1.55" id="cm-body"></p>
    {{-- in-app, not window.prompt(): the name for a new category --}}
    <div id="cm-new-wrap" style="display:none;margin-bottom:14px">
      <label class="ia-form-label" style="font-size:12px">New category name</label>
      <input type="text" name="new_category" id="cm-new" class="ia-input" placeholder="e.g. Bottles &amp; Hydration" maxlength="120" style="width:100%">
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
      <button type="button" class="ia-btn ia-btn--sm ia-btn--ghost" onclick="document.getElementById('cm-dlg').style.display='none'">Cancel</button>
      <button type="submit" name="apply" value="forward" class="ia-btn ia-btn--sm ia-btn--ghost">Only from now on</button>
      <button type="submit" name="apply" value="move" class="ia-btn ia-btn--sm ia-btn--primary" id="cm-move">Move them too</button>
    </div>
  </form>
</div>

<script>
  // MARKER-CAT-MAP — a row's picker changes → open the dialog with the choice.
  document.addEventListener('change', function (e) {
    var row = e.target.closest && e.target.closest('[data-cm-row]');
    if (!row || !e.target.classList.contains('ssel-val')) { return; }
    var v = e.target.value, n = parseInt(row.dataset.cmCount || '0', 10);
    document.getElementById('cm-kind').value   = row.dataset.cmKind;
    document.getElementById('cm-src').value    = row.dataset.cmSource;
    document.getElementById('cm-bucket').value = row.dataset.cmBucket;
    document.getElementById('cm-new').value    = '';
    var newWrap = document.getElementById('cm-new-wrap');
    if (v === '__new__') {
      document.getElementById('cm-cat').value = '';
      newWrap.style.display = 'block';
      setTimeout(function () { document.getElementById('cm-new').focus(); }, 0);
    } else {
      document.getElementById('cm-cat').value = v;
      newWrap.style.display = 'none';
    }
    var label = row.querySelector('.ssel-cur') ? row.querySelector('.ssel-cur').textContent.trim() : v;
    document.getElementById('cm-title').textContent = '“' + row.dataset.cmBucket + '” → ' + (v === '__new__' ? 'a new category' : label);
    document.getElementById('cm-body').textContent = n
      ? n.toLocaleString() + ' item' + (n === 1 ? '' : 's') + ' came in as “' + row.dataset.cmBucket + '”. Move them to this category? They land on the undo rail, so this is reversible.'
      : 'Nothing currently carries this string.';
    document.getElementById('cm-move').hidden = !n;
    var dlg = document.getElementById('cm-dlg');
    dlg.style.display = 'flex';
  });
</script>
@endsection
