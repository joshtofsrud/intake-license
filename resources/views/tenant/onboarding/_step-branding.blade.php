<div class="ob-step-label">Step 1 of 3</div>
<h1 class="ob-title">Let's set up your brand</h1>
<p class="ob-subtitle">This takes about 2 minutes. You can change everything later.</p>

@if($errors->any())
  <div class="error">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ route('tenant.onboarding.branding.save') }}" enctype="multipart/form-data">
  @csrf

  <label>Shop name *</label>
  <input type="text" name="name" value="{{ old('name', $currentTenant->name) }}" required
    placeholder="e.g. Spokes Cycle Works">

  <label>Tagline</label>
  <input type="text" name="tagline" value="{{ old('tagline', $currentTenant->tagline) }}"
    placeholder="e.g. Expert bike service since 2010">
  <p class="ob-hint">Shown on your website and booking page.</p>

  <label>Brand color</label>
  <div class="ob-color-row">
    <div class="ob-color-swatch">
      <input type="color" id="cp-accent" name="accent_color"
        value="{{ old('accent_color', $currentTenant->accent_color ?? '#BEF264') }}">
    </div>
    <input type="text" class="ob-color-text" id="ct-accent"
      value="{{ old('accent_color', $currentTenant->accent_color ?? '#BEF264') }}"
      pattern="^#[0-9A-Fa-f]{6}$" placeholder="#BEF264">
  </div>
  <p class="ob-hint">Used for buttons and highlights on your booking page.</p>

  <label>Logo <span style="font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></label>
  <input type="file" name="logo" accept="image/*">
  <p class="ob-hint">PNG or SVG, max 2MB. You can add this later.</p>

  <button type="submit" class="ob-btn">Continue →</button>
</form>
