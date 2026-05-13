#!/bin/bash
# ============================================================================
# patch-61-onboarding-step1-workflow-router.sh
# ----------------------------------------------------------------------------
# Restructures onboarding step 1 from a flat 25-tile industry picker into a
# two-stage flow:
#
#   Step 1a — Workflow chooser (3 cards):
#     • Take it in, do work, give it back  (drop-off workflow)
#     • Book me a time                     (time-slot workflow)
#     • Sign me up for a class             (class workflow)
#
#   Step 1b — Industry refinement:
#     Filtered tile list showing only the industries that match the workflow
#     picked in 1a. ~7-9 tiles per workflow instead of 25.
#
# The 8-step header treats this as one logical step. Sub-step is shown via a
# small "A" or "B" badge on the current step card. Total stays 8 steps.
#
# Picking a workflow on 1a pre-fills step 3 booking defaults ONLY if the
# tenant's booking_mode is still null (fresh signup). Never overwrites.
#
#   takein   → booking_mode='drop_off',   classes_enabled=false
#   booktime → booking_mode='time_slots', classes_enabled=false
#   class    → booking_mode='time_slots', classes_enabled=true
#
# WORKFLOW MAPPING (25 industry keys → 3 workflows):
#   takein   → bike, ski, auto, tailor, shoe, electronics, jewelry, instruments, lawn (9)
#   booktime → salon, barber, massage, medspa, pet, pt, photo, art, music (9)
#   class    → yoga, pilates, crossfit, boxing, mma, hiit, lagree (7)
#
# Workflow state is stored in the SESSION, not the database. Cleared at the
# end of onboarding. No migration needed.
#
# Files touched:
#   - resources/views/tenant/onboarding/industry.blade.php  (full rewrite)
#   - app/Http/Controllers/Tenant/OnboardingWizardController.php  (showIndustry + saveIndustry)
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "resources/views/tenant/onboarding/industry.blade.php" ]; then
  echo "ERROR: industry.blade.php not found." >&2
  exit 1
fi
if [ ! -f "app/Http/Controllers/Tenant/OnboardingWizardController.php" ]; then
  echo "ERROR: OnboardingWizardController.php not found." >&2
  exit 1
fi

# ─── 1. Replace industry.blade.php ─────────────────────────────────────
if grep -q "WORKFLOW_ROUTER_V1" resources/views/tenant/onboarding/industry.blade.php; then
    echo "    SKIP industry.blade.php — already on workflow router (v1 marker found)"
else
cat > resources/views/tenant/onboarding/industry.blade.php <<'BLADE'
{{-- WORKFLOW_ROUTER_V1 --}}
{{-- 
    Onboarding step 1 — two-stage flow:
      Step 1a (default): Workflow chooser. 3 cards.
      Step 1b: Filtered industry tiles based on chosen workflow.
    
    The current sub-step is determined by:
      • request('workflow') query param present → render 1b for that workflow
      • otherwise → render 1a (or 1b if session already has a workflow)
    
    Workflow mapping is defined in the @php block and is the source of truth.
--}}
@extends('tenant.onboarding._layout')

