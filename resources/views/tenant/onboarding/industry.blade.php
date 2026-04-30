@extends('tenant.onboarding._layout')

@section('extra-styles')
  /* Belt-and-suspenders: force wizard colors over tenant theme injection. */
  .screen, .screen * { color: #f0f0f0; }
  .screen .screen-eyebrow { color: #D4FF3F; }
  .screen .screen-sub, .screen .industry-tile-meta { color: #888; }
  .industry-cat-label {
    font-size: 10px; font-weight: 700; color: #888 !important;
    text-transform: uppercase; letter-spacing: 0.08em;
    margin: 16px 0 8px; padding-bottom: 6px;
    border-bottom: 1px solid #1f1f1f;
  }
  .industry-cat-label:first-child { margin-top: 0; }
  .industry-grid {
    display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px;
  }
  @media (max-width: 1100px) {
    .industry-grid { grid-template-columns: repeat(4, 1fr); }
  }
  @media (max-width: 800px) {
    .industry-grid { grid-template-columns: repeat(3, 1fr); }
  }
  @media (max-width: 600px) {
    .industry-grid { grid-template-columns: repeat(2, 1fr); }
  }
  .industry-tile {
    background: #1a1a1a !important; border: 1px solid #2a2a2a;
    border-radius: 10px; padding: 12px 12px;
    cursor: pointer; transition: all 0.15s;
    text-align: left; color: #f0f0f0 !important;
  }
  .industry-tile:hover { border-color: #5a5a5a; transform: translateY(-1px); }
  .industry-tile.selected {
    border-color: #D4FF3F;
    background: linear-gradient(180deg, rgba(212,255,63,0.08), #1a1a1a) !important;
  }
  .industry-tile-icon { font-size: 18px; margin-bottom: 4px; display: block; }
  .industry-tile-name {
    font-size: 12px; font-weight: 700; line-height: 1.25;
    color: #f0f0f0 !important;
  }
  .industry-tile-meta {
    font-size: 10px; color: #888 !important; margin-top: 2px;
  }
@endsection

@section('screen')
  <div class="screen-header">
    <div class="screen-eyebrow">Step 1 of 8</div>
    <h2 class="screen-title">What kind of business are you running?</h2>
    <p class="screen-sub">We use this to tag your account, link the right marketing pages, and progressively show you industry-specific features (work-order fields, addons, etc.) once you're set up.</p>
  </div>

  <div id="ob-error" class="err"></div>

  @php
    $industries = [
      'Repair · Service' => [
        ['key' => 'bike',         'icon' => '🚲', 'name' => 'Bike Shop'],
        ['key' => 'ski',          'icon' => '🎿', 'name' => 'Ski / Outdoor'],
        ['key' => 'auto',         'icon' => '🚗', 'name' => 'Auto Detailing'],
        ['key' => 'tailor',       'icon' => '🧵', 'name' => 'Tailor / Alterations'],
        ['key' => 'shoe',         'icon' => '👞', 'name' => 'Shoe Repair'],
        ['key' => 'electronics',  'icon' => '📱', 'name' => 'Electronics Repair'],
        ['key' => 'jewelry',      'icon' => '💍', 'name' => 'Jewelry'],
        ['key' => 'instruments',  'icon' => '🎸', 'name' => 'Musical Instruments'],
        ['key' => 'lawn',         'icon' => '🌿', 'name' => 'Small Engine / Lawn'],
      ],
      'Wellness · Beauty' => [
        ['key' => 'salon',   'icon' => '💇', 'name' => 'Salon / Hair'],
        ['key' => 'barber',  'icon' => '✂️', 'name' => 'Barbershop'],
        ['key' => 'massage', 'icon' => '💆', 'name' => 'Massage Therapy'],
        ['key' => 'medspa',  'icon' => '💉', 'name' => 'Med Spa'],
        ['key' => 'pet',     'icon' => '🐩', 'name' => 'Pet Grooming'],
      ],
      'Fitness · Classes' => [
        ['key' => 'yoga',     'icon' => '🧘', 'name' => 'Yoga Studio'],
        ['key' => 'pilates',  'icon' => '🤸', 'name' => 'Pilates Studio'],
        ['key' => 'crossfit', 'icon' => '🏋️', 'name' => 'CrossFit'],
        ['key' => 'boxing',   'icon' => '🥊', 'name' => 'Boxing Gym'],
        ['key' => 'mma',      'icon' => '🥋', 'name' => 'MMA / Martial Arts'],
        ['key' => 'hiit',     'icon' => '🔥', 'name' => 'HIIT / Group Fitness'],
        ['key' => 'lagree',   'icon' => '⚡', 'name' => 'Lagree'],
        ['key' => 'pt',       'icon' => '💪', 'name' => 'Personal Trainer'],
      ],
      'Creative · Lessons' => [
        ['key' => 'photo', 'icon' => '📷', 'name' => 'Photography'],
        ['key' => 'art',   'icon' => '🎨', 'name' => 'Art / Pottery'],
        ['key' => 'music', 'icon' => '🎼', 'name' => 'Music Lessons'],
      ],
    ];
  @endphp

  @foreach($industries as $cat => $items)
    <div class="industry-cat-label">{{ $cat }}</div>
    <div class="industry-grid">
      @foreach($items as $item)
        <button type="button"
                class="industry-tile {{ $tenant->industry_pack === $item['key'] ? 'selected' : '' }}"
                data-industry="{{ $item['key'] }}">
          <span class="industry-tile-icon">{{ $item['icon'] }}</span>
          <div class="industry-tile-name">{{ $item['name'] }}</div>
          <div class="industry-tile-meta">{{ explode(' · ', $cat)[0] }}</div>
        </button>
      @endforeach
    </div>
  @endforeach

  <div class="actions">
    <button type="button" class="btn-skip">I don't see my industry</button>
    <button type="button" id="ob-continue" class="btn btn-primary" disabled>Continue → Identity</button>
  </div>
@endsection

@section('scripts')
<script>
(function () {
  const SAVE_URL = @json(route('tenant.onboarding.wizard.industry.save', ['subdomain' => $tenant->subdomain]));
  const tiles = document.querySelectorAll('.industry-tile');
  const continueBtn = document.getElementById('ob-continue');
  const errorBox = document.getElementById('ob-error');

  let selectedIndustry = @json($tenant->industry_pack);

  function syncContinue() {
    continueBtn.disabled = !selectedIndustry;
  }

  tiles.forEach(tile => {
    tile.addEventListener('click', () => {
      tiles.forEach(t => t.classList.remove('selected'));
      tile.classList.add('selected');
      selectedIndustry = tile.dataset.industry;
      syncContinue();
    });
  });

  function showError(msg) {
    errorBox.textContent = msg;
    errorBox.classList.add('show');
  }
  function hideError() { errorBox.classList.remove('show'); }

  function csrf() {
    return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  }

  continueBtn.addEventListener('click', async () => {
    if (!selectedIndustry) return;
    hideError();
    continueBtn.disabled = true;
    continueBtn.textContent = 'Saving…';

    try {
      const fd = new FormData();
      fd.append('industry_pack', selectedIndustry);

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
    } catch (e) {
      showError(e.message || 'Something went wrong. Please try again.');
      continueBtn.disabled = false;
      continueBtn.textContent = 'Continue → Identity';
    }
  });

  syncContinue();
})();
</script>
@endsection
