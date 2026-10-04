{{-- MARKER-SHOP-NAV — reads the shop's menu itself, so it can't disagree
     with it. Replaces the "Show in site navigation" checkbox, which saved a
     setting the site never read. "Edit menu" opens the Nav section. --}}
@php
  $snStRows = \App\Support\ShopNav::rows((string) $page->tenant_id);
  $snStPos  = null;
  foreach ($snStRows as $snI => $snR) {
      if (($snR['type'] ?? '') === 'page' && (string) $snR['page'] === (string) $page->id) { $snStPos = $snI + 1; break; }
  }
  $snHome = \App\Models\Tenant\TenantPage::where('tenant_id', $page->tenant_id)->where('is_home', true)->value('id');
  $snEdit = $snHome ? url('/admin/pages/' . $snHome) . '?select=nav' : null;
@endphp
<div class="pb2-status-nav" style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--pb2-text-dim);margin-top:10px;padding-top:10px;border-top:.5px solid var(--pb2-border)">
  @if($snStPos === null)
    <span style="width:7px;height:7px;border-radius:50%;background:var(--pb2-text-faint);flex:none"></span>
    <span>Not in the menu</span>
    @if($snEdit)<a href="{{ $snEdit }}" style="margin-left:auto;color:var(--pb2-accent)">Add it in the menu →</a>@endif
  @elseif(! $page->is_published)
    <span style="width:7px;height:7px;border-radius:50%;background:#F0C46A;flex:none"></span>
    <span>In the menu · <b style="color:var(--pb2-text)">hidden until published</b></span>
    @if($snEdit)<a href="{{ $snEdit }}" style="margin-left:auto;color:var(--pb2-accent)">Edit menu →</a>@endif
  @else
    <span style="width:7px;height:7px;border-radius:50%;background:var(--pb2-accent);flex:none"></span>
    <span>In the menu · <b style="color:var(--pb2-text)">{{ \App\Support\MarketingNav::ordinal($snStPos) }} of {{ count($snStRows) }}</b></span>
    @if($snEdit)<a href="{{ $snEdit }}" style="margin-left:auto;color:var(--pb2-accent)">Edit menu →</a>@endif
  @endif
</div>
