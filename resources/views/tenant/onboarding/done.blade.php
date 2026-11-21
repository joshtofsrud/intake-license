@extends('tenant.onboarding._layout')

@section('extra-styles')
  /* Force wizard colors over tenant theme injection. */
  .screen { color: #f0f0f0; }
  .screen .screen-eyebrow { color: #D4FF3F !important; }
  .screen .screen-sub { color: #888 !important; }
  .screen .btn-primary { color: #0a0a0a !important; background: #D4FF3F !important; }
  .screen .btn-skip { color: #888 !important; }

  .done-center { text-align: center; padding: 12px 0 4px; }
  .done-icon {
    width: 64px; height: 64px; border-radius: 50%;
    background: #D4FF3F; color: #0a0a0a;
    display: flex; align-items: center; justify-content: center;
    font-size: 32px; font-weight: 800;
    margin: 0 auto 22px;
  }
  .done-title {
    font-size: 32px; font-weight: 800; letter-spacing: -0.02em;
    margin-bottom: 10px; color: #f0f0f0 !important;
  }
  .done-sub {
    color: #c8c8c8 !important; font-size: 14.5px;
    max-width: 480px; margin: 0 auto 26px;
    line-height: 1.55;
  }

  .booking-url-bar {
    display: inline-flex; align-items: center; gap: 10px;
    padding: 10px 14px;
    background: #131313; border: 1px solid #D4FF3F;
    border-radius: 10px;
    font-family: ui-monospace, monospace;
    font-size: 13px; font-weight: 600;
  }
  .booking-url-bar code { color: #D4FF3F !important; background: transparent; }
  .copy-btn {
    background: #D4FF3F !important; color: #0a0a0a !important;
    border: none; border-radius: 6px;
    padding: 5px 11px; font-weight: 700; font-size: 11px;
    cursor: pointer; font-family: inherit;
    text-transform: uppercase; letter-spacing: 0.05em;
    transition: filter 0.12s;
  }
  .copy-btn:hover { filter: brightness(0.92); }
  .copy-btn.copied { background: #34D399 !important; }

  .next-grid {
    display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;
    margin-top: 28px;
  }
  @media (max-width: 800px) { .next-grid { grid-template-columns: 1fr; } }

  .next-card {
    background: #1a1a1a !important; border: 1px solid #2a2a2a;
    border-radius: 10px; padding: 18px; text-align: left;
    cursor: pointer; transition: all 0.15s;
    text-decoration: none; color: #f0f0f0 !important;
    display: block;
  }
  .next-card:hover { border-color: #D4FF3F; transform: translateY(-1px); }
  .next-card-icon { font-size: 18px; margin-bottom: 8px; display: block; }
  .next-card-title {
    font-size: 13.5px; font-weight: 700; margin-bottom: 4px;
    color: #f0f0f0 !important;
  }
  .next-card-desc {
    font-size: 11.5px; color: #888 !important; line-height: 1.5;
  }

  /* MARKER-CONNECTED-POS-SECTION */
  .pos-move-q { font-size: 15px; font-weight: 700; color: #f0f0f0 !important; margin-bottom: 4px; }
  .pos-move-sub { font-size: 12.5px; color: #888 !important; line-height: 1.55; margin-bottom: 12px; }
  .pos-move-opts { display: flex; gap: 8px; flex-wrap: wrap; }
  .pos-move-opt {
    background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 10px;
    padding: 9px 14px; color: #c8c8c8 !important; font-family: inherit; font-size: 13px;
    font-weight: 600; cursor: pointer; transition: border-color 0.15s;
  }
  .pos-move-opt:hover { border-color: #5a5a5a; }
  .pos-move-opt.on { border-color: #D4FF3F; color: #f0f0f0 !important; }
  .pos-move-note { font-size: 12.5px; color: #c8c8c8 !important; line-height: 1.55; margin-top: 12px; }
  .pos-move-note b { color: #f0f0f0 !important; }
  .summary-block.pos-move { margin-top: 16px; }

  .summary-block {
    margin-top: 28px; padding: 16px;
    background: #131313; border: 1px solid #1f1f1f;
    border-radius: 10px;
  }
  .summary-label {
    font-size: 10.5px; font-weight: 700; color: #888 !important;
    text-transform: uppercase; letter-spacing: 0.08em;
    margin-bottom: 10px;
  }
  .summary-row {
    display: flex; justify-content: space-between; gap: 12px;
    padding: 6px 0; font-size: 13px;
  }
  .summary-row .k { color: #888 !important; }
  .summary-row .v { color: #f0f0f0 !important; font-weight: 600; }
@endsection

@section('screen')
  <div id="ob-error" class="err"></div>

  @php
    $bookingUrl = $tenant->subdomain . '.intake.works/book';

    $appointmentStyle = $tenant->booking_mode === 'drop_off' ? 'Drop-off' : 'Time slot';
    $classesLabel     = $tenant->classes_enabled ? 'On' : 'Off';

    $serviceCount = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
        ->where('is_active', true)->count();

    $teamCount = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
        ->where('is_active', true)->count();

    $paymentLabel = match($tenant->payment_processor) {
        'stripe'  => 'Stripe (intent recorded)',
        'paypal'  => 'PayPal (intent recorded)',
        'square'  => 'Square (intent recorded)',
        'offline' => 'Outside the app',
        default   => 'Not selected',
    };
  @endphp

  <div class="done-center">
    <div class="done-icon">✓</div>
    <h2 class="done-title">{{ $tenant->name }} is ready.</h2>
    <p class="done-sub">Your booking page is live. Send the link to a customer, share it on your site, or send yourself a test booking to see how it feels.</p>

    <div class="booking-url-bar">
      <code id="booking-url">{{ $bookingUrl }}</code>
      <button type="button" class="copy-btn" id="copy-btn">Copy</button>
    </div>
  </div>

  <div class="summary-block">
    <div class="summary-label">Your setup</div>
    <div class="summary-row"><span class="k">Industry</span><span class="v">{{ ucfirst($tenant->industry_pack ?: '—') }}</span></div>
    <div class="summary-row"><span class="k">Appointment style</span><span class="v">{{ $appointmentStyle }}</span></div>
    <div class="summary-row"><span class="k">Group classes</span><span class="v">{{ $classesLabel }}</span></div>
    <div class="summary-row"><span class="k">Services</span><span class="v">{{ $serviceCount }} service{{ $serviceCount === 1 ? '' : 's' }}</span></div>
    <div class="summary-row"><span class="k">Team</span><span class="v">{{ $teamCount }} {{ $teamCount === 1 ? 'person' : 'people' }}</span></div>
    <div class="summary-row"><span class="k">Payments</span><span class="v">{{ $paymentLabel }}</span></div>
  </div>

  <div class="next-grid">
    <a href="https://{{ $tenant->subdomain }}.intake.works/book" target="_blank" class="next-card">
      <span class="next-card-icon">🧪</span>
      <div class="next-card-title">Send a test booking</div>
      <div class="next-card-desc">See exactly what customers see — confirmation email and all.</div>
    </a>
    <a href="{{ route('tenant.pages.index', []) }}" class="next-card">
      <span class="next-card-icon">🎨</span>
      <div class="next-card-title">Customize your booking page</div>
      <div class="next-card-desc">Tweak colors, add a hero image, change page copy.</div>
    </a>
    <a href="{{ route('tenant.calendar.index', []) }}" class="next-card">
      <span class="next-card-icon">📅</span>
      <div class="next-card-title">Open your calendar</div>
      <div class="next-card-desc">See where bookings land, set up breaks, manage capacity.</div>
    </a>
  </div>

  {{-- MARKER-CONNECTED-POS-SECTION: a shop coming from another POS gets Connected POS and the Move to Intake checklist --}}
  <div class="summary-block pos-move">
    <div class="summary-label">One more question</div>
    <div class="pos-move-q">Are you moving from another POS?</div>
    <p class="pos-move-sub">If you are, Intake can connect to it and bring over the items you choose and your customers. Your current POS keeps working until you switch over, and nothing is copied until you ask for it.</p>
    <div class="pos-move-opts" role="radiogroup" aria-label="Are you moving from another POS?">
      <button type="button" class="pos-move-opt" data-move="1" role="radio" aria-checked="false">Yes, I'm moving from another POS</button>
      <button type="button" class="pos-move-opt on" data-move="0" role="radio" aria-checked="true">No</button>
    </div>
    <p class="pos-move-note" id="pos-move-note" hidden>
      @if($canConnectedPos ?? false)
        Connected POS will be turned on for you. It's free. Finishing takes you to <b>Move to Intake</b>, where you connect your POS and bring things over step by step.
      @else
        Connected POS comes with the Branded plan and up, and you're on {{ ucfirst($planTier ?? 'starter') }}, so it stays off for now. When you upgrade, turn it on under Add-ons; it's free, and it will open on Move to Intake.
      @endif
    </p>
  </div>

  <div class="actions">
    <a href="{{ route('tenant.onboarding.wizard.payment', []) }}" class="btn btn-ghost">← Back</a>
    <button type="button" class="btn btn-primary" id="ob-finish">Go to dashboard →</button>
  </div>
@endsection

@section('scripts')
<script>
(function () {
  const COMPLETE_URL = @json(route('tenant.onboarding.wizard.complete', []));

  const copyBtn = document.getElementById('copy-btn');
  const finish  = document.getElementById('ob-finish');
  const errBox  = document.getElementById('ob-error');

  function csrf() {
    return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  }
  function showError(msg) { errBox.textContent = msg; errBox.classList.add('show'); }
  function hideError() { errBox.classList.remove('show'); }

  copyBtn.addEventListener('click', () => {
    const url = 'https://' + document.getElementById('booking-url').textContent.trim();
    navigator.clipboard.writeText(url).then(() => {
      copyBtn.textContent = 'Copied';
      copyBtn.classList.add('copied');
      setTimeout(() => {
        copyBtn.textContent = 'Copy';
        copyBtn.classList.remove('copied');
      }, 1500);
    }).catch(() => {
      showError('Could not copy. Select the URL and copy it manually.');
    });
  });

  // MARKER-CONNECTED-POS-SECTION: "Are you moving from another POS?"
  const CAN_POS = @json((bool) ($canConnectedPos ?? false));
  const moveNote = document.getElementById('pos-move-note');
  const finishLabel = () => (moving && CAN_POS) ? 'Finish and move from my POS →' : 'Go to dashboard →';
  let moving = false;
  document.querySelectorAll('.pos-move-opt').forEach((b) => {
    b.addEventListener('click', () => {
      moving = b.dataset.move === '1';
      document.querySelectorAll('.pos-move-opt').forEach((x) => {
        x.classList.toggle('on', x === b);
        x.setAttribute('aria-checked', x === b ? 'true' : 'false');
      });
      moveNote.hidden = !moving;
      finish.textContent = finishLabel();
    });
  });

  finish.addEventListener('click', async () => {
    hideError();
    finish.disabled = true;
    finish.textContent = 'Finishing…';

    try {
      const fd = new FormData();
      fd.append('moving_from_pos', moving ? '1' : '0');
      const res = await fetch(COMPLETE_URL, {
        method: 'POST',
        body: fd,
        headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
        credentials: 'same-origin',
      });
      if (!res.ok) {
        const text = await res.text();
        throw new Error('Failed to complete (' + res.status + '). ' + text.substring(0, 140));
      }
      const json = await res.json();
      window.location.href = json.redirect;
    } catch (err) {
      showError(err.message || 'Something went wrong.');
      finish.disabled = false;
      finish.textContent = finishLabel();
    }
  });
})();
</script>
@endsection
