@extends('layouts.tenant.app')
@php $pageTitle = 'Inventory categories'; @endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Inventory</h1>
    <p class="ia-page-subtitle">{{ $categories->count() }} {{ \Illuminate\Support\Str::plural('category', $categories->count()) }}</p>
  </div>
</div>

@include('layouts.tenant._inventory-tabs')

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

@if($errors->any())
  <div class="ia-flash ia-flash--error">
    @foreach($errors->all() as $error){{ $error }}<br>@endforeach
  </div>
@endif

<div class="ia-card" style="margin-bottom:20px">
  <div class="ia-card-head"><span class="ia-card-title">Add a category</span></div>
  <form method="POST" action="{{ route('tenant.inventory.categories.store') }}">
    @csrf
    <div class="ia-card-body">
      <div class="ia-form-group">
        <label class="ia-form-label">Name <span class="ia-required">*</span></label>
        <input type="text" name="name" class="ia-input" required value="{{ old('name') }}"
          placeholder="e.g. Drivetrain, Tubes, Lubes, Tools" style="max-width:400px">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Parent category</label>
        {{-- MARKER-SSEL-BATCH1 — our picker: the native popup is drawn by the
             OS and ignored the dark theme, so this list read white on white
             for a tenant in light mode. --}}
        @php
          $sselParents = [];
          foreach ($tree as $o) { $sselParents[$o['id']] = str_repeat('— ', $o['depth']) . $o['name']; }
        @endphp
        <div style="max-width:400px">
          <x-tenant.searchable-select name="parent_id" :options="$sselParents"
            selected="" any="— None (top level) —" noun="categories"
            :searchable="count($sselParents) >= 12" />
        </div>
      </div>
      <button type="submit" class="ia-btn ia-btn--primary">Add category</button>
    </div>
  </form>
</div>

<div class="ia-card">
  <div class="ia-card-head">
    <span class="ia-card-title">Your categories</span>
    <span style="font-size:12px;color:var(--ia-text-muted);margin-left:8px">{{ $categories->count() }} total</span>
  </div>
  @if($categories->isEmpty())
    <div class="ia-card-body" style="text-align:center;padding:40px 20px;color:var(--ia-text-muted)">
      No categories yet. Add your first one above.
    </div>
  @else