@section('extra-styles')
  /* Force wizard colors over tenant theme injection. */
  .screen { color: #f0f0f0; }
  .screen .screen-eyebrow { color: #D4FF3F !important; }
  .screen .screen-sub { color: #888 !important; }
  .screen .btn-primary { color: #0a0a0a !important; background: #D4FF3F !important; }
  .screen .btn-skip { color: #888 !important; }

  /* ── Step 1a: workflow cards ──────────────────────────── */
  .wf-cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 24px;
  }
  @media (max-width: 900px) { .wf-cards { grid-template-columns: 1fr; } }

  .wf-card {
    background: #131313 !important;
    border: 1px solid #2a2a2a;
    border-radius: 14px;
    padding: 26px 24px;
    cursor: pointer;
    transition: all .15s;
    display: flex;
    flex-direction: column;
    text-align: left;
    color: #f0f0f0 !important;
  }
  .wf-card:hover {
    border-color: #5a5a5a;
    background: #1a1a1a !important;
    transform: translateY(-1px);
  }
  .wf-card.selected {
    border-color: #D4FF3F;
    background: linear-gradient(180deg, rgba(212,255,63,0.08), #131313) !important;
  }
  .wf-icon { font-size: 30px; margin-bottom: 18px; line-height: 1; display: block; }
  .wf-title {
    font-size: 17px; font-weight: 600; margin: 0 0 8px;
    letter-spacing: -.005em; line-height: 1.3;
    color: #f0f0f0 !important;
  }
  .wf-body {
    font-size: 13px; color: #aaa !important;
    line-height: 1.55; margin: 0 0 18px; flex: 1;
  }
  .wf-examples-label {
    font-size: 10px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .12em; color: #888 !important; margin-bottom: 6px;
  }
  .wf-examples {
    font-size: 12px; color: #aaa !important; line-height: 1.55;
  }

  /* ── Step 1b: industry refinement ─────────────────────── */
  .picked-banner {
    display: flex;
    align-items: center;
    gap: 14px;
    background: rgba(212,255,63,0.08) !important;
    border: 1px solid rgba(212,255,63,0.35);
    border-radius: 10px;
    padding: 12px 18px;
    margin-bottom: 28px;
  }
  .picked-icon { font-size: 20px; }
  .picked-text { flex: 1; font-size: 13.5px; color: #f0f0f0 !important; }
  .picked-text strong { color: #D4FF3F !important; }
  .picked-change {
    color: #aaa !important;
    font-size: 12.5px;
    cursor: pointer;
    border-bottom: 1px dashed #444;
    padding-bottom: 1px;
    background: none;
    border-top: none;
    border-left: none;
    border-right: none;
    font-family: inherit;
  }
  .picked-change:hover { color: #f0f0f0 !important; }

  .industry-grid {
    display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px;
  }
  @media (max-width: 1100px) { .industry-grid { grid-template-columns: repeat(4, 1fr); } }
  @media (max-width: 800px)  { .industry-grid { grid-template-columns: repeat(3, 1fr); } }
  @media (max-width: 600px)  { .industry-grid { grid-template-columns: repeat(2, 1fr); } }

  .industry-tile {
    background: #1a1a1a !important;
    border: 1px solid #2a2a2a;
    border-radius: 10px;
    padding: 12px 12px;
    cursor: pointer;
    transition: all 0.15s;
    text-align: left;
    color: #f0f0f0 !important;
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
@endsection

@section('screen')
  @php
    /* Workflow mapping — keep in sync with controller. */
    $industryToWorkflow = [
        'bike' => 'takein', 'ski' => 'takein', 'auto' => 'takein',
        'tailor' => 'takein', 'shoe' => 'takein', 'electronics' => 'takein',
        'jewelry' => 'takein', 'instruments' => 'takein', 'lawn' => 'takein',
        'salon' => 'booktime', 'barber' => 'booktime', 'massage' => 'booktime',
        'medspa' => 'booktime', 'pet' => 'booktime', 'pt' => 'booktime',
        'photo' => 'booktime', 'art' => 'booktime', 'music' => 'booktime',
        'yoga' => 'class', 'pilates' => 'class', 'crossfit' => 'class',
        'boxing' => 'class', 'mma' => 'class', 'hiit' => 'class', 'lagree' => 'class',
    ];

    $industryMeta = [
        'bike' => ['icon' => '🚲', 'name' => 'Bike Shop'],
        'ski' => ['icon' => '🎿', 'name' => 'Ski / Outdoor'],
        'auto' => ['icon' => '🚗', 'name' => 'Auto Detailing'],
        'tailor' => ['icon' => '🧵', 'name' => 'Tailor / Alterations'],
        'shoe' => ['icon' => '👞', 'name' => 'Shoe Repair'],
        'electronics' => ['icon' => '📱', 'name' => 'Electronics Repair'],
        'jewelry' => ['icon' => '💍', 'name' => 'Jewelry'],
        'instruments' => ['icon' => '🎸', 'name' => 'Musical Instruments'],
        'lawn' => ['icon' => '🌿', 'name' => 'Small Engine / Lawn'],
        'salon' => ['icon' => '💇', 'name' => 'Salon / Hair'],
        'barber' => ['icon' => '✂️', 'name' => 'Barbershop'],
        'massage' => ['icon' => '💆', 'name' => 'Massage Therapy'],
        'medspa' => ['icon' => '💉', 'name' => 'Med Spa'],
        'pet' => ['icon' => '🐩', 'name' => 'Pet Grooming'],
        'pt' => ['icon' => '💪', 'name' => 'Personal Trainer'],
        'photo' => ['icon' => '📷', 'name' => 'Photography'],
        'art' => ['icon' => '🎨', 'name' => 'Art / Pottery'],
        'music' => ['icon' => '🎼', 'name' => 'Music Lessons'],
        'yoga' => ['icon' => '🧘', 'name' => 'Yoga Studio'],
        'pilates' => ['icon' => '🤸', 'name' => 'Pilates Studio'],
        'crossfit' => ['icon' => '🏋️', 'name' => 'CrossFit'],
        'boxing' => ['icon' => '🥊', 'name' => 'Boxing Gym'],
        'mma' => ['icon' => '🥋', 'name' => 'MMA / Martial Arts'],
        'hiit' => ['icon' => '🔥', 'name' => 'HIIT / Group Fitness'],
        'lagree' => ['icon' => '⚡', 'name' => 'Lagree'],
    ];

    $workflowMeta = [
        'takein'   => ['icon' => '🛠', 'title' => 'Take it in, do work, give it back'],
        'booktime' => ['icon' => '💆', 'title' => 'Book me a time'],
        'class'    => ['icon' => '🧘', 'title' => 'Sign me up for a class'],
    ];

    /* Determine current sub-step from controller-injected $workflow var. */
    $currentWorkflow = $workflow ?? null;
    $isSubstepB = $currentWorkflow !== null && isset($workflowMeta[$currentWorkflow]);
  @endphp

  <div id="ob-error" class="err"></div>

  @if(!$isSubstepB)
    {{-- ═══════════════════════════════════════════════════════════════
         STEP 1a — Workflow chooser
    ═══════════════════════════════════════════════════════════════ --}}
    <div class="screen-header">
      <div class="screen-eyebrow">Step 1a of 8 · Business type</div>
      <h2 class="screen-title">How does your business work?</h2>
      <p class="screen-sub">This sets up the right kind of bookings and workflow for you. We'll narrow to your specific industry next.</p>
    </div>

    <div class="wf-cards">
      <button type="button" class="wf-card" data-workflow="takein">
        <span class="wf-icon">🛠</span>
        <h3 class="wf-title">Take it in, do work, give it back</h3>
        <p class="wf-body">Customers drop off something to be serviced. You schedule the work, track progress through your queue, and notify when ready.</p>
        <div class="wf-examples-label">Common shops</div>
        <div class="wf-examples">Bike · Ski tuning · Auto detailing · Tailor · Shoe · Electronics · Jewelry · Instruments · Small engine</div>
      </button>

      <button type="button" class="wf-card" data-workflow="booktime">
        <span class="wf-icon">💆</span>
        <h3 class="wf-title">Book me a time</h3>
        <p class="wf-body">Customers book an appointment for a specific service at a specific time. You provide the service one customer at a time.</p>
        <div class="wf-examples-label">Common shops</div>
        <div class="wf-examples">Salon · Barbershop · Massage · Med spa · Pet grooming · Personal trainer · Photography · Art lessons · Music lessons</div>
      </button>

      <button type="button" class="wf-card" data-workflow="class">
        <span class="wf-icon">🧘</span>
        <h3 class="wf-title">Sign me up for a class</h3>
        <p class="wf-body">Customers register for scheduled group classes. You manage instructors, capacity, waitlists, and registrations.</p>
        <div class="wf-examples-label">Common shops</div>
        <div class="wf-examples">Yoga · Pilates · CrossFit · Boxing gym · MMA · HIIT · Lagree</div>
      </button>
    </div>

    <div class="actions">
      <span></span>
      <span style="color:#888; font-size:12px;">Pick a workflow to see matching industries</span>
    </div>

  @else
    {{-- ═══════════════════════════════════════════════════════════════
         STEP 1b — Industry refinement (filtered to chosen workflow)
    ═══════════════════════════════════════════════════════════════ --}}
    @php
      $matchingIndustries = collect($industryToWorkflow)
          ->filter(fn($wf) => $wf === $currentWorkflow)
          ->keys()
          ->map(fn($key) => array_merge(['key' => $key], $industryMeta[$key]))
          ->values();
      $wfTitle = $workflowMeta[$currentWorkflow]['title'];
      $wfIcon = $workflowMeta[$currentWorkflow]['icon'];
      $count = $matchingIndustries->count();
    @endphp

    <div class="screen-header">
      <div class="screen-eyebrow">Step 1b of 8 · Industry</div>
      <h2 class="screen-title">Pick your industry</h2>
      <p class="screen-sub">We'll pre-load your service catalog, workflow statuses, and the right help content. Pick the closest match — you can customize everything later.</p>
    </div>

    <div class="picked-banner">
      <span class="picked-icon">{{ $wfIcon }}</span>
      <span class="picked-text">You picked <strong>{{ $wfTitle }}</strong> — showing {{ $count }} industries that match this workflow.</span>
      <button type="button" class="picked-change" id="ob-change-workflow">← Change workflow</button>
    </div>

    <div class="industry-grid">
      @foreach($matchingIndustries as $item)
        <button type="button"
                class="industry-tile {{ $tenant->industry_pack === $item['key'] ? 'selected' : '' }}"
                data-industry="{{ $item['key'] }}">
          <span class="industry-tile-icon">{{ $item['icon'] }}</span>
          <div class="industry-tile-name">{{ $item['name'] }}</div>
        </button>
      @endforeach
    </div>

    <div class="actions">
      <button type="button" class="btn-skip" id="ob-back-to-workflow">← Back</button>
      <button type="button" id="ob-continue" class="btn btn-primary" disabled>Continue → Identity</button>
    </div>

  @endif
@endsection

@section('scripts')
<script>
(function () {
  const SAVE_URL = @json(route('tenant.onboarding.wizard.industry.save', ['subdomain' => $tenant->subdomain]));
  const INDUSTRY_URL = @json(route('tenant.onboarding.wizard.industry', ['subdomain' => $tenant->subdomain]));
  const errorBox = document.getElementById('ob-error');
  const isSubstepB = @json($isSubstepB ?? false);

  function csrf() {
    return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  }
  function showError(msg) {
    if (!errorBox) return;
    errorBox.textContent = msg;
    errorBox.classList.add('show');
  }
  function hideError() {
    if (!errorBox) return;
    errorBox.classList.remove('show');
  }

  if (!isSubstepB) {
    // ── Step 1a — workflow chooser ──
    const wfCards = document.querySelectorAll('.wf-card');
    wfCards.forEach(card => {
      card.addEventListener('click', async () => {
        const workflow = card.dataset.workflow;
        wfCards.forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
        hideError();

        try {
          const fd = new FormData();
          fd.append('workflow', workflow);

          const res = await fetch(SAVE_URL, {
            method: 'POST',
            body: fd,
            headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
            credentials: 'same-origin',
          });
          if (!res.ok) {
            const text = await res.text();
            throw new Error(`Save failed (${res.status}). ${text.substring(0,140)}`);
          }
          const json = await res.json();
          window.location.href = json.next_url;
        } catch (e) {
          showError(e.message || 'Something went wrong. Please try again.');
          card.classList.remove('selected');
        }
      });
    });
  } else {
    // ── Step 1b — industry refinement ──
    const tiles = document.querySelectorAll('.industry-tile');
    const continueBtn = document.getElementById('ob-continue');
    const backBtn = document.getElementById('ob-back-to-workflow');
    const changeBtn = document.getElementById('ob-change-workflow');
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

    // Back/change buttons route to 1a (without workflow query param)
    [backBtn, changeBtn].forEach(btn => {
      if (!btn) return;
      btn.addEventListener('click', async () => {
        try {
          const fd = new FormData();
          fd.append('clear_workflow', '1');
          const res = await fetch(SAVE_URL, {
            method: 'POST',
            body: fd,
            headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
            credentials: 'same-origin',
          });
          // Regardless of response, navigate back to 1a.
          window.location.href = INDUSTRY_URL;
        } catch (e) {
          window.location.href = INDUSTRY_URL;
        }
      });
    });

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
          throw new Error(`Save failed (${res.status}). ${text.substring(0,140)}`);
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
  }
})();
</script>
@endsection
BLADE
echo "    REPLACED industry.blade.php — workflow router v1 installed"
fi

# ─── 2. Update OnboardingWizardController ─────────────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("app/Http/Controllers/Tenant/OnboardingWizardController.php")
s = p.read_text()

# 2a. Replace showIndustry to read session/request workflow.
old_show = """    public function showIndustry(string $subdomain): View
    {
        return $this->render('industry', 1);
    }"""

new_show = """    public function showIndustry(string $subdomain): View
    {
        $workflow = session('onboarding_workflow');
        $valid = ['takein', 'booktime', 'class'];
        if (!in_array($workflow, $valid, true)) {
            $workflow = null;
        }
        return view('tenant.onboarding.industry', [
            'currentStep' => 1,
            'totalSteps'  => self::TOTAL_STEPS,
            'tenant'      => tenant(),
            'workflow'    => $workflow,
        ]);
    }"""

if "session('onboarding_workflow')" in s and "showIndustry" in s:
    print("    SKIP showIndustry — already workflow-aware")
elif old_show not in s:
    raise SystemExit("ABORT showIndustry: anchor not found")
else:
    s = s.replace(old_show, new_show, 1)
    print("    UPDATED — showIndustry reads workflow from session")

# 2b. Replace saveIndustry to handle three payload shapes:
#     • workflow=X            → save workflow to session, return next_url=industry (1b)
#     • clear_workflow=1      → forget session workflow, return next_url=industry (1a)
#     • industry_pack=X       → existing behavior + seed booking defaults if null
old_save = """    public function saveIndustry(string $subdomain, Request $request): JsonResponse
    {
        $data = $request->validate([
            'industry_pack' => ['required', 'string', 'max:64'],
        ]);
        tenant()->update([
            'industry_pack'   => $data['industry_pack'],
            'onboarding_step' => max(2, tenant()->onboarding_step ?? 0),
        ]);
        return $this->stepResponse(1, $subdomain, 'identity');
    }"""

new_save = """    public function saveIndustry(string $subdomain, Request $request): JsonResponse
    {
        // Three payload shapes, handled in priority order.

        // (1) Clearing the workflow (back-to-1a from 1b).
        if ($request->boolean('clear_workflow')) {
            session()->forget('onboarding_workflow');
            return response()->json([
                'ok' => true,
                'next_url' => route('tenant.onboarding.wizard.industry', ['subdomain' => $subdomain]),
            ]);
        }

        // (2) Picking a workflow (1a → 1b).
        if ($request->filled('workflow')) {
            $data = $request->validate([
                'workflow' => ['required', 'in:takein,booktime,class'],
            ]);
            session(['onboarding_workflow' => $data['workflow']]);
            return response()->json([
                'ok' => true,
                'next_url' => route('tenant.onboarding.wizard.industry', ['subdomain' => $subdomain]),
            ]);
        }

        // (3) Picking an industry (1b → identity).
        $data = $request->validate([
            'industry_pack' => ['required', 'string', 'max:64'],
        ]);

        $tenant = tenant();
        $update = [
            'industry_pack'   => $data['industry_pack'],
            'onboarding_step' => max(2, $tenant->onboarding_step ?? 0),
        ];

        // Pre-fill step 3 booking defaults based on workflow — only if the
        // tenant hasn't already chosen a booking mode (fresh signup).
        $workflow = session('onboarding_workflow');
        if (is_null($tenant->booking_mode) && in_array($workflow, ['takein', 'booktime', 'class'], true)) {
            $defaults = [
                'takein'   => ['booking_mode' => 'drop_off',   'classes_enabled' => false],
                'booktime' => ['booking_mode' => 'time_slots', 'classes_enabled' => false],
                'class'    => ['booking_mode' => 'time_slots', 'classes_enabled' => true],
            ];
            $update['booking_mode']    = $defaults[$workflow]['booking_mode'];
            $update['classes_enabled'] = $defaults[$workflow]['classes_enabled'];
        }

        $tenant->update($update);

        // Clear the session workflow now that industry is locked in.
        session()->forget('onboarding_workflow');

        return $this->stepResponse(1, $subdomain, 'identity');
    }"""

if "Three payload shapes" in s:
    print("    SKIP saveIndustry — already handles workflow router")
elif old_save not in s:
    raise SystemExit("ABORT saveIndustry: anchor not found")
else:
    s = s.replace(old_save, new_save, 1)
    print("    UPDATED — saveIndustry handles workflow + clear_workflow + industry_pack")

p.write_text(s)
PYEOF

cat <<EONOTE

==> Patch 61 applied locally.

Deploy:
  mv patch-61-onboarding-step1-workflow-router.sh _patches/
  git add resources/views/tenant/onboarding/industry.blade.php \\
          app/Http/Controllers/Tenant/OnboardingWizardController.php \\
          _patches/patch-61-onboarding-step1-workflow-router.sh
  git commit -m "feat(onboarding): step 1 workflow router → industry refinement (patch 61)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify on a NEW dogfood-style tenant (one with onboarding incomplete):
  1. Hit /onboarding/wizard/industry — should show 3 workflow cards
  2. Click "Take it in, do work, give it back" — page reloads to industry tiles
     filtered to the 9 take-in industries, with a "You picked..." banner at top
  3. Click "← Change workflow" — page reloads back to the 3 workflow cards
  4. Pick "Book me a time" → see 9 booktime industries → pick Salon → Continue
  5. Land on step 2 (identity) as expected
  6. Step 3 (booking) should now show "Time slot" pre-selected as Default and
     classes toggle OFF (because workflow was 'booktime')
  7. If instead step 1a was "Sign me up for a class" → step 3 should show
     classes toggle ON

Edge cases:
  - Existing tenants who already have industry_pack set: their first visit
    to /industry shows 1a (since session is empty), but the booking_mode
    is_null check prevents overwriting their existing step 3 choice.
  - User refreshes /industry mid-flow: session preserves workflow → re-renders 1b.
  - User clears cookies between 1a and 1b: session lost → falls back to 1a.
    Industry pack is unchanged, no data corruption.

NOTE: The 'pt' (Personal Trainer) tile is in the 'booktime' workflow per the
discussion. Personal trainers who also run group classes can flip the classes
toggle on step 3 — they'll land there with classes off by default but it's
one click to enable.
EONOTE
