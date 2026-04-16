@extends('layouts.tenant.app')
@php $pageTitle = 'Services'; @endphp

@push('styles')
<style>
.sv-layout{display:grid;grid-template-columns:220px 1fr 280px;gap:0;height:calc(100vh - 120px);overflow:hidden;margin:-28px -32px}
.sv-panel{overflow-y:auto;padding:20px 16px;border-right:0.5px solid var(--ia-border)}
.sv-panel:last-child{border-right:none}
.sv-panel-title{font-size:11px;text-transform:uppercase;letter-spacing:.08em;font-weight:500;opacity:.4;margin-bottom:12px;padding:0 4px}
.sv-tier-row{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:var(--ia-r-md);cursor:pointer;font-size:13px;transition:background var(--ia-t);margin-bottom:2px}
.sv-tier-row:hover{background:var(--ia-hover)}
.sv-tier-row.is-selected{background:var(--ia-accent-soft)}
.sv-tier-dot{width:7px;height:7px;border-radius:50%;background:var(--ia-accent);flex-shrink:0}
.sv-tier-name{flex:1}
.sv-cat-head{display:flex;align-items:center;justify-content:space-between;padding:4px 6px 8px;margin-top:8px}
.sv-cat-name{font-size:13px;font-weight:500;cursor:pointer}
.sv-cat-name:hover{opacity:.7}
.sv-item-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;padding:0 0 16px}
.sv-item-card{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:12px;cursor:pointer;transition:all var(--ia-t);min-height:72px;display:flex;flex-direction:column;justify-content:space-between}
.sv-item-card:hover{border-color:var(--ia-accent)}
.sv-item-card.is-selected{border-color:var(--ia-accent);background:var(--ia-accent-soft)}
.sv-item-card.is-inactive{opacity:.4}
.sv-item-card.is-dragging{opacity:.5;transform:scale(.97)}
.sv-item-name{font-size:13px;font-weight:500;line-height:1.3}
.sv-item-price{font-size:11px;opacity:.5;margin-top:4px}
.sv-add-card{border:0.5px dashed var(--ia-border);border-radius:var(--ia-r-md);padding:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;min-height:72px;font-size:12px;opacity:.4;transition:opacity var(--ia-t)}
.sv-add-card:hover{opacity:.8}
.sv-settings-field{margin-bottom:14px}
.sv-settings-label{font-size:11px;opacity:.5;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px}
.sv-settings-empty{font-size:13px;opacity:.35;padding:20px 4px;text-align:center}
.sv-tier-price-row{display:flex;align-items:center;gap:8px;margin-bottom:8px}
.sv-tier-price-label{font-size:13px;flex:1}
.sv-tier-price-input{width:90px;padding:6px 10px;border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);background:var(--ia-input-bg);color:var(--ia-text);font-size:13px;text-align:right}
.sv-status-bar{padding:6px 4px;font-size:12px;opacity:.5;min-height:24px}
.sv-toggle-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.sv-toggle{width:34px;height:20px;background:var(--ia-border);border-radius:10px;position:relative;cursor:pointer;transition:background var(--ia-t);border:none;outline:none;flex-shrink:0}
.sv-toggle.on{background:var(--ia-accent)}
.sv-toggle::after{content:'';position:absolute;top:3px;left:3px;width:14px;height:14px;border-radius:50%;background:white;transition:transform var(--ia-t)}
.sv-toggle.on::after{transform:translateX(14px)}
@media(max-width:800px){.sv-layout{grid-template-columns:1fr;height:auto}.sv-panel{border-right:none;border-bottom:0.5px solid var(--ia-border)}}
</style>
@endpush

@section('content')
<div class="sv-layout" id="sv-app">
  <div class="sv-panel" id="sv-left">
    <div class="sv-panel-title">Tiers</div>
    <div id="sv-tier-list"></div>
    <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" style="width:100%;margin-top:8px" id="sv-add-tier">+ Add tier</button>
  </div>
  <div class="sv-panel" id="sv-canvas">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
      <div class="sv-panel-title" style="margin-bottom:0">Catalog</div>
      <div style="display:flex;gap:6px;align-items:center">
        <span class="sv-status-bar" id="sv-status"></span>
        <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" id="sv-add-category">+ Category</button>
      </div>
    </div>
    <div id="sv-catalog"></div>
  </div>
  <div class="sv-panel" id="sv-right">
    <div class="sv-panel-title">Settings</div>
    <div id="sv-settings-panel"><p class="sv-settings-empty">Select a tier, category, or item to edit.</p></div>
  </div>
</div>
@endsection

@push('scripts')
<script>
window.IntakeSVData = {
  catalog:  @json($jsCatalog),
  tiers:    @json($jsTiers),
  addons:   @json($jsAddons),
  ajaxUrl:  '{{ route("tenant.services.store") }}',
  currency: '{{ tenant()->currency_symbol ?? "$" }}',
  csrf:     '{{ csrf_token() }}',
};
</script>
<script src="{{ asset('js/tenant/services.js') }}" defer></script>
@endpush
