{{--
  MARKER-RENTAL-SECTIONS — rental_spotlight public render. One model,
  hero treatment. Live rates/sizes/unit count; renders nothing when
  rentals are hidden or the model is gone/archived.
--}}
@php
  $spTenant = $tenant ?? $currentTenant ?? null;
  $spModel = null;
  if ($spTenant && $spTenant->rentals_visible && !empty($c['model_id'])) {
      $spModel = \App\Models\Tenant\TenantRentalModel::where('tenant_id', $spTenant->id)
          ->whereNull('archived_at')
          ->where('id', $c['model_id'])
          ->with('category:id,name')
          ->withCount(['units as sp_unit_count' => fn ($u) => $u->whereNull('archived_at')
              ->where('status', '!=', 'retired')
              ->where('available_for_rent', true)])
          ->first();
  }
  $spShowRates   = ($c['show_rates'] ?? '1') === '1';
  $spShowDeposit = ($c['show_deposit'] ?? '0') === '1';
  $spSizes = $spModel
      ? \App\Models\Tenant\TenantRentalUnit::where('tenant_id', $spTenant->id)
          ->where('model_id', $spModel->id)
          ->whereNull('archived_at')->where('status', '!=', 'retired')
          ->where('available_for_rent', true)
          ->whereNotNull('size')->distinct()->orderBy('size')->pluck('size')
      : collect();
  $spCta = !empty($c['cta_url']) ? $c['cta_url'] : ($spModel ? route('tenant.rentals.reserve', ['model' => $spModel->id]) : '/rentals');
  // MARKER-RENTAL-MODEL-PHOTOS — section image wins; fleet photo is the fallback.
  $spImage = !empty($c['image_url']) ? $c['image_url'] : ($spModel->image_url ?? '');
  $spImgLeft = ($c['image_position'] ?? 'left') !== 'right';
  $spImgRad = (int) ($c['image_radius'] ?? 14);
  // MARKER-RENTAL-STYLE — style + advanced resolution (feature_grid model).
  $stBgMode  = $c['bg_mode'] ?? (!empty($c['bg_color']) ? 'color' : 'none');
  $stText    = ($c['text_color'] ?? '') ?: 'inherit';
  $stBody    = ($c['text_color_body'] ?? '') ?: 'inherit';
  $stAccent  = ($c['accent_color'] ?? '') ?: 'inherit';
  $stCardBg  = ($c['card_bg'] ?? '') ?: 'rgba(255,255,255,.6)';
  $stCardBd  = ($c['card_border'] ?? '') ?: 'rgba(0,0,0,.1)';
  $anchorId  = trim($c['anchor_id'] ?? '') ?: 'rental-spotlight';
  $custClass = trim($c['custom_classes'] ?? '');
  $instId    = 'p-rsp-' . ($section->id ?? uniqid());
@endphp
<style>
.{{ $instId }} {
  @if($stBgMode === 'color' && !empty($c['bg_color'])) background: {{ $c['bg_color'] }};
  @elseif($stBgMode === 'gradient') background: linear-gradient({{ (int)($c['bg_gradient_angle'] ?? 135) }}deg, {{ ($c['bg_gradient_from'] ?? '') ?: '#ffffff' }} 0%, {{ ($c['bg_gradient_to'] ?? '') ?: '#f4f4f4' }} 100%);
  @endif
}
.{{ $instId }} .rs-head { color: {{ $stText }}; }
.{{ $instId }} .rs-body { color: {{ $stBody }}; }
.{{ $instId }} .p-eyebrow, .{{ $instId }} .rs-accent { color: {{ $stAccent }}; }
.{{ $instId }} .rs-card { background: {{ $stCardBg }}; border-color: {{ $stCardBd }}; }
@if(!empty($c['hide_on_mobile']))
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if(!empty($c['hide_on_desktop']))
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
</style>
@php $__rs_done = true;
@endphp

@if($spModel && $spModel->sp_unit_count > 0)
<section class="p-section {{ $instId }} {{ $custClass }}" id="{{ $anchorId }}">
  <div class="p-container">
    <div style="display:grid;grid-template-columns:{{ !empty($spImage) ? '1fr 1fr' : '1fr' }};gap:40px;align-items:center" class="p-spotlight-grid">
      @if(!empty($spImage))
        <div style="border-radius:{{ $spImgRad }}px;overflow:hidden;aspect-ratio:4/3;{{ $spImgLeft ? '' : 'order:2' }}">
          <img src="{{ $spImage }}" alt="{{ $c['image_alt'] ?? $spModel->name }}" style="width:100%;height:100%;object-fit:cover" loading="lazy">
        </div>
      @endif
      <div>
        <div class="rs-accent" style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.75">{{ $c['eyebrow'] ?: ($spModel->category?->name ?? 'Rentals') }}</div>
        <h2 class="p-section-heading rs-head" style="margin-top:6px">{{ $c['heading'] ?: $spModel->name }}</h2>
        @if($spModel->subtitle)<div style="font-size:14px;opacity:.55;margin-top:4px">{{ $spModel->subtitle }}</div>@endif
        @if(!empty($c['body']))<p class="rs-body" style="margin-top:14px;opacity:.85;font-size:15px;line-height:1.65">{{ $c['body'] }}</p>@endif
        @if($spShowRates)
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:18px">
            @if($spModel->daily_rate_cents)<span style="font-size:12.5px;background:rgba(0,0,0,.05);border-radius:6px;padding:4px 10px"><b>{{ format_money($spModel->daily_rate_cents) }}</b>/day</span>@endif
            @if($spModel->hourly_rate_cents)<span style="font-size:12.5px;background:rgba(0,0,0,.05);border-radius:6px;padding:4px 10px"><b>{{ format_money($spModel->hourly_rate_cents) }}</b>/hr</span>@endif
            @if($spModel->weekend_rate_cents)<span style="font-size:12.5px;background:rgba(0,0,0,.05);border-radius:6px;padding:4px 10px"><b>{{ format_money($spModel->weekend_rate_cents) }}</b>/weekend</span>@endif
            @if($spShowDeposit && $spModel->deposit_cents)<span style="font-size:12.5px;background:rgba(0,0,0,.05);border-radius:6px;padding:4px 10px">{{ format_money($spModel->deposit_cents) }} deposit</span>@endif
          </div>
        @endif
        <div style="font-size:12px;opacity:.5;margin-top:12px">
          {{ $spModel->sp_unit_count }} in the fleet @if($spSizes->isNotEmpty()) · sizes {{ $spSizes->implode(', ') }} @endif
        </div>
        <div style="margin-top:22px">
          <a href="{{ $spCta }}" class="p-btn p-btn--primary">{{ $c['cta_label'] ?: 'Reserve' }}</a>
        </div>
      </div>
    </div>
  </div>
</section>
<style>@media (max-width:720px){ .{{ $instId }} .p-spotlight-grid { grid-template-columns:1fr !important; } .{{ $instId }} .p-spotlight-grid > * { order:0 !important; } }</style>
@endif
