{{-- MARKER-SHOP-NAV — reads the shop's menu itself, so it can't disagree
     with it. Replaces the "Show in site navigation" checkbox, which saved a
     setting the site never read.
     MARKER-NAV-INTENT — the links open the page holding the Nav section
     (wherever it is) with that section selected; "Add it in the menu" also
     puts this page in the list, unsaved, and a dialog explains what to do. --}}
@php
  $snStRows = \App\Support\ShopNav::rows((string) $page->tenant_id);
  $snStPos  = null;
  foreach ($snStRows as $snI => $snR) {
      if (($snR['type'] ?? '') === 'page' && (string) $snR['page'] === (string) $page->id) { $snStPos = $snI + 1; break; }
  }
  $snHome = \App\Models\Tenant\TenantPage::where('tenant_id', $page->tenant_id)->where('is_home', true)->value('id');
  $snNav  = \App\Models\Tenant\TenantPageSection::where('tenant_id', $page->tenant_id)->where('section_type', 'nav')
              ->orderByRaw('page_id = ? desc', [$snHome])->first(['id', 'page_id']);
  $snBase = $snNav ? url('/admin/pages/' . $snNav->page_id) . '?section=' . $snNav->id : null;
@endphp
<div class="pb2-status-nav" style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--pb2-text-dim);margin-top:10px;padding-top:10px;border-top:.5px solid var(--pb2-border)">
  @if($snStPos === null)
    <span style="width:7px;height:7px;border-radius:50%;background:var(--pb2-text-faint);flex:none"></span>
    <span>Not in the menu</span>
  @elseif(! $page->is_published)
    <span style="width:7px;height:7px;border-radius:50%;background:#F0C46A;flex:none"></span>
    <span>In the menu · <b style="color:var(--pb2-text)">hidden until published</b></span>
  @else
    <span style="width:7px;height:7px;border-radius:50%;background:var(--pb2-accent);flex:none"></span>
    <span>In the menu · <b style="color:var(--pb2-text)">{{ \App\Support\MarketingNav::ordinal($snStPos) }} of {{ count($snStRows) }}</b></span>
  @endif
  @if(! $snBase)
    <span style="margin-left:auto;color:var(--pb2-text-faint)">Your site has no Nav section</span>
  @elseif($snStPos === null)
    <a href="{{ $snBase . '&add=' . $page->id }}" style="margin-left:auto;color:var(--pb2-accent)">Add it in the menu →</a>
  @else
    <a href="{{ $snBase . '&menu=1' }}" style="margin-left:auto;color:var(--pb2-accent)">Edit menu →</a>
  @endif
</div>
