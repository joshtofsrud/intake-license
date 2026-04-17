@php
  $bk = $bk ?? [];
  $bkTheme = $bk['theme'] ?? 'light';
  $isDark = $bkTheme === 'dark';
  $bkAccent = ($bk['accent'] ?? null) ?: ($currentTenant->accent_color ?? '#BEF264');
  $bkText = $isDark
    ? (($bk['body_text'] ?? null) ?: '#f0f0f0')
    : (($bk['body_text'] ?? null) ?: ($currentTenant->text_color ?? '#111111'));
  $bkBg = $isDark ? '#111111' : ($currentTenant->bg_color ?? '#ffffff');
  $bkTint = $isDark
    ? (($bk['bg_tint'] ?? null) ?: '#1a1a1a')
    : (($bk['bg_tint'] ?? null) ?: '#FFFFFF');
  $bkOpacity = ($bk['bg_opacity'] ?? 100) / 100;
  $bkProgressBg = ($bk['progress_bg'] ?? null) ?: ($isDark ? '#333333' : '#ABA6A6');
  $bkProgressText = ($bk['progress_text'] ?? null) ?: ($isDark ? '#f0f0f0' : '#000000');
  $stepLabels = [
    $bk['step1_label'] ?? 'Services',
    $bk['step2_label'] ?? 'Schedule',
    $bk['step3_label'] ?? 'Details',
    $bk['step4_label'] ?? 'Review',
  ];
  \$bookingBg = \$isDark ? '#111111' : (\$currentTenant->bg_color ?? '#ffffff');
  \$logoUrl = \App\Support\ColorHelper::pickLogo(\$currentTenant, \$bookingBg);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Book online — {{ $currentTenant->name }}</title>
  @if($currentTenant->favicon_url)<link rel="icon" href="{{ $currentTenant->favicon_url }}">@endif
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family={{ str_replace(' ', '+', $currentTenant->font_heading ?? 'Inter') }}:wght@400;500;600;700&family={{ str_replace(' ', '+', $currentTenant->font_body ?? 'Inter') }}:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    :root {
      --p-accent:      {{ $bkAccent }};
      --p-accent-text: {{ \App\Support\ColorHelper::accentTextColor($bkAccent) }};
      --p-text:        {{ $bkText }};
      --p-bg:          {{ $bkBg }};
      --p-font-heading:'{{ $currentTenant->font_heading ?? 'Inter' }}', -apple-system, sans-serif;
      --p-font-body:   '{{ $currentTenant->font_body ?? 'Inter' }}', -apple-system, sans-serif;
      --p-r: 8px; --p-r-lg: 12px; --p-max: 1100px;
      --p-gutter: clamp(16px, 4vw, 48px);
      --bk-progress-bg: {{ $bkProgressBg }};
      --bk-progress-text: {{ $bkProgressText }};
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: var(--p-font-body);
      background: var(--p-bg);
      color: var(--p-text);
      -webkit-font-smoothing: antialiased;
    }
    @if($bkOpacity < 1)
    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background: {{ $bkTint }};
      opacity: {{ $bkOpacity }};
      z-index: -1;
      pointer-events: none;
    }
    @endif
    @if($isDark)
    .bk-top-bar { border-bottom-color: rgba(255,255,255,.08) !important; }
    .bk-item-card, .bk-review-card { border-color: rgba(255,255,255,.1) !important; }
    .bk-input, .bk-select, .bk-textarea, .bk-search { background: rgba(255,255,255,.06) !important; border-color: rgba(255,255,255,.12) !important; color: #f0f0f0 !important; }
    .bk-cal-day.available:hover { background: rgba(255,255,255,.08) !important; }
    .bk-sidebar { background: rgba(255,255,255,.04) !important; border-color: rgba(255,255,255,.1) !important; }
    .bk-addon-row { border-color: rgba(255,255,255,.08) !important; }
    @endif
    a { color: inherit; text-decoration: none; }
    button { font-family: inherit; }
    .bk-top-bar { border-bottom: 1px solid rgba(0,0,0,.08); padding: 14px var(--p-gutter); display: flex; align-items: center; justify-content: space-between; max-width: var(--p-max); margin: 0 auto; }
    .bk-top-logo { font-family: var(--p-font-heading); font-size: 17px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .bk-top-logo img { height: 28px; width: auto; border-radius: 4px; }
    .bk-top-back { font-size: 13px; opacity: .5; transition: opacity .12s; }
    .bk-top-back:hover { opacity: 1; }
  </style>
  <link rel="stylesheet" href="{{ asset('css/booking.css') }}">
</head>
<body>

<div class="bk-top-bar">
  <div class="bk-top-logo">
    @if($logoUrl)
      <img src="{{ $logoUrl }}" alt="{{ $currentTenant->name }}">
    @else
      {{ $currentTenant->name }}
    @endif
  </div>
  <a href="/" class="bk-top-back">← Back to site</a>
</div>

<div class="bk-progress" id="bk-progress">
  @foreach($stepLabels as $i => $label)
    <div class="bk-step {{ $i === 0 ? 'active' : '' }}" data-step="{{ $i + 1 }}">
      <div class="bk-step-dot">{{ $i + 1 }}</div>
      <span class="bk-step-label">{{ $label }}</span>
    </div>
    @if(!$loop->last)<div class="bk-step-line"></div>@endif
  @endforeach
</div>

<div class="bk-body">

{{-- Step 1: Services --}}
<div class="bk-section active" id="bk-step-1">
  <h1 class="bk-section-title">{{ $bk['step1_heading'] ?? 'What do you need serviced?' }}</h1>
  <p class="bk-section-sub">{{ $bk['step1_sub'] ?? 'Select one or more services.' }}</p>
  <div class="bk-toolbar">
    <input type="search" class="bk-search" id="bk-search" placeholder="Search services…">
  </div>
  <div id="bk-catalog">
    @forelse($catalog as $cat)
      <div class="bk-cat-group" data-cat="{{ strtolower($cat->name) }}">
        <div class="bk-cat-heading">{{ $cat->name }}</div>
        <div class="bk-item-grid">
          @foreach($cat->items as $item)
            <div class="bk-item-card" data-item-id="{{ $item->id }}" data-item-name="{{ $item->name }}">
              <div class="bk-item-name">{{ $item->name }}</div>
              <div class="bk-tiers">
                @foreach($item->tierPrices->filter(fn($p) => $p->price_cents !== null) as $price)
                  <button type="button" class="bk-tier-btn"
                    data-item-id="{{ $item->id }}"
                    data-item-name="{{ $item->name }}"
                    data-tier-id="{{ $price->tier_id }}"
                    data-tier-name="{{ $price->tier->name ?? '' }}"
                    data-price="{{ $price->price_cents }}">
                    <span>{{ $price->tier->name ?? '' }}</span>
                    <span class="bk-tier-price">{{ format_money($price->price_cents) }}</span>
                  </button>
                @endforeach
              </div>
            </div>
          @endforeach
        </div>
      </div>
    @empty
      <p style="opacity:.4">No services available yet.</p>
    @endforelse
  </div>
  @if($addons->isNotEmpty())
    <div style="margin-top:28px">
      <div class="bk-cat-heading">Add-ons</div>
      @foreach($addons as $addon)
        <div class="bk-addon-row">
          <input type="checkbox" id="addon-{{ $addon->id }}"
            data-addon-id="{{ $addon->id }}" data-addon-name="{{ $addon->name }}"
            data-addon-price="{{ $addon->price_cents }}" class="bk-addon-check"
            style="width:18px;height:18px;cursor:pointer;accent-color:var(--p-accent)">
          <label for="addon-{{ $addon->id }}" class="bk-addon-name" style="cursor:pointer">
            {{ $addon->name }}
            @if($addon->description)<small style="opacity:.5;display:block;font-size:12px">{{ $addon->description }}</small>@endif
          </label>
          <span class="bk-addon-price">{{ format_money($addon->price_cents) }}</span>
        </div>
      @endforeach
    </div>
  @endif
  <div class="bk-nav">
    <button type="button" class="bk-next" id="bk-next-1" disabled onclick="goTo(2)">Continue → {{ $stepLabels[1] }}</button>
  </div>
</div>

{{-- Step 2: Schedule --}}
<div class="bk-section" id="bk-step-2">
  <h1 class="bk-section-title">{{ $bk['step2_heading'] ?? 'Pick a drop-off date' }}</h1>
  <p class="bk-section-sub">{{ $bk['step2_sub'] ?? 'Choose an available date for your service.' }}</p>
  <div class="bk-calendar">
    <div class="bk-cal-header">
      <button type="button" class="bk-cal-nav" id="cal-prev">‹</button>
      <span class="bk-cal-month" id="cal-month-label"></span>
      <button type="button" class="bk-cal-nav" id="cal-next">›</button>
    </div>
    <div class="bk-cal-grid" id="cal-grid">
      @foreach(['Su','Mo','Tu','We','Th','Fr','Sa'] as $d)
        <div class="bk-cal-day-name">{{ $d }}</div>
      @endforeach
    </div>
    <div id="cal-loading" style="display:none;text-align:center;padding:16px;font-size:13px;opacity:.4">Loading…</div>
  </div>
  @if($receivingMethods->isNotEmpty())
    <div style="margin-top:24px;max-width:400px">
      <label class="bk-label">How are you dropping off?</label>
      <select class="bk-select" id="bk-receiving">
        <option value="">Select…</option>
        @foreach($receivingMethods as $rm)
          <option value="{{ $rm->name }}">{{ $rm->name }}</option>
        @endforeach
      </select>
    </div>
  @endif
  <div class="bk-nav">
    <button type="button" class="bk-back" onclick="goTo(1)">← Back</button>
    <button type="button" class="bk-next" id="bk-next-2" disabled onclick="goTo(3)">Continue → {{ $stepLabels[2] }}</button>
  </div>
</div>

{{-- Step 3: Details --}}
<div class="bk-section" id="bk-step-3">
  <div class="bk-details-layout">
    <div>
      <h1 class="bk-section-title">{{ $bk['step3_heading'] ?? 'Your details' }}</h1>
      <p class="bk-section-sub">{{ $bk['step3_sub'] ?? 'We\'ll use this to confirm your booking.' }}</p>
      <div class="bk-field-grid-2">
        <div class="bk-form-group">
          <label class="bk-label">First name *</label>
          <input type="text" class="bk-input" id="bk-first-name" required placeholder="Jane">
        </div>
        <div class="bk-form-group">
          <label class="bk-label">Last name *</label>
          <input type="text" class="bk-input" id="bk-last-name" required placeholder="Smith">
        </div>
      </div>
      <div class="bk-field-grid-2">
        <div class="bk-form-group">
          <label class="bk-label">Email *</label>
          <input type="email" class="bk-input" id="bk-email" required placeholder="jane@example.com">
        </div>
        <div class="bk-form-group">
          <label class="bk-label">Phone</label>
          <input type="tel" class="bk-input" id="bk-phone" placeholder="+1 (555) 000-0000">
        </div>
      </div>
      @foreach($formSections as $section)
        @if(!$section->is_core && $section->fields->isNotEmpty())
          <div style="margin-top:20px">
            <div style="font-size:11px;font-weight:600;margin-bottom:12px;opacity:.4;text-transform:uppercase;letter-spacing:.07em">{{ $section->title }}</div>
            @foreach($section->fields as $field)
              <div class="bk-form-group">
                <label class="bk-label">{{ $field->label }}@if($field->is_required) *@endif</label>
                @if($field->field_type === 'textarea')
                  <textarea class="bk-textarea bk-custom-field" data-field-key="{{ $field->field_key }}" data-field-label="{{ $field->label }}" {{ $field->is_required ? 'required' : '' }} placeholder="{{ $field->placeholder }}"></textarea>
                @elseif($field->field_type === 'select')
                  <select class="bk-select bk-custom-field" data-field-key="{{ $field->field_key }}" data-field-label="{{ $field->label }}" {{ $field->is_required ? 'required' : '' }}>
                    <option value="">Select…</option>
                    @foreach($field->options ?? [] as $opt)
                      <option value="{{ is_array($opt) ? ($opt['value'] ?? '') : $opt }}">{{ is_array($opt) ? ($opt['label'] ?? $opt['value'] ?? '') : $opt }}</option>
                    @endforeach
                  </select>
                @else
                  <input type="{{ $field->field_type }}" class="bk-input bk-custom-field" data-field-key="{{ $field->field_key }}" data-field-label="{{ $field->label }}" {{ $field->is_required ? 'required' : '' }} placeholder="{{ $field->placeholder }}">
                @endif
              </div>
            @endforeach
          </div>
        @endif
      @endforeach
      <div class="bk-nav">
        <button type="button" class="bk-back" onclick="goTo(2)">← Back</button>
        <button type="button" class="bk-next" id="bk-next-3" onclick="goToReview()">Continue → {{ $stepLabels[3] }}</button>
      </div>
    </div>
    <div class="bk-sidebar" id="bk-sidebar">
      <div class="bk-sidebar-title">Your order</div>
      <div id="bk-sidebar-items"><p class="bk-sidebar-empty">No items selected yet.</p></div>
    </div>
  </div>
</div>

{{-- Step 4: Review + Payment --}}
<div class="bk-section" id="bk-step-4">
  <h1 class="bk-section-title">{{ $bk['step4_heading'] ?? 'Review your order' }}</h1>
  <p class="bk-section-sub">{{ $bk['step4_sub'] ?? 'Confirm your booking details.' }}</p>
  <div class="bk-review-card">
    <div class="bk-review-head">Services</div>
    <div class="bk-review-body" id="bk-review-services"></div>
  </div>
  <div class="bk-review-card">
    <div class="bk-review-head">Date & contact</div>
    <div class="bk-review-body" id="bk-review-details"></div>
  </div>

  @if($stripeEnabled || $paypalEnabled)
  <div style="margin-bottom:24px">
    <div style="font-size:14px;font-weight:600;margin-bottom:12px">Payment method</div>
    <div class="bk-payment-methods">
      @if($stripeEnabled)
        <button type="button" class="bk-payment-btn {{ !$paypalEnabled ? 'selected' : '' }}" id="pay-stripe" onclick="selectPayment('stripe')">💳 Card</button>
      @endif
      @if($paypalEnabled)
        <button type="button" class="bk-payment-btn" id="pay-paypal" onclick="selectPayment('paypal')">🅿 PayPal</button>
      @endif
    </div>
    <div id="bk-stripe-wrap">
      <div id="bk-stripe-elements"></div>
    </div>
    <div id="bk-paypal-wrap" style="display:none">
      <div id="bk-paypal-button-container"></div>
    </div>
  </div>
  @endif

  <div id="bk-form-error" class="bk-error" style="display:none"></div>
  <div class="bk-nav">
    <button type="button" class="bk-back" onclick="goTo(3)">← Back</button>
    @if(!$stripeEnabled && !$paypalEnabled)
      <button type="button" class="bk-submit" id="bk-submit-btn" onclick="submitBooking('none')">Confirm booking</button>
    @elseif($stripeEnabled)
      <button type="button" class="bk-submit" id="bk-submit-btn" onclick="handlePayment()">Pay & confirm</button>
    @endif
  </div>
</div>

</div>{{-- /.bk-body --}}

<script>
window.BkData = {
  csrf:           '{{ csrf_token() }}',
  availUrl:       '{{ route("tenant.booking.availability") }}',
  submitUrl:      '{{ route("tenant.booking.submit") }}',
  currency:       '{{ $currentTenant->currency_symbol ?? "$" }}',
  stripeEnabled:  {{ $stripeEnabled ? 'true' : 'false' }},
  paypalEnabled:  {{ $paypalEnabled ? 'true' : 'false' }},
  stripePk:       '{{ $stripePublishableKey }}',
  paypalClientId: '{{ $paypalClientId }}',
  hasReceiving:   {{ $receivingMethods->isNotEmpty() ? 'true' : 'false' }},
};
</script>
@if($stripeEnabled)<script src="https://js.stripe.com/v3/"></script>@endif
@if($paypalEnabled)<script src="https://www.paypal.com/sdk/js?client-id={{ $paypalClientId }}&currency={{ strtoupper($currentTenant->currency ?? 'USD') }}"></script>@endif
<script src="{{ asset('js/booking.js') }}"></script>
</body>
</html>
