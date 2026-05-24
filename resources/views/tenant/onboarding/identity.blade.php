@extends('tenant.onboarding._layout')

@section('extra-styles')
  /* Force wizard colors over tenant theme injection. */
  .screen { color: #f0f0f0; }
  .screen .screen-eyebrow { color: #D4FF3F !important; }
  .screen .screen-sub { color: #888 !important; }
  .screen .btn-primary { color: #0a0a0a !important; background: #D4FF3F !important; }
  .screen .btn-skip { color: #888 !important; }

  /* AI banner */
  .ai-banner {
    background: linear-gradient(135deg, rgba(212,255,63,0.12), rgba(212,255,63,0.04));
    border: 1px solid rgba(212,255,63,0.35);
    border-radius: 14px; padding: 20px;
    margin-bottom: 24px; cursor: pointer;
    transition: all 0.18s;
  }
  .ai-banner:hover { border-color: #D4FF3F; }
  .ai-banner.expanded { background: #1a1a1a !important; border-color: #D4FF3F; cursor: default; }
  .ai-banner-head { display: flex; align-items: flex-start; gap: 14px; }
  .ai-banner-icon {
    width: 38px; height: 38px; border-radius: 10px;
    background: #D4FF3F; color: #0a0a0a;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; font-weight: 800; flex-shrink: 0;
  }
  .ai-banner-body { flex: 1; }
  .ai-banner-title {
    font-size: 16px; font-weight: 700; letter-spacing: -0.01em;
    margin-bottom: 4px; color: #f0f0f0 !important;
  }
  .ai-banner-sub { font-size: 13px; color: #c8c8c8 !important; line-height: 1.5; }
  .ai-banner-arrow { color: #D4FF3F; font-size: 18px; font-weight: 700; margin-left: 12px; }
  .ai-banner-form { margin-top: 20px; display: none; }
  .ai-banner.expanded .ai-banner-form { display: block; }
  .ai-meta-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
  .ai-meta-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 10px; border-radius: 99px;
    background: #131313; border: 1px solid #2a2a2a;
    font-size: 11px; color: #c8c8c8 !important;
  }
  .ai-banner-actions {
    display: flex; align-items: center; gap: 10px; margin-top: 14px;
  }
  .ai-skip-link {
    background: transparent; border: none;
    color: #888 !important; font-family: inherit;
    font-size: 12px; cursor: pointer; text-decoration: underline;
  }

  /* Form layout */
  .row-2 { display: grid; grid-template-columns: 1.4fr 1fr; gap: 28px; }
  @media (max-width: 900px) { .row-2 { grid-template-columns: 1fr; } }

  .field { margin-bottom: 18px; }
  .label {
    display: block; font-size: 12px; font-weight: 600;
    color: #c8c8c8 !important; margin-bottom: 7px;
  }
  .input, .textarea {
    width: 100%; background: #1a1a1a !important;
    border: 1px solid #2a2a2a; border-radius: 10px;
    padding: 11px 14px; color: #f0f0f0 !important;
    font-family: inherit; font-size: 14px;
    transition: border-color 0.15s;
  }
  .textarea { resize: vertical; min-height: 100px; line-height: 1.55; }
  .input:focus, .textarea:focus { outline: none; border-color: #D4FF3F; }
  .helper { font-size: 11.5px; color: #888 !important; margin-top: 6px; }

  .logo-drop {
    display: flex; gap: 10px; align-items: center; padding: 14px;
    background: #1a1a1a; border: 1px dashed #2a2a2a; border-radius: 10px;
  }
  .logo-initials {
    width: 44px; height: 44px; border-radius: 6px;
    background: #D4FF3F; color: #0a0a0a;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800;
  }
  .color-row { display: flex; gap: 8px; align-items: center; }
  .color-swatch {
    width: 40px; height: 40px; border-radius: 8px;
    border: 1px solid #2a2a2a; cursor: pointer;
  }
  .color-hex {
    background: #131313 !important; padding: 8px 12px;
    border-radius: 6px; font-size: 12px;
    font-family: ui-monospace, monospace;
    color: #f0f0f0 !important;
  }

  /* Live preview */
  .preview-label {
    font-size: 9.5px; font-weight: 700; color: #5a5a5a !important;
    text-transform: uppercase; letter-spacing: 0.08em;
    margin-bottom: 10px;
    display: flex; justify-content: space-between; align-items: center;
  }
  .preview-label-tag {
    padding: 2px 6px; background: #1a1a1a;
    border-radius: 3px; color: #888 !important; font-weight: 600;
  }
  .preview-frame {
    background: #f4f4f2;
    border: 1px solid #1f1f1f; border-radius: 10px;
    padding: 18px; min-height: 360px;
    color: #111 !important;
  }
  .preview-mock {
    background: white; border-radius: 6px;
    padding: 22px 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.04);
  }
  .preview-mock-header {
    display: flex; align-items: center; gap: 10px;
    padding-bottom: 14px; border-bottom: 1px solid #e8e8e4;
    margin-bottom: 16px;
  }
  .preview-logo {
    width: 28px; height: 28px; border-radius: 4px;
    background: #111; color: white;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 13px;
  }
  .preview-shop-name { font-weight: 700; font-size: 15px; color: #111 !important; }
  .preview-tagline { font-size: 11px; color: #888 !important; margin-top: 2px; }
  .preview-step {
    display: inline-block; background: #BEF264; color: #111 !important;
    font-size: 10px; font-weight: 700; padding: 3px 8px;
    border-radius: 3px; margin-bottom: 8px;
  }
  .preview-h {
    font-size: 17px; font-weight: 800; color: #111 !important;
    margin-bottom: 4px; letter-spacing: -0.01em;
  }
  .preview-p { font-size: 11.5px; color: #666 !important; }
@endsection

@section('screen')
  <div class="screen-header">
    <div class="screen-eyebrow">Step 2 of 8</div>
    <h2 class="screen-title">Make it look like yours</h2>
    <p class="screen-sub">Your shop's name, tagline, and an optional logo. Customers see this on every email and your booking page.</p>
  </div>

  <div id="ob-error" class="err"></div>

  {{-- AI Quick Setup banner --}}
  <div class="ai-banner" id="ai-banner">
    <div class="ai-banner-head">
      <div class="ai-banner-icon">✨</div>
      <div class="ai-banner-body">
        <div class="ai-banner-title">Want a head start? Let me set up the rest for you.</div>
        <div class="ai-banner-sub">Describe your business in 2-3 sentences and I'll fill out hours, services, team, and booking style automatically. You'll review everything before it goes live.</div>
      </div>
      <div class="ai-banner-arrow" id="ai-arrow">→</div>
    </div>

    <div class="ai-banner-form" id="ai-form">
      <div class="field">
        <label class="label">Tell me about your business</label>
        <textarea class="textarea" id="ai-prompt" placeholder="e.g., I run a small bike repair shop. We do tune-ups, brake work, and drivetrain service. Tune-ups are $89-169. Open Tue-Sat 10-6."></textarea>
        <div class="helper">The more specific, the better. Include actual service names, prices, and your real hours (e.g., "Mon-Sat 8-5"). Vague descriptions get industry-typical defaults.</div>
      </div>
      <div class="ai-meta-row">
        @if($tenant->industry_pack)
          <div class="ai-meta-chip">📍 {{ ucfirst($tenant->industry_pack) }} context applied</div>
        @endif
        <div class="ai-meta-chip">🤖 Powered by Claude</div>
      </div>
      <div class="ai-banner-actions">
        <button type="button" class="btn btn-primary" id="ai-generate">✨ Generate setup</button>
        <button type="button" class="ai-skip-link" id="ai-collapse">I'll set it up manually</button>
      </div>
    </div>
  </div>

  <div class="row-2">
    <div>
      <div class="field">
        <label class="label" for="ob-name">Shop name</label>
        <input type="text" class="input" id="ob-name" value="{{ $tenant->name }}" placeholder="The Bike Hub">
        <div class="helper">Pre-filled from your account at signup. Edit if needed.</div>
      </div>

      <div class="field">
        <label class="label" for="ob-tagline">Tagline (optional)</label>
        <input type="text" class="input" id="ob-tagline" value="{{ $tenant->tagline }}" placeholder="Fast, friendly service.">
        <div class="helper">Shows under your name on your booking page.</div>
      </div>

      <div class="field">
        <label class="label">Logo (optional)</label>
        <div class="logo-drop">
          <div class="logo-initials" id="ob-logo-initials">{{ substr($tenant->name, 0, 1) }}</div>
          <div style="flex:1">
            <div style="font-size:13px;font-weight:600">Drop a PNG, JPG or SVG</div>
            <div class="helper">Or we'll generate initials for you.</div>
          </div>
          <button type="button" class="btn btn-ghost" style="padding:7px 14px;font-size:12px">Browse</button>
        </div>
      </div>

      <div class="field">
        <label class="label">Brand color</label>
        <div class="color-row">
          <input type="color" id="ob-color" class="color-swatch" value="{{ $tenant->accent_color ?: '#D4FF3F' }}">
          <code class="color-hex" id="ob-color-hex">{{ $tenant->accent_color ?: '#D4FF3F' }}</code>
        </div>
        <div class="helper">Used for buttons and accents on your booking page.</div>
      </div>
    </div>

    <div>
      <div class="preview-label">
        <span>Live preview</span>
        <span class="preview-label-tag">your booking page</span>
      </div>
      <div class="preview-frame">
        <div class="preview-mock">
          <div class="preview-mock-header">
            <div class="preview-logo" id="prev-logo">{{ substr($tenant->name, 0, 1) }}</div>
            <div>
              <div class="preview-shop-name" id="prev-name">{{ $tenant->name }}</div>
              <div class="preview-tagline" id="prev-tagline">{{ $tenant->tagline ?: 'Your tagline appears here' }}</div>
            </div>
          </div>
          <div class="preview-step">1 · Services</div>
          <div class="preview-h">What do you need?</div>
          <div class="preview-p">Pick a service to continue.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="actions">
    <a href="{{ route('tenant.onboarding.wizard.industry', []) }}" class="btn btn-ghost">← Back</a>
    <button type="button" class="btn btn-primary" id="ob-continue">Continue → Booking</button>
  </div>
@endsection

@section('scripts')
<script>
(function () {
  const SAVE_URL    = @json(route('tenant.onboarding.wizard.identity.save', []));
  const AI_URL      = @json(route('tenant.onboarding.wizard.ai-prefill', []));

  const banner   = document.getElementById('ai-banner');
  const arrow    = document.getElementById('ai-arrow');
  const aiForm   = document.getElementById('ai-form');
  const aiPrompt = document.getElementById('ai-prompt');
  const aiGen    = document.getElementById('ai-generate');
  const aiCollapse = document.getElementById('ai-collapse');

  const nameEl    = document.getElementById('ob-name');
  const taglineEl = document.getElementById('ob-tagline');
  const colorEl   = document.getElementById('ob-color');
  const colorHex  = document.getElementById('ob-color-hex');
  const initials  = document.getElementById('ob-logo-initials');

  const prevLogo    = document.getElementById('prev-logo');
  const prevName    = document.getElementById('prev-name');
  const prevTagline = document.getElementById('prev-tagline');

  const continueBtn = document.getElementById('ob-continue');
  const errorBox    = document.getElementById('ob-error');

  function csrf() {
    return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  }
  function showError(msg) {
    errorBox.textContent = msg;
    errorBox.classList.add('show');
  }
  function hideError() { errorBox.classList.remove('show'); }

  // AI banner expand/collapse
  banner.addEventListener('click', (e) => {
    if (banner.classList.contains('expanded')) return;
    banner.classList.add('expanded');
    arrow.style.display = 'none';
    setTimeout(() => aiPrompt.focus(), 50);
  });
  aiCollapse.addEventListener('click', (e) => {
    e.stopPropagation();
    banner.classList.remove('expanded');
    arrow.style.display = '';
  });

  // AI generate (stub — will return 501 until Phase 4)
  aiGen.addEventListener('click', async (e) => {
    e.stopPropagation();
    if (!aiPrompt.value.trim()) {
      showError('Tell me about your business first — even a sentence helps.');
      return;
    }
    hideError();
    aiGen.disabled = true;
    aiGen.textContent = '✨ Generating…';

    try {
      const fd = new FormData();
      fd.append('description', aiPrompt.value);
      const res = await fetch(AI_URL, {
        method: 'POST',
        body: fd,
        headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
        credentials: 'same-origin',
      });
      const json = await res.json();
      if (!res.ok) {
        showError(json.error || `AI Quick Setup failed (${res.status}).`);
      } else {
        // Phase 4 path: redirect to next_url with prefilled data already saved.
        window.location.href = json.next_url;
        return;
      }
    } catch (err) {
      showError(err.message || 'Something went wrong.');
    } finally {
      aiGen.disabled = false;
      aiGen.innerHTML = '✨ Generate setup';
    }
  });

  // Live preview wiring
  nameEl.addEventListener('input', () => {
    prevName.textContent = nameEl.value || 'Your shop name';
    const ch = (nameEl.value || '?').trim().charAt(0).toUpperCase();
    initials.textContent = ch;
    prevLogo.textContent = ch;
  });
  taglineEl.addEventListener('input', () => {
    prevTagline.textContent = taglineEl.value || 'Your tagline appears here';
  });
  colorEl.addEventListener('input', () => {
    colorHex.textContent = colorEl.value;
    initials.style.background = colorEl.value;
  });

  // Continue: save and advance
  continueBtn.addEventListener('click', async () => {
    hideError();
    continueBtn.disabled = true;
    continueBtn.textContent = 'Saving…';

    try {
      const fd = new FormData();
      fd.append('name',         nameEl.value);
      fd.append('tagline',      taglineEl.value);
      fd.append('accent_color', colorEl.value);

      const res = await fetch(SAVE_URL, {
        method: 'POST',
        body: fd,
        headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
        credentials: 'same-origin',
      });
      if (!res.ok) {
        const text = await res.text();
        throw new Error(`Save failed (${res.status}). ${text.substring(0, 140)}`);
      }
      const json = await res.json();
      window.location.href = json.next_url;
    } catch (err) {
      showError(err.message || 'Something went wrong.');
      continueBtn.disabled = false;
      continueBtn.textContent = 'Continue → Booking';
    }
  });
})();
</script>
@endsection
