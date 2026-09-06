{{-- MARKER-CAT-MAP — one rule row. Shared by the page and, later, import step 3. --}}
@php
  $rowOpts = $catOpts + ['__new__' => '+ Create a new category…'];
@endphp
<div data-cm-row data-cm-kind="{{ $kind }}" data-cm-source="{{ $sourceName }}" data-cm-bucket="{{ $bucket }}" data-cm-count="{{ (int) $count }}"
     style="display:grid;grid-template-columns:minmax(0,1fr) 90px 320px 150px;gap:12px;align-items:center;padding:9px 16px;border-bottom:.5px solid var(--ia-border);font-size:13px">
  <span style="font-family:var(--ia-font-mono,ui-monospace),monospace;font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $bucket }}</span>
  <span style="text-align:right;color:var(--ia-text-dim);font-variant-numeric:tabular-nums">{{ number_format($count) }}</span>
  <div>
    <x-tenant.searchable-select :name="'cat_' . md5($kind . $sourceName . $bucket)" :options="$rowOpts" :assoc="true"
      :selected="(string) ($categoryId ?? '')" any="— Uncategorized —" noun="categories" :searchable="count($catOpts) >= 12" />
  </div>
  <span>
    @if($setBy === 'user')
      <span style="font-size:10.5px;padding:1px 8px;border-radius:99px;border:.5px solid rgba(190,242,100,.45);color:var(--ia-accent)">you</span>
    @elseif($setBy === 'mapper')
      <span style="font-size:10.5px;padding:1px 8px;border-radius:99px;border:.5px solid var(--ia-border);color:var(--ia-text-dim)">learned · mapper</span>
    @else
      <span style="font-size:10.5px;padding:1px 8px;border-radius:99px;border:.5px solid rgba(240,196,106,.5);color:#F0C46A">no rule</span>
    @endif
  </span>
</div>
