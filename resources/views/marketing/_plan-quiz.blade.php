{{--
    Plan Quiz Modal — marketing-layout partial.

    Include once in marketing layout (e.g. inside <body> before closing tag).
    Triggered by any element with [data-open-quiz] attribute:
        <button data-open-quiz>Not sure? Take 30 seconds →</button>

    Self-contained: HTML + scoped CSS + vanilla JS state machine.
    No dependencies, no backend roundtrip per question.
    Only server call is on completion: POST /api/plan-quiz/complete.

    Scoring logic mirrors PlanQuizController::computeRecommendation() to keep
    client and server aligned (client shows, server logs — server truth wins).
--}}

<style>
  .pq-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); z-index: 9000; display: none; align-items: center; justify-content: center; padding: 20px; animation: pq-fade 0.2s ease-out; }
  .pq-overlay.is-open { display: flex; }

  @keyframes pq-fade { from { opacity: 0; } to { opacity: 1; } }
  @keyframes pq-slide { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

  .pq-modal { background: #0a0a0a; border: 1px solid #1f1f1f; border-radius: 14px; max-width: 560px; width: 100%; max-height: 90vh; overflow-y: auto; color: #fff; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }

  .pq-head { display: flex; justify-content: space-between; align-items: center; padding: 18px 24px 14px; border-bottom: 1px solid #1f1f1f; }
  .pq-head .pq-brand { display: flex; align-items: center; gap: 8px; font-weight: 500; font-size: 14px; }
  .pq-head .pq-brand .mark { width: 22px; height: 22px; background: #BEF264; color: #000; border-radius: 5px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12pt; }
  .pq-close { background: transparent; border: none; color: #8a8a87; font-size: 20px; cursor: pointer; padding: 4px 8px; border-radius: 6px; line-height: 1; }
  .pq-close:hover { background: rgba(255,255,255,0.05); color: #fff; }

  .pq-progress { padding: 12px 24px 0; }
  .pq-progress-bar { height: 3px; background: rgba(255,255,255,0.08); border-radius: 3px; overflow: hidden; }
  .pq-progress-fill { height: 100%; background: #BEF264; border-radius: 3px; transition: width 0.3s ease; }
  .pq-progress-text { font-size: 10px; color: #8a8a87; letter-spacing: 0.1em; text-transform: uppercase; margin-top: 6px; font-weight: 500; }

  .pq-body { padding: 24px; animation: pq-slide 0.25s ease-out; }
  .pq-question { font-size: 20pt; font-weight: 700; line-height: 1.2; letter-spacing: -0.02em; margin-bottom: 18px; color: #fff; }
  .pq-question .accent { color: #BEF264; }

  .pq-options { display: flex; flex-direction: column; gap: 9px; margin-bottom: 22px; }
  .pq-opt { background: #111111; border: 1px solid #262626; border-radius: 10px; padding: 14px 16px; cursor: pointer; transition: all 0.15s; font-size: 14px; color: #e5e4df; text-align: left; font-family: inherit; }
  .pq-opt:hover { background: #151515; border-color: #333; }
  .pq-opt.is-selected { background: rgba(190,242,100,0.06); border-color: #BEF264; color: #fff; }

  .pq-nav { display: flex; justify-content: space-between; align-items: center; padding: 14px 24px 20px; border-top: 1px solid #1f1f1f; }
  .pq-btn { padding: 10px 18px; border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; border: 1px solid transparent; font-family: inherit; transition: all 0.15s; }
  .pq-btn--ghost { background: transparent; color: #8a8a87; }
  .pq-btn--ghost:hover { color: #fff; background: rgba(255,255,255,0.04); }
  .pq-btn--primary { background: #BEF264; color: #000; font-weight: 700; }
  .pq-btn--primary:hover:not(:disabled) { background: #9ED856; }
  .pq-btn--primary:disabled { opacity: 0.4; cursor: not-allowed; }

  /* Result screen */
  .pq-result { padding: 28px 24px; text-align: center; animation: pq-slide 0.3s ease-out; }
  .pq-result-kicker { font-size: 10px; color: #BEF264; letter-spacing: 0.15em; text-transform: uppercase; font-weight: 700; margin-bottom: 10px; }
  .pq-result-title { font-size: 22pt; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; margin-bottom: 8px; }
  .pq-result-title .accent { color: #BEF264; }
  .pq-result-sub { color: #8a8a87; font-size: 14px; margin-bottom: 20px; }
  .pq-rec-card { background: linear-gradient(180deg, rgba(190,242,100,0.08) 0%, rgba(190,242,100,0.02) 100%); border: 1px solid #BEF264; border-radius: 12px; padding: 22px; margin-bottom: 18px; text-align: left; }
  .pq-rec-name { font-size: 24pt; font-weight: 700; letter-spacing: -0.02em; }
  .pq-rec-price { color: #8a8a87; font-size: 14px; margin-top: 3px; margin-bottom: 14px; }
  .pq-rec-price strong { color: #fff; font-weight: 600; }
  .pq-rec-reasons { list-style: none; padding: 0; font-size: 13px; color: #dcdcd7; line-height: 1.6; }
  .pq-rec-reasons li { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 5px; }
  .pq-rec-reasons li::before { content: "✓"; color: #BEF264; font-weight: 700; flex-shrink: 0; }

  .pq-alt-tiers { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 18px; }
  .pq-alt { background: #111111; border: 1px solid #262626; border-radius: 8px; padding: 12px; text-align: left; opacity: 0.7; }
  .pq-alt-name { font-size: 12px; font-weight: 600; color: #fff; margin-bottom: 3px; }
  .pq-alt-reason { font-size: 11px; color: #8a8a87; line-height: 1.4; }

  .pq-result-actions { display: flex; flex-direction: column; gap: 8px; }
  .pq-result-actions a { display: block; padding: 13px; border-radius: 8px; font-size: 14px; font-weight: 600; text-decoration: none; text-align: center; transition: all 0.15s; }
  .pq-cta-primary { background: #BEF264; color: #000; }
  .pq-cta-primary:hover { background: #9ED856; }
  .pq-cta-secondary { background: transparent; color: #8a8a87; border: 1px solid #262626; }
  .pq-cta-secondary:hover { color: #fff; border-color: #333; }

  .pq-bigger-needs { color: #666; font-size: 11px; text-align: center; margin-top: 14px; }
  .pq-bigger-needs a { color: #BEF264; text-decoration: none; }

  @media (max-width: 600px) {
    .pq-modal { max-height: 100vh; border-radius: 0; }
    .pq-question { font-size: 17pt; }
    .pq-result-title { font-size: 18pt; }
    .pq-rec-name { font-size: 20pt; }
    .pq-alt-tiers { grid-template-columns: 1fr; }
  }
</style>

<div class="pq-overlay" id="pq-overlay" role="dialog" aria-labelledby="pq-question" aria-modal="true">
  <div class="pq-modal">
    <div class="pq-head">
      <div class="pq-brand"><div class="mark">I</div> Plan finder</div>
      <button type="button" class="pq-close" id="pq-close" aria-label="Close">×</button>
    </div>

    <div class="pq-progress" id="pq-progress-wrap">
      <div class="pq-progress-bar"><div class="pq-progress-fill" id="pq-progress-fill" style="width: 20%;"></div></div>
      <div class="pq-progress-text" id="pq-progress-text">Question 1 of 5</div>
    </div>

    <div class="pq-body" id="pq-body">
      {{-- Populated by JS --}}
    </div>

    <div class="pq-nav" id="pq-nav">
      <button type="button" class="pq-btn pq-btn--ghost" id="pq-back">← Back</button>
      <button type="button" class="pq-btn pq-btn--primary" id="pq-next" disabled>Next →</button>
    </div>
  </div>
</div>

<script>
(function() {
  'use strict';

  // ---- Question definitions ----
  const QUESTIONS = [
    {
      key: 'volume',
      q: 'How many bookings a month do you expect?',
      options: [
        { value: 'lt50', label: 'Fewer than 50' },
        { value: '50to200', label: '50 to 200' },
        { value: '200plus', label: '200 or more' },
        { value: 'unsure', label: 'Not sure yet' },
      ],
    },
    {
      key: 'website',
      q: 'Do you have a website?',
      options: [
        { value: 'keeping', label: 'Yes, and I\u2019m keeping it (I just need booking)' },
        { value: 'need_one', label: 'No, I need one built' },
        { value: 'replacing', label: 'Yes, but I want to replace it' },
      ],
    },
    {
      key: 'locations',
      q: 'How many physical locations?',
      options: [
        { value: 'one', label: 'One' },
        { value: 'two_three', label: '2\u20133' },
        { value: 'four_plus', label: '4 or more' },
      ],
    },
    {
      key: 'branding',
      q: 'Does it matter if "Intake" shows on your booking page?',
      options: [
        { value: 'fine', label: 'No, doesn\u2019t bother me' },
        { value: 'prefer_hide', label: 'I\u2019d prefer to hide it' },
        { value: 'need_whitelabel', label: 'I need full white-label' },
      ],
    },
    {
      key: 'setup',
      q: 'How much help do you want getting started?',
      options: [
        { value: 'none', label: 'None, I\u2019ll figure it out' },
        { value: 'some', label: 'Some guidance would be nice' },
        { value: 'done_for_me', label: 'I want someone to do it for me' },
      ],
    },
  ];

  // ---- Tier metadata for result screen ----
  const TIERS = {
    starter: { name: 'Starter', price: '$29', monthlyPrice: 29 },
    branded: { name: 'Branded', price: '$79', monthlyPrice: 79 },
    scale:   { name: 'Scale',   price: '$199', monthlyPrice: 199 },
  };

  // ---- State ----
  let step = 0;        // 0..4 for questions, 5 for result
  let answers = {};    // keyed by question.key
  let sessionId = null;

  // ---- Session ID (persists within tab) ----
  function getSessionId() {
    try {
      let id = sessionStorage.getItem('pq_session_id');
      if (!id) {
        id = 'pq-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
        sessionStorage.setItem('pq_session_id', id);
      }
      return id;
    } catch (_) {
      return 'pq-' + Date.now().toString(36);
    }
  }

  // ---- Scoring logic (mirrors server) ----
  function computeResult() {
    const tags = ['quiz-signup']; // always applied on quiz-sourced signups

    const enterpriseSignals = [
      answers.volume === '200plus',
      answers.locations === 'two_three' || answers.locations === 'four_plus',
      answers.branding === 'need_whitelabel',
      answers.setup === 'done_for_me',
    ].filter(Boolean).length;

    // Extra classification tags
    if (answers.volume === '200plus') tags.push('high-volume');
    if (answers.locations === 'two_three' || answers.locations === 'four_plus') tags.push('multi-location');
    if (answers.setup === 'done_for_me') tags.push('needs-setup-help');

    // Recommendation
    let rec;
    const reasons = [];
    if (enterpriseSignals > 0) {
      rec = 'scale';
      tags.push('enterprise-quiz');
      if (answers.volume === '200plus') reasons.push('200+ bookings a month — Scale is built for that volume.');
      if (answers.locations === 'two_three' || answers.locations === 'four_plus') reasons.push('Multi-location support comes with Scale.');
      if (answers.branding === 'need_whitelabel') reasons.push('Full white-label is a Scale feature.');
      if (answers.setup === 'done_for_me') reasons.push('Scale includes onboarding assistance.');
    } else if (answers.branding === 'prefer_hide' || answers.website === 'replacing') {
      rec = 'branded';
      if (answers.branding === 'prefer_hide') reasons.push('Remove Intake branding — included on Branded.');
      if (answers.website === 'replacing') reasons.push('Custom domain + replaces your existing site.');
      reasons.push('Everything in Starter, plus brand control.');
    } else {
      rec = 'starter';
      reasons.push('Online booking form + customer CRM — everything you need to start.');
      reasons.push('No long-term commitment, upgrade anytime during your trial.');
      reasons.push('Fewer than 50 bookings/month? Starter is sized right.');
    }

    return { rec, tags, reasons };
  }

  // ---- DOM refs ----
  const overlay = document.getElementById('pq-overlay');
  const closeBtn = document.getElementById('pq-close');
  const body = document.getElementById('pq-body');
  const nav = document.getElementById('pq-nav');
  const backBtn = document.getElementById('pq-back');
  const nextBtn = document.getElementById('pq-next');
  const progressFill = document.getElementById('pq-progress-fill');
  const progressText = document.getElementById('pq-progress-text');
  const progressWrap = document.getElementById('pq-progress-wrap');

  // ---- Open/close ----
  function openQuiz() {
    sessionId = getSessionId();
    step = 0;
    answers = {};
    overlay.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    renderStep();
  }

  function closeQuiz() {
    overlay.classList.remove('is-open');
    document.body.style.overflow = '';
  }

  // ---- Renderers ----
  function renderStep() {
    if (step >= QUESTIONS.length) {
      renderResult();
      return;
    }

    const q = QUESTIONS[step];
    const current = answers[q.key];

    progressWrap.style.display = 'block';
    nav.style.display = 'flex';
    progressFill.style.width = ((step + 1) / QUESTIONS.length * 100) + '%';
    progressText.textContent = 'Question ' + (step + 1) + ' of ' + QUESTIONS.length;

    body.innerHTML =
      '<h2 class="pq-question" id="pq-question">' + q.q + '</h2>' +
      '<div class="pq-options">' +
        q.options.map(function(opt) {
          const isSel = current === opt.value;
          return '<button type="button" class="pq-opt' + (isSel ? ' is-selected' : '') + '" data-value="' + opt.value + '">' + opt.label + '</button>';
        }).join('') +
      '</div>';

    body.querySelectorAll('.pq-opt').forEach(function(el) {
      el.addEventListener('click', function() {
        answers[q.key] = el.dataset.value;
        body.querySelectorAll('.pq-opt').forEach(function(e) { e.classList.remove('is-selected'); });
        el.classList.add('is-selected');
        nextBtn.disabled = false;
      });
    });

    backBtn.style.visibility = step === 0 ? 'hidden' : 'visible';
    nextBtn.disabled = !answers[q.key];
    nextBtn.textContent = step === QUESTIONS.length - 1 ? 'See my plan →' : 'Next →';
  }

  function renderResult() {
    const result = computeResult();
    const tier = TIERS[result.rec];

    // Fire-and-forget server log — don't block UX on this.
    fetch('/api/plan-quiz/complete', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
      },
      body: JSON.stringify({
        session_id: sessionId,
        answers: answers,
        recommendation: result.rec,
        tags_applied: result.tags,
      }),
      credentials: 'same-origin',
    }).catch(function() { /* silent — analytics is non-blocking */ });

    progressWrap.style.display = 'none';
    nav.style.display = 'none';

    const otherTiers = Object.keys(TIERS).filter(function(k) { return k !== result.rec; });
    const otherTierReasons = {
      starter: { too_small: 'Starter maxes out around 50 bookings/month and has Intake branding.' },
      branded: { between: 'Branded is right in the middle — good if you need white-label but not multi-location.' },
      scale: { too_big: 'Scale is built for multi-location, high-volume, or white-label needs.' },
    };

    const altHtml = otherTiers.map(function(k) {
      const t = TIERS[k];
      let reason = '';
      if (result.rec === 'starter') {
        reason = k === 'branded' ? 'Adds custom domain and removes Intake branding.' : 'Built for multi-location or high-volume operations.';
      } else if (result.rec === 'branded') {
        reason = k === 'starter' ? 'Simpler, but keeps Intake branding visible.' : 'More than you need unless you have multiple locations.';
      } else {
        reason = k === 'starter' ? 'Too small for your needs.' : 'Missing multi-location and white-label.';
      }
      return '<div class="pq-alt"><div class="pq-alt-name">' + t.name + ' · ' + t.price + '/mo</div><div class="pq-alt-reason">' + reason + '</div></div>';
    }).join('');

    const reasonsHtml = result.reasons.map(function(r) { return '<li>' + r + '</li>'; }).join('');

    const signupUrl = '/signup?plan=' + result.rec +
      '&quiz_session=' + encodeURIComponent(sessionId) +
      '&quiz_tags=' + encodeURIComponent(result.tags.join(','));

    body.innerHTML =
      '<div class="pq-result">' +
        '<div class="pq-result-kicker">Your recommendation</div>' +
        '<h2 class="pq-result-title">' + tier.name + ' <span class="accent">is the fit.</span></h2>' +
        '<div class="pq-result-sub">14-day free trial. No card charged until day 15.</div>' +
        '<div class="pq-rec-card">' +
          '<div class="pq-rec-name">' + tier.name + '</div>' +
          '<div class="pq-rec-price"><strong>' + tier.price + '</strong> / month</div>' +
          '<ul class="pq-rec-reasons">' + reasonsHtml + '</ul>' +
        '</div>' +
        '<div class="pq-alt-tiers">' + altHtml + '</div>' +
        '<div class="pq-result-actions">' +
          '<a href="' + signupUrl + '" class="pq-cta-primary">Start 14-day free trial →</a>' +
          '<a href="/pricing" class="pq-cta-secondary">See full comparison</a>' +
        '</div>' +
        (result.rec === 'scale'
          ? '<div class="pq-bigger-needs">Have needs beyond this? <a href="/contact">Let\u2019s talk →</a></div>'
          : '') +
      '</div>';
  }

  // ---- Wire up ----
  closeBtn.addEventListener('click', closeQuiz);
  overlay.addEventListener('click', function(e) {
    if (e.target === overlay) closeQuiz();
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeQuiz();
  });
  backBtn.addEventListener('click', function() {
    if (step > 0) { step--; renderStep(); }
  });
  nextBtn.addEventListener('click', function() {
    step++;
    renderStep();
  });

  // ---- Trigger buttons (delegated click handler) ----
  document.addEventListener('click', function(e) {
    const trigger = e.target.closest('[data-open-quiz]');
    if (trigger) {
      e.preventDefault();
      openQuiz();
    }
  });
})();
</script>
