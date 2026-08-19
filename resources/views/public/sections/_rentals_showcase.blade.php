{{--
  MARKER-PATCH-239 — rentals_showcase public render. Live fleet pull:
  models (+ rates) in the chosen category, only rentable units counted.
  Renders nothing when rentals are hidden or no models match — a stale
  section can never show an empty shell or leak a disabled feature.
--}}
@php
  $rsTenant = $tenant ?? $currentTenant ?? null;
  $rsModels = collect();
  if ($rsTenant && $rsTenant->rentals_visible) {
      $rsModels = \App\Models\Tenant\TenantRentalModel::where('tenant_id', $rsTenant->id)
          ->whereNull('archived_at')
          ->when(!empty($c['category_id']), fn ($q) => $q->where('category_id', $c['category_id']))
          ->whereHas('units', fn ($u) => $u->whereNull('archived_at')
              ->where('status', '!=', 'retired')
              ->where('available_for_rent', true))
          ->withCount(['units as rs_unit_count' => fn ($u) => $u->whereNull('archived_at')
              ->where('status', '!=', 'retired')
              ->where('available_for_rent', true)])
          ->with('category:id,name')
          ->orderBy('sort_order')->orderBy('name')
          ->limit(max(1, min(24, (int) ($c['max_models'] ?? 6))))
          ->get();
  }
  $rsShowRates   = ($c['show_rates'] ?? '1') === '1';
  $rsShowDeposit = ($c['show_deposit'] ?? '0') === '1';
  // MARKER-RENTAL-STYLE — style + advanced resolution (feature_grid model).
  $stBgMode  = $c['bg_mode'] ?? (!empty($c['bg_color']) ? 'color' : 'none');
  $stText    = ($c['text_color'] ?? '') ?: 'inherit';
  $stBody    = ($c['text_color_body'] ?? '') ?: 'inherit';
  $stAccent  = ($c['accent_color'] ?? '') ?: 'inherit';
  $stCardBg  = ($c['card_bg'] ?? '') ?: 'rgba(255,255,255,.6)';
  $stCardBd  = ($c['card_border'] ?? '') ?: 'rgba(0,0,0,.1)';
  $anchorId  = trim($c['anchor_id'] ?? '') ?: 'rentals';
  $custClass = trim($c['custom_classes'] ?? '');
  $instId    = 'p-rsc-' . ($section->id ?? uniqid());
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

@if($rsModels->isNotEmpty())
<section class="p-section {{ $instId }} {{ $custClass }}" id="{{ $anchorId }}">
  <div class="p-container">
    <div class="p-section-head-wrap" style="text-align:center">
      @if(!empty($c['eyebrow']))<div class="p-eyebrow">{{ $c['eyebrow'] }}</div>@endif
      @if(!empty($c['heading']))<h2 class="p-section-heading rs-head">{{ $c['heading'] }}</h2>@endif
      @if(!empty($c['body']))<p class="rs-body" style="max-width:560px;margin:10px auto 0;opacity:.85;font-size:15px;line-height:1.6">{{ $c['body'] }}</p>@endif
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;margin-top:32px">
      @foreach($rsModels as $m)
        <div class="rs-card" style="border-width:1.5px;border-style:solid;border-radius:var(--p-r-lg,14px);overflow:hidden">
          @if($m->image_url)
            <div style="aspect-ratio:16/10;background:url('{{ $m->image_url }}') center/cover no-repeat"></div>
          @endif
          <div style="padding:20px 22px">
          <div class="rs-accent" style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.75">{{ $m->category?->name }}</div>
          <div style="font-size:17px;font-weight:650;margin-top:3px;line-height:1.3">{{ $m->name }}</div>
          @if($m->subtitle)<div style="font-size:12.5px;opacity:.55;margin-top:2px">{{ $m->subtitle }}</div>@endif
          @if($rsShowRates)
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:12px">
              @if($m->daily_rate_cents)<span style="font-size:12px;background:rgba(0,0,0,.05);border-radius:6px;padding:3px 9px"><b>{{ format_money($m->daily_rate_cents) }}</b>/day</span>@endif
              @if($m->hourly_rate_cents)<span style="font-size:12px;background:rgba(0,0,0,.05);border-radius:6px;padding:3px 9px"><b>{{ format_money($m->hourly_rate_cents) }}</b>/hr</span>@endif
              @if($m->weekend_rate_cents)<span style="font-size:12px;background:rgba(0,0,0,.05);border-radius:6px;padding:3px 9px"><b>{{ format_money($m->weekend_rate_cents) }}</b>/weekend</span>@endif
              @if($rsShowDeposit && $m->deposit_cents)<span style="font-size:12px;background:rgba(0,0,0,.05);border-radius:6px;padding:3px 9px">{{ format_money($m->deposit_cents) }} deposit</span>@endif
            </div>
          @endif
          <div style="font-size:11.5px;opacity:.45;margin-top:10px">{{ $m->rs_unit_count }} in the fleet</div>
          </div>
        </div>
      @endforeach
    </div>

    @if(!empty($c['cta_label']))
      <div style="text-align:center;margin-top:32px">
        <a href="{{ $c['cta_url'] ?: '/rentals' }}" class="p-btn p-btn--primary">{{ $c['cta_label'] }}</a>
      </div>
    @endif
  </div>
</section>
@endif
