{{--
  MARKER-RENTAL-SECTIONS — rental_categories public render. Tiles for the
  tenant's checked categories in their chosen order, each linking to the
  browse page pre-filtered. Empty/archived categories drop out silently.
--}}
@php
  $rcTenant = $tenant ?? $currentTenant ?? null;
  // MARKER-RENTAL-SECTIONS hotfix — content cast may hand us a decoded
  // array; the editor writes a JSON string. Accept both.
  $rcRaw = $c['category_ids'] ?? [];
  $rcIds = is_array($rcRaw) ? $rcRaw : (json_decode((string) $rcRaw, true) ?: []);
  $rcImgRaw = $c['category_images'] ?? [];
  $rcImgMap = is_array($rcImgRaw) ? $rcImgRaw : (json_decode((string) $rcImgRaw, true) ?: []);
  $rcCats = collect();
  if ($rcTenant && $rcTenant->rentals_visible && $rcIds) {
      $found = \App\Models\Tenant\TenantRentalCategory::where('tenant_id', $rcTenant->id)
          ->whereNull('archived_at')
          ->whereIn('id', $rcIds)
          ->withCount(['units as rc_unit_count' => fn ($u) => $u->whereNull('archived_at')
              ->where('status', '!=', 'retired')
              ->where('available_for_rent', true)])
          ->get()->keyBy('id');
      // MARKER-RENTAL-SECTIONS — tile photos: chosen model per category,
      // else the category's first model with a photo.
      $rcPhotoModels = \App\Models\Tenant\TenantRentalModel::where('tenant_id', $rcTenant->id)
          ->whereNull('archived_at')
          ->whereNotNull('image_url')
          ->whereIn('category_id', $rcIds)
          ->orderBy('sort_order')->orderBy('name')
          ->get(['id', 'category_id', 'image_url']);
      foreach ($rcIds as $id) {
          $cat = $found[$id] ?? null;
          if ($cat && $cat->rc_unit_count > 0) {
              $chosen = $rcImgMap[(string) $id] ?? null;
              $photo = $chosen ? $rcPhotoModels->firstWhere('id', $chosen) : null;
              $cat->rc_tile_image = ($photo ?: $rcPhotoModels->firstWhere('category_id', $cat->id))?->image_url;
              $rcCats->push($cat);
          }
      }
  }
  $rcShowCounts = ($c['show_counts'] ?? '1') === '1';
  $rcCols = $c['columns'] ?? 'auto';
  $rcGrid = $rcCols === 'auto' ? 'repeat(auto-fill,minmax(220px,1fr))' : 'repeat(' . max(2, min(4, (int) $rcCols)) . ',1fr)';
  $rcPhotoTiles = ($c['tile_style'] ?? 'photo') !== 'compact';
  // MARKER-RENTAL-STYLE — style + advanced resolution (feature_grid model).
  $stBgMode  = $c['bg_mode'] ?? (!empty($c['bg_color']) ? 'color' : 'none');
  $stText    = ($c['text_color'] ?? '') ?: 'inherit';
  $stBody    = ($c['text_color_body'] ?? '') ?: 'inherit';
  $stAccent  = ($c['accent_color'] ?? '') ?: 'inherit';
  $stCardBg  = ($c['card_bg'] ?? '') ?: 'rgba(255,255,255,.6)';
  $stCardBd  = ($c['card_border'] ?? '') ?: 'rgba(0,0,0,.1)';
  $anchorId  = trim($c['anchor_id'] ?? '') ?: 'rental-categories';
  $custClass = trim($c['custom_classes'] ?? '');
  $instId    = 'p-rcat-' . ($section->id ?? uniqid());
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

@if($rcCats->isNotEmpty())
<style>@media (max-width:720px){ .{{ $instId ?? '' }} .rc-grid { grid-template-columns:1fr 1fr !important; } }</style>
<section class="p-section {{ $instId }} {{ $custClass }}" id="{{ $anchorId }}">
  <div class="p-container">
    <div class="p-section-head-wrap" style="text-align:center">
      @if(!empty($c['eyebrow']))<div class="p-eyebrow">{{ $c['eyebrow'] }}</div>@endif
      @if(!empty($c['heading']))<h2 class="p-section-heading rs-head">{{ $c['heading'] }}</h2>@endif
      @if(!empty($c['body']))<p class="rs-body" style="max-width:560px;margin:10px auto 0;opacity:.85;font-size:15px;line-height:1.6">{{ $c['body'] }}</p>@endif
    </div>
    <div style="display:grid;grid-template-columns:{{ $rcGrid }};gap:16px;margin-top:32px" class="rc-grid">
      @foreach($rcCats as $cat)
        <a href="{{ route('tenant.rentals.browse', ['category' => $cat->id]) }}"
           class="rs-card"
           style="display:block;border-width:1.5px;border-style:solid;border-radius:var(--p-r-lg,14px);text-decoration:none;color:inherit;overflow:hidden">
          @if($rcPhotoTiles && $cat->rc_tile_image)
            <div style="aspect-ratio:16/10;background:url('{{ $cat->rc_tile_image }}') center/cover no-repeat"></div>
          @endif
          <div style="padding:24px 22px">
          <div style="font-size:17px;font-weight:650;line-height:1.3">{{ $cat->name }}</div>
          @if($rcShowCounts)<div style="font-size:12px;opacity:.5;margin-top:6px">{{ $cat->rc_unit_count }} to rent</div>@endif
          <div class="rs-accent" style="font-size:13px;margin-top:14px;font-weight:600">Check availability →</div>
          </div>
        </a>
      @endforeach
    </div>
  </div>
</section>
@endif
