@php
  $cols = (int)($c['columns'] ?? 3);
  $showPrices = (bool)($c['show_prices'] ?? true);
@endphp

@push('styles')
<style>
.p-services-grid {
  display: grid;
  grid-template-columns: repeat({{ $cols }}, 1fr);
  gap: 20px;
}
.p-service-card {
  border: 1px solid rgba(0,0,0,.09);
  border-radius: var(--p-r-lg);
  padding: 24px;
  transition: border-color .15s, transform .15s;
}
.p-service-card:hover { border-color: var(--p-accent); transform: translateY(-2px); }
.p-service-cat-name {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .08em;
  font-weight: 600;
  opacity: .4;
  margin-bottom: 10px;
}
.p-service-item-name {
  font-family: var(--p-font-heading);
  font-size: 17px;
  font-weight: 600;
  margin-bottom: 8px;
}
.p-service-desc {
  font-size: 14px;
  opacity: .6;
  line-height: 1.5;
  margin-bottom: 14px;
}
.p-service-tiers { display: flex; flex-direction: column; gap: 6px; }
.p-service-tier-row { display: flex; justify-content: space-between; font-size: 13px; }
.p-service-tier-name { opacity: .6; }
.p-service-tier-price { font-weight: 600; }
@media (max-width: 900px) { .p-services-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 560px) { .p-services-grid { grid-template-columns: 1fr; } }
</style>
@endpush

<section class="p-section">
  <div class="p-container">
    @if(!empty($c['heading']))
      <div class="p-section-head-wrap">
        <h2 class="p-section-heading">{{ $c['heading'] }}</h2>
      </div>
    @endif

    @if($catalog->isEmpty())
      <p style="opacity:.4;font-size:15px">No services available yet.</p>
    @else
      @foreach($catalog as $category)
        @if($category->items->isNotEmpty())
          <div style="margin-bottom:40px">
            <h3 style="font-size:13px;text-transform:uppercase;letter-spacing:.08em;opacity:.4;font-weight:600;margin-bottom:16px">
              {{ $category->name }}
            </h3>
            <div class="p-services-grid">
              @foreach($category->items as $item)
                <div class="p-service-card">
                  <div class="p-service-item-name">{{ $item->name }}</div>
                  @if($item->description)
                    <p class="p-service-desc">{{ $item->description }}</p>
                  @endif
                  @if($showPrices && $item->tierPrices->isNotEmpty())
                    <div class="p-service-tiers">
                      @foreach($item->tierPrices as $price)
                        @if($price->price_cents !== null)
                          <div class="p-service-tier-row">
                            <span class="p-service-tier-name">{{ $price->tier->name ?? '' }}</span>
                            <span class="p-service-tier-price">{{ format_money($price->price_cents) }}</span>
                          </div>
                        @endif
                      @endforeach
                    </div>
                  @endif
                </div>
              @endforeach
            </div>
          </div>
        @endif
      @endforeach
    @endif

    <div style="margin-top:32px">
      <a href="/book" class="p-btn p-btn--primary">Book now</a>
    </div>
  </div>
</section>
