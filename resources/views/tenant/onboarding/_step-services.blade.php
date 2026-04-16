<div class="ob-step-label">Step 2 of 3</div>
<h1 class="ob-title">Add your first service</h1>
<p class="ob-subtitle">
  This populates your booking form. Add your most common service to start —
  you can build out your full catalog in the Services tab.
</p>

@if($errors->any())
  <div class="error">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ route('tenant.onboarding.services.save') }}">
  @csrf

  <label>Service tier *</label>
  <input type="text" name="tier_name" value="{{ old('tier_name', 'Standard') }}" required
    placeholder="e.g. Standard, Full service, Rush">
  <p class="ob-hint">Tiers are pricing levels (e.g. Standard vs Premium). You can add more later.</p>

  <label>Category *</label>
  <input type="text" name="category_name" value="{{ old('category_name') }}" required
    placeholder="e.g. Mountain bike, Road bike, Ski tune">
  <p class="ob-hint">Group similar items together.</p>

  <label>First item *</label>
  <input type="text" name="item_name" value="{{ old('item_name') }}" required
    placeholder="e.g. Full tune-up, Fork service, Edge sharpening">

  <label>Price ({{ $currentTenant->currency_symbol ?? '$' }})</label>
  <input type="number" name="price" value="{{ old('price') }}" min="0" step="0.01"
    placeholder="e.g. 85.00">
  <p class="ob-hint">Leave blank if price varies or is quote-based.</p>

  <button type="submit" class="ob-btn">Continue →</button>
  <a href="{{ route('tenant.onboarding.index') }}?skip_services=1" class="ob-btn ob-btn-ghost"
     style="display:block;text-align:center;text-decoration:none;padding:13px;border-radius:10px;font-size:15px;font-weight:600;margin-top:10px">
    Skip for now
  </a>
</form>
