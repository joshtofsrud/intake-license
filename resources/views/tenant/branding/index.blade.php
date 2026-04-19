@php
  $pageTitle   = 'Branding';
  $activeTab   = request('tab', 'appearance');
  $adminThemeC = $currentTenant->settings['admin_theme'] ?? 'c';

  $fonts = ['Inter','Poppins','DM Sans','Nunito','Lato','Raleway','Montserrat','Playfair Display','Merriweather'];
@endphp

@push('styles')
<style>
.brand-tabs{display:flex;gap:0;border-bottom:0.5px solid var(--ia-border);margin-bottom:28px}
.brand-tab{padding:10px 20px;font-size:13px;color:var(--ia-text-muted);cursor:pointer;border-bottom:2px solid transparent;text-decoration:none;transition:all .12s}
.brand-tab:hover{color:var(--ia-text)}
.brand-tab.active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}
.brand-section{max-width:640px}
.brand-theme-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:8px}
.brand-theme-card{border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:14px;cursor:pointer;transition:all .12s;position:relative}
.brand-theme-card:hover{border-color:var(--ia-accent)}
.brand-theme-card.selected{border-color:var(--ia-accent);background:var(--ia-accent-soft)}
.brand-theme-card input{position:absolute;opacity:0;width:0;height:0}
.brand-theme-preview{height:60px;border-radius:var(--ia-r-md);overflow:hidden;margin-bottom:8px;display:flex}
.brand-theme-label{font-size:12px;font-weight:500;text-align:center}
.preview-a-side{width:35%;background:#0f0f0f}
.preview-a-main{flex:1;background:#f8f8f6}
.preview-b-wrap{flex:1;display:flex;flex-direction:column}
.preview-b-top{height:12px;background:#ffffff;border-bottom:0.5px solid #e8e8e4}
.preview-b-main{flex:1;background:#ffffff}
.preview-c-side{width:35%;background:#0c0c0c}
.preview-c-main{flex:1;background:#111111}
.color-swatch-row{display:flex;gap:10px;align-items:center;margin-top:6px}
.color-swatch{width:36px;height:36px;border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);overflow:hidden;cursor:pointer;flex-shrink:0}
.color-swatch input[type=color]{width:52px;height:52px;margin:-8px;border:none;cursor:pointer;background:none;padding:0}
.logo-preview{height:40px;border-radius:6px;margin-bottom:8px;display:block}
.logo-preview-dark{background:#111;padding:6px 10px;border-radius:6px;margin-bottom:8px;display:inline-block}
.logo-preview-dark img{height:32px}

.brand-theme-desktop-tag{display:inline-block;margin-left:6px;padding:1px 6px;font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;background:rgba(0,0,0,.08);color:rgba(0,0,0,.55);border-radius:3px;vertical-align:middle}
.ia-theme-c .brand-theme-desktop-tag{background:rgba(255,255,255,.1);color:rgba(255,255,255,.6)}

@media (max-width: 1023px) {
  .brand-theme-card--desktop-only{opacity:.4;cursor:not-allowed;pointer-events:none}
  .brand-theme-card--desktop-only input[type=radio]{pointer-events:none}
}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Branding</h1>
    <p class="ia-page-subtitle">Customize your shop's look and communication.</p>
  </div>
</div>

<div class="brand-tabs">
  <a href="?tab=appearance" class="brand-tab {{ $activeTab === 'appearance' ? 'active' : '' }}">Appearance</a>
  <a href="?tab=email"      class="brand-tab {{ $activeTab === 'email'      ? 'active' : '' }}">Email</a>
</div>

@if($activeTab === 'appearance')
<form method="POST" action="{{ route('tenant.branding.update') }}" enctype="multipart/form-data" class="brand-section">
  @csrf @method('PATCH')
  <input type="hidden" name="tab" value="appearance">

  <div class="ia-card" style="margin-bottom:20px">
    <div class="ia-card-head"><span class="ia-card-title">Shop identity</span></div>
    <div class="ia-form-group">
      <label class="ia-form-label">Shop name <span class="ia-required">*</span></label>
      <input type="text" name="name" class="ia-input" value="{{ old('name', $currentTenant->name) }}" required>
    </div>
    <div class="ia-form-group">
      <label class="ia-form-label">Tagline</label>
      <input type="text" name="tagline" class="ia-input" value="{{ old('tagline', $currentTenant->tagline) }}"
        placeholder="e.g. Expert bike service since 2010">
    </div>
  </div>

  <div class="ia-card" style="margin-bottom:20px">
    <div class="ia-card-head"><span class="ia-card-title">Logos</span></div>
    <p style="font-size:13px;opacity:.5;margin-bottom:16px">
      Upload two versions of your logo. The system automatically picks the right one based on the background color.
    </p>
    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label">Default logo <span style="opacity:.4;font-weight:400">(for light backgrounds)</span></label>
        @if($currentTenant->logo_url)
          <img src="{{ $currentTenant->logo_url }}" alt="Logo" class="logo-preview">
        @endif
        <input type="file" name="logo" accept="image/*" class="ia-input" style="padding:6px">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Light logo <span style="opacity:.4;font-weight:400">(for dark backgrounds)</span></label>
        @if($currentTenant->logo_light_url)
          <div class="logo-preview-dark">
            <img src="{{ $currentTenant->logo_light_url }}" alt="Light logo">
          </div>
        @endif
        <input type="file" name="logo_light" accept="image/*" class="ia-input" style="padding:6px">
        <div style="font-size:11px;opacity:.35;margin-top:4px">White or light-colored version for use on dark hero sections and dark theme booking forms.</div>
      </div>
    </div>
    <div class="ia-form-group" style="margin-top:12px">
      <label class="ia-form-label">Favicon</label>
      @if($currentTenant->favicon_url)
        <img src="{{ $currentTenant->favicon_url }}" alt="Favicon" style="height:32px;border-radius:4px;margin-bottom:8px;display:block">
      @endif
      <input type="file" name="favicon" accept="image/*" class="ia-input" style="padding:6px;max-width:300px">
    </div>
  </div>

  <div class="ia-card" style="margin-bottom:20px">
    <div class="ia-card-head"><span class="ia-card-title">Colors</span></div>
    @foreach([
      ['accent_color', 'Accent color', $currentTenant->accent_color ?? '#BEF264', 'Used for buttons, links, and active states'],
      ['text_color',   'Text color',   $currentTenant->text_color   ?? '#111111', 'Main body text on your booking form'],
      ['bg_color',     'Background',   $currentTenant->bg_color     ?? '#ffffff', 'Page background on your booking form'],
    ] as [$name, $label, $value, $hint])
    <div class="ia-form-group">
      <label class="ia-form-label">{{ $label }}</label>
      <div class="color-swatch-row">
        <div class="color-swatch">
          <input type="color" name="{{ $name }}" value="{{ old($name, $value) }}" id="color-{{ $name }}">
        </div>
        <input type="text" class="ia-input" style="width:110px;font-family:var(--ia-font-mono);font-size:13px"
          value="{{ old($name, $value) }}" id="text-{{ $name }}"
          oninput="document.getElementById('color-{{ $name }}').value=this.value"
          pattern="^#[0-9A-Fa-f]{6}$">
        <span style="font-size:12px;opacity:.45">{{ $hint }}</span>
      </div>
    </div>
    @endforeach
  </div>

  <div class="ia-card" style="margin-bottom:20px">
    <div class="ia-card-head"><span class="ia-card-title">Typography</span></div>
    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label">Heading font</label>
        <select name="font_heading" class="ia-input">
          @foreach($fonts as $font)
            <option value="{{ $font }}" @selected(old('font_heading', $currentTenant->font_heading) === $font)>{{ $font }}</option>
          @endforeach
        </select>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Body font</label>
        <select name="font_body" class="ia-input">
          @foreach($fonts as $font)
            <option value="{{ $font }}" @selected(old('font_body', $currentTenant->font_body) === $font)>{{ $font }}</option>
          @endforeach
        </select>
      </div>
    </div>
  </div>

  <div class="ia-card" style="margin-bottom:24px">
    <div class="ia-card-head"><span class="ia-card-title">Admin theme</span></div>
    <div class="brand-theme-grid">
      @foreach([
        ['a', 'Sidebar + light', 'preview-a-side', 'preview-a-main', true],
        ['b', 'Top nav + airy',  'preview-b-wrap',  null,             false],
        ['c', 'Dark premium',    'preview-c-side',  'preview-c-main', false],
      ] as [$val, $label, $class1, $class2, $desktopOnly])
      <label class="brand-theme-card {{ $adminThemeC === $val ? 'selected' : '' }} {{ $desktopOnly ? 'brand-theme-card--desktop-only' : '' }}" id="theme-card-{{ $val }}">
        <input type="radio" name="admin_theme" value="{{ $val }}"
          {{ $adminThemeC === $val ? 'checked' : '' }}
          onchange="document.querySelectorAll('.brand-theme-card').forEach(c=>c.classList.remove('selected'));document.getElementById('theme-card-{{ $val }}').classList.add('selected')">
        <div class="brand-theme-preview">
          @if($val === 'b')
            <div class="preview-b-wrap">
              <div class="preview-b-top"></div>
              <div class="preview-b-main"></div>
            </div>
          @else
            <div class="{{ $class1 }}"></div>
            <div class="{{ $class2 }}"></div>
          @endif
        </div>
        <div class="brand-theme-label">
          {{ $label }}
          @if($desktopOnly)
            <span class="brand-theme-desktop-tag">Desktop only</span>
          @endif
        </div>
      </label>
      @endforeach
    </div>
  </div>

  <button type="submit" class="ia-btn ia-btn--primary">Save appearance</button>
</form>
@endif

@if($activeTab === 'email')
<form method="POST" action="{{ route('tenant.branding.update') }}" class="brand-section">
  @csrf @method('PATCH')
  <input type="hidden" name="tab" value="email">

  <div class="ia-card" style="margin-bottom:20px">
    <div class="ia-card-head"><span class="ia-card-title">Sender details</span></div>
    <p style="font-size:13px;opacity:.5;margin-bottom:16px">
      All emails to your customers will be sent from these details.
    </p>
    <div class="ia-form-group">
      <label class="ia-form-label">From name</label>
      <input type="text" name="email_from_name" class="ia-input"
        value="{{ old('email_from_name', $currentTenant->email_from_name) }}"
        placeholder="{{ $currentTenant->name }}">
    </div>
    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label">From email address</label>
        <input type="email" name="email_from_address" class="ia-input"
          value="{{ old('email_from_address', $currentTenant->email_from_address) }}"
          placeholder="{{ $currentTenant->subdomain }}@intake.works">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Reply-to</label>
        <input type="email" name="email_reply_to" class="ia-input"
          value="{{ old('email_reply_to', $currentTenant->email_reply_to) }}"
          placeholder="Optional">
      </div>
    </div>
  </div>

  <div class="ia-card" style="margin-bottom:24px">
    <div class="ia-card-head"><span class="ia-card-title">Notifications</span></div>
    <div class="ia-form-group">
      <label class="ia-form-label">New booking notification email</label>
      <input type="email" name="notification_email" class="ia-input"
        value="{{ old('notification_email', $currentTenant->notification_email) }}"
        placeholder="Where to send new booking alerts">
    </div>
  </div>

  <button type="submit" class="ia-btn ia-btn--primary">Save email settings</button>
</form>
@endif

@endsection

@push('scripts')
<script>
document.querySelectorAll('input[type=color]').forEach(function(picker) {
  var textId = picker.id.replace('color-', 'text-');
  var text   = document.getElementById(textId);
  if (text) picker.addEventListener('input', function() { text.value = picker.value; });
});
</script>
@endpush
