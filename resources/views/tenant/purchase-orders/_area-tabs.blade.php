{{-- MARKER-PO: Special orders and Purchase orders are one "Ordering" area. --}}
@php
  $au = auth('tenant')->user();
  $canSo = ! $au || $au->canAccessSection('special_orders');
  $canPo = ! $au || $au->canAccessSection('purchase_orders');
  $onPo = str_starts_with(Route::currentRouteName() ?? '', 'tenant.purchase-orders');
  $soOpen = $soOpen ?? \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', tenant()->id)->open()->count();
  $poOpen = $poOpen ?? \App\Models\Tenant\TenantPurchaseOrder::where('tenant_id', tenant()->id)->whereIn('status', array_merge([\App\Models\Tenant\TenantPurchaseOrder::DRAFT], \App\Models\Tenant\TenantPurchaseOrder::OPEN))->count();
@endphp
@if($canSo && $canPo)
<div class="ord-area-tabs">
  <a href="{{ route('tenant.special-orders.index') }}" class="{{ $onPo ? '' : 'on' }}">Special orders <span class="so-tab-count">{{ $soOpen }}</span></a>
  <a href="{{ route('tenant.purchase-orders.index') }}" class="{{ $onPo ? 'on' : '' }}">Purchase orders <span class="so-tab-count">{{ $poOpen }}</span></a>
</div>
<style>
  .ord-area-tabs{display:flex;gap:6px;margin:-4px 0 14px;flex-wrap:wrap}
  .ord-area-tabs a{font-size:13px;font-weight:600;padding:7px 12px;border-radius:8px;color:var(--ia-text-muted);text-decoration:none;display:inline-flex;gap:6px;align-items:center;border:0.5px solid transparent}
  .ord-area-tabs a.on{color:var(--ia-text);border-color:var(--ia-border);background:var(--ia-surface)}
  .ord-area-tabs .so-tab-count{font-size:11px;font-weight:600;padding:1px 6px;border-radius:999px;background:var(--ia-surface);color:var(--ia-text-muted)}
</style>
@endif
