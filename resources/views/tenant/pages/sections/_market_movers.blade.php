{{--
  MARKER-MOVING-FAST: market_movers editor. Items, words and layout are edited in the Moving fast
  popup (edit.blade.php, #pb2-mf); it writes the hidden fields below, and the builder's normal
  Save writes them like any other section's fields.
--}}
@php
  $c   = $c ?? ($section->content ?? []);
  $get = fn ($k, $d = '') => $c[$k] ?? $d;
  $mfAvailable = $mfAvailable ?? \App\Support\MovingFast::available(tenant());
  $mfVendors = $mfVendors ?? [];
  $cfg = \App\Support\MovingFast::config($c);
  $json = fn ($v) => json_encode(is_array($v) ? array_values($v) : (json_decode((string) $v, true) ?: []));
  $listName = \App\Support\MovingFast::LISTS[$cfg['list']][0];
@endphp

<input type="checkbox" data-field="is_visible" value="1" {{ $section->is_visible ? 'checked' : '' }} style="display:none">

{{-- what the popup reads and writes --}}
<div hidden data-mf-fields>
  @foreach (['eyebrow', 'heading', 'body', 'btn_label', 'btn_url', 'mf_list', 'mf_mode', 'mf_count', 'mf_layout', 'mf_carry', 'mf_min', 'mf_max', 'mf_badges', 'mf_prices', 'mf_special'] as $f)
    <input type="hidden" data-field="{{ $f }}" value="{{ is_array($get($f)) ? '' : $get($f) }}">
  @endforeach
  @foreach (['mf_picked', 'mf_vendors', 'mf_cats', 'mf_brands'] as $f)
    <input type="hidden" data-field="{{ $f }}" value="{{ $json($get($f, [])) }}">
  @endforeach
</div>

<div class="pb2-tab-panel" data-tab="content">
  @if (! $mfAvailable)
    <div class="pb2-group">
      <div class="pb2-mf-locked"><b>This section is hidden on your site.</b> It shows products from your online store, which needs the online store add-on with your store switched on. Its settings and items are kept, and it comes back as it was when the store is on again.
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
          <a class="pb2-btn pb2-btn-primary" href="{{ route('tenant.feature_addons.index') }}">See add-ons</a>
          <button type="button" class="pb2-btn" onclick="document.getElementById('pb2-delete-section') && document.getElementById('pb2-delete-section').click()">Remove section</button>
        </div>
      </div>
    </div>
  @else
    <div class="pb2-group">
      <button type="button" class="pb2-btn pb2-btn-primary pb2-mf-open" data-mf-open>Open Moving fast</button>
      <div class="pb2-mf-legend"><b>Where the items come from:</b> how fast stock is leaving your vendors{{ $mfVendors ? ' (' . implode(', ', $mfVendors) . ')' : '' }}, across all the shops they sell to, not your own sales. Shoppers see your price and badges, never vendor names or numbers. Items you don't carry are added to your inventory as catalog items when they show, so shoppers can order them as special orders.</div>
      @unless ($mfVendors)
        <div class="pb2-mf-legend" style="border-color:rgba(239,159,39,.4)"><b>No vendors linked yet.</b> Connect a distributor in <a href="{{ route('tenant.distributors.connection') }}">Inventory › Connection &amp; sync</a> and this section fills with what is moving there.</div>
      @endunless
      <div class="pb2-mf-sum">
        <div class="row"><span>Items</span><span>{{ $cfg['mode'] === 'auto' ? $listName . ', automatic' : 'Picked by you' }}</span></div>
        <div class="row"><span>How many</span><span>{{ $cfg['count'] }}{{ $cfg['mode'] === 'auto' && $cfg['picked'] ? ' · ' . count($cfg['picked']) . ' pinned' : '' }}{{ $cfg['mode'] === 'hand' ? ' · ' . count($cfg['picked']) . ' picked' : '' }}</span></div>
        <div class="row"><span>Items you don't carry</span><span>{{ $cfg['special'] === 'hide' ? 'Hidden' : 'Shown as special orders' }}</span></div>
        <div class="row"><span>Heading</span><span>{{ $get('heading') ?: '—' }}</span></div>
      </div>
      <div class="pb2-field-hint">Items, words and layout are edited in the popup. Background is on the Design tab.</div>
    </div>
  @endif
</div>

<div class="pb2-tab-panel" data-tab="style">
  <div class="pb2-group">
    <div class="pb2-group-title">Background</div>
    <div class="pb2-field">
      <label class="pb2-field-label">Background color</label>
      <input type="text" class="pb2-input" data-field="bg_color" value="{{ $get('bg_color') }}" placeholder="Inherits page background">
    </div>
  </div>
</div>