<div class="ia-table-wrap">
    <table class="ia-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Parent (move)</th>
          <th>Items</th>
            {{-- MARKER-CAT-EDIT --}}
            <th style="width:210px">Actions</th>
        </tr>
      </thead>
      <tbody>
        @foreach($tree as $node)
          <tr>
            <td style="padding-left:{{ 12 + $node['depth'] * 22 }}px">@if($node['depth'] > 0)<span style="color:var(--ia-text-muted)">└&nbsp;</span>@endif<a href="{{ route('tenant.inventory.index', ['category' => $node['id']]) }}" style="color:var(--ia-text);text-decoration:none" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'"><strong>{{ $node['name'] }}</strong></a>{{-- MARKER-PATCH-HLC28-NAME --}}</td>
            <td>
              <form method="POST" action="{{ route('tenant.inventory.categories.reparent', $node['id']) }}" style="margin:0">
                @csrf
                @method('PATCH')
                {{-- MARKER-SSEL-BATCH1 — one of these per row; on 291
                     categories it is the worst instance of the OS popup. The
                     component fires `change` on its hidden input, and the
                     handler at the foot of this page submits the form, so
                     reparenting still saves on pick as it always has. --}}
                @php
                  $sselRow = [];
                  foreach ($tree as $o) {
                      if ($o['id'] !== $node['id']) { $sselRow[$o['id']] = str_repeat('— ', $o['depth']) . $o['name']; }
                  }
                @endphp
                <div style="max-width:260px" data-ssel-submit>
                  <x-tenant.searchable-select name="parent_id" :options="$sselRow"
                    :selected="$node['parent_id'] ?? ''" any="— top level —" noun="categories"
                    :searchable="count($sselRow) >= 12" />
                </div>
              </form>
            </td>
            <td>@if($node['count'] > 0)<a href="{{ route('tenant.inventory.index', ['category' => $node['id']]) }}" style="color:var(--ia-accent);text-decoration:none;font-weight:600" title="View these items">{{ $node['count'] }}</a>@else<span style="color:var(--ia-text-muted)">0</span>@endif{{-- MARKER-PATCH-HLC28-COUNT --}}</td>
            {{-- MARKER-CAT-EDIT — rename is inline; delete only appears when the
                 category is genuinely empty, counting ARCHIVED items too. The
                 disabled state carries its reason rather than failing on click. --}}
            @php
              $catAll   = (int) ($allCounts[$node['id']] ?? 0);
              $catKids  = (int) ($childCounts[$node['id']] ?? 0);
              $catEmpty = $catAll === 0 && $catKids === 0;
              $canRn    = $authUser->can('inventory.categories.rename');
              $canDel   = $authUser->can('inventory.categories.delete');
            @endphp
            <td>
              @if($canRn || $canDel)
                <div class="cat-acts" data-id="{{ $node['id'] }}">
                  @if($canRn)
                    <form method="POST" action="{{ route('tenant.inventory.categories.rename', $node['id']) }}"
                          class="cat-rn-form" style="display:none;margin:0">
                      @csrf
                      @method('PATCH')
                      <input type="text" name="name" value="{{ $node['name'] }}" class="ia-input cat-rn-input"
                             style="width:150px;padding:4px 7px;font-size:12.5px">
                      <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save</button>
                      <button type="button" class="ia-btn ia-btn--sm cat-rn-cancel">Cancel</button>
                    </form>
                  @endif

                  <div class="cat-act-buttons">
                    @if($canRn)
                      <button type="button" class="ia-btn ia-btn--sm cat-rn-start">Rename</button>
                    @endif

                    @if($canDel)
                      @if($catEmpty)
                        <button type="button" class="ia-btn ia-btn--sm cat-del-start"
                                style="color:var(--ia-danger,#f2777a)">Delete</button>
                      @else
                        <button type="button" class="ia-btn ia-btn--sm" disabled
                                title="{{ $catKids > 0
                                    ? $catKids . ' sub-categor' . ($catKids === 1 ? 'y' : 'ies') . ' under it'
                                    : $catAll . ' item' . ($catAll === 1 ? '' : 's') . ' in it (archived included)' }}"
                                style="opacity:.35">Delete</button>
                      @endif
                    @endif
                  </div>

                  @if($canDel && $catEmpty)
                    <form method="POST" action="{{ route('tenant.inventory.categories.destroy', $node['id']) }}"
                          class="cat-del-form" style="display:none;margin:0">
                      @csrf
                      @method('DELETE')
                      <span style="font-size:12px;color:var(--ia-text-muted)">Delete “{{ $node['name'] }}”?</span>
                      <button type="submit" class="ia-btn ia-btn--sm"
                              style="color:var(--ia-danger,#f2777a)">Yes, delete</button>
                      <button type="button" class="ia-btn ia-btn--sm cat-del-cancel">Keep it</button>
                    </form>
                  @endif
                </div>
              @else
                <span style="color:var(--ia-text-muted);font-size:12px">—</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
</div>
  @endif
</div>

@endsection

{{-- MARKER-SSEL-BATCH1 — ssel-submit-handler. The native row select had
     onchange="this.form.submit()"; the component has no onchange, so the same
     behaviour is bound to its hidden input's change event instead. --}}
<script>
  document.addEventListener('change', function (e) {
    var host = e.target.closest && e.target.closest('[data-ssel-submit]');
    if (!host || !e.target.classList.contains('ssel-val')) { return; }
    var form = host.closest('form');
    if (form) { form.submit(); }
  });
</script>

@push('scripts')
<script>
// MARKER-CAT-EDIT — swap a row between its buttons and one of its two forms.
// No native confirm(): deleting asks inside the row, which the browser cannot
// suppress, and rename needs no confirmation at all because it is reversible.
(function () {
  function show(wrap, which) {
    var btns = wrap.querySelector('.cat-act-buttons');
    var rn   = wrap.querySelector('.cat-rn-form');
    var del  = wrap.querySelector('.cat-del-form');
    if (btns) { btns.style.display = which === null ? '' : 'none'; }
    if (rn)   { rn.style.display   = which === 'rename' ? 'inline-flex' : 'none'; }
    if (del)  { del.style.display  = which === 'delete' ? 'inline-flex' : 'none'; }
    if (which === 'rename' && rn) {
      var i = rn.querySelector('.cat-rn-input');
      if (i) { i.focus(); i.select(); }
    }
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.classList) { return; }
    var wrap = t.closest ? t.closest('.cat-acts') : null;
    if (!wrap) { return; }

    if (t.classList.contains('cat-rn-start'))  { show(wrap, 'rename'); }
    if (t.classList.contains('cat-del-start')) { show(wrap, 'delete'); }
    if (t.classList.contains('cat-rn-cancel') || t.classList.contains('cat-del-cancel')) {
      show(wrap, null);
    }
  });

  // Escape backs out of whichever form is open.
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') { return; }
    var open = document.querySelector('.cat-rn-form[style*="inline-flex"], .cat-del-form[style*="inline-flex"]');
    if (open) { show(open.closest('.cat-acts'), null); }
  });
})();
</script>
@endpush
