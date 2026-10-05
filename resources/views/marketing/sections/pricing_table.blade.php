{{--
    Pricing. Content: eyebrow, heading, subheading, source ('config'|'manual'),
                      featured (slug), plans[] (manual), footnote

    When source='config' (default), pulls plan prices from config('intake.plan_prices')
    and uses the old design's plan descriptions/features. Matches the old pricing
    card visual style exactly — dark cards, lime-accented featured plan, pill badge.
--}}
@php
    if (($c['source'] ?? 'config') === 'config') {
        $prices = config('intake.plan_prices', []);
        $plans = [
            [
                'slug'  => 'starter',
                'name'  => 'Starter',
                'price_cents' => $prices['starter'] ?? 2900,
                'period' => '/mo',
                'desc'   => 'Everything you need to start taking bookings online.',
                'features' => ['Booking form','Customer CRM','Work orders','intake.works subdomain','Stripe + PayPal'],
                'cta_label' => 'Start free trial',
            ],
            [
                'slug'  => 'branded',
                'name'  => 'Branded',
                'price_cents' => $prices['branded'] ?? 7900,
                'period' => '/mo',
                'desc'   => 'Your own domain and brand — nothing that says "Intake".',
                'features' => ['Everything in Starter','Custom domain','Remove Intake branding','Priority support','Email campaigns'],
                'cta_label' => 'Start free trial',
            ],
            [
                'slug'  => 'scale',
                'name'  => 'Scale',
                'price_cents' => $prices['scale'] ?? 19900,
                'period' => '/mo',
                'desc'   => 'Multi-location, full white-label, and advanced automations.',
                'features' => ['Everything in Branded','Multi-location','Full white-label','Dedicated support','Advanced automations'],
                'cta_label' => 'Start free trial',
            ],
        ];
    } else {
        $plans = $c['plans'] ?? [];
    }
    $featured = $c['featured'] ?? 'branded';
    // MARKER-PRICING-PHONE-TABS
    $ptTabs   = ($c['phone_layout'] ?? 'tabs') !== 'stack';
    $ptId     = 'mkpt-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10);
    $ptActive = 0;
    foreach (array_values($plans) as $ptI => $ptP) { if (($ptP['slug'] ?? null) === $featured) { $ptActive = $ptI; break; } }
@endphp

<style>
    .mk-plan-grid {
        display: grid;
        grid-template-columns: repeat({{ count($plans) }}, 1fr);
        gap: 14px;
    }
    .mk-plan {
        background: rgba(255,255,255,.03);
        border: 0.5px solid var(--mk-border);
        border-radius: var(--mk-r-lg);
        padding: 24px;
        display: flex; flex-direction: column;
    }
    .mk-plan.featured {
        border-color: rgba(190,242,100,.4);
        background: rgba(190,242,100,.03);
    }
    .mk-plan-badge {
        font-size: 10px; text-transform: uppercase; letter-spacing: .08em;
        background: rgba(190,242,100,.12);
        color: var(--mk-accent);
        padding: 3px 10px;
        border-radius: 4px;
        display: inline-block;
        margin-bottom: 12px;
        font-weight: 600;
        width: fit-content;
    }
    .mk-plan-name { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
    .mk-plan-price {
        font-size: 32px; font-weight: 800;
        letter-spacing: -.02em; margin-bottom: 4px;
        line-height: 1;
    }
    .mk-plan-price sup { font-size: 18px; font-weight: 600; vertical-align: top; margin-top: 6px; display: inline-block; }
    .mk-plan-price span { font-size: 14px; font-weight: 400; color: var(--mk-muted); }
    .mk-plan-desc {
        font-size: 13px; color: var(--mk-muted);
        margin: 10px 0 18px;
        padding-bottom: 18px;
        border-bottom: 0.5px solid var(--mk-border);
        line-height: 1.55;
    }
    .mk-plan-feats { display: flex; flex-direction: column; gap: 8px; flex: 1; }
    .mk-plan-feat {
        font-size: 13px; color: rgba(255,255,255,.6);
        display: flex; align-items: flex-start; gap: 8px;
        line-height: 1.4;
    }
    .mk-check {
        width: 14px; height: 14px;
        border-radius: 50%;
        background: var(--mk-accent-dim);
        border: 0.5px solid rgba(190,242,100,.3);
        flex-shrink: 0;
        margin-top: 1px;
        display: flex; align-items: center; justify-content: center;
    }
    .mk-check::after {
        content: '';
        width: 5px; height: 5px;
        border-radius: 50%;
        background: var(--mk-accent);
        opacity: .7;
    }
    .mk-plan-btn {
        margin-top: 20px;
        display: block; text-align: center;
        padding: 11px;
        border-radius: var(--mk-r);
        font-size: 14px; font-weight: 600;
        border: 0.5px solid var(--mk-border2);
        color: var(--mk-muted);
        transition: all .15s;
    }
    .mk-plan-btn:hover { border-color: rgba(255,255,255,.25); color: var(--mk-text); }
    .mk-plan.featured .mk-plan-btn {
        background: var(--mk-accent);
        color: var(--mk-accent-text);
        border-color: var(--mk-accent);
    }
    .mk-plan.featured .mk-plan-btn:hover { filter: brightness(.92); }

    @media(max-width: 860px) { .mk-plan-grid { grid-template-columns: 1fr; } }
    /* MARKER-PRICING-PHONE-TABS */
    .{{ $ptId }} .mk-plan-tabs { display: none; }
    @media (max-width: 860px) {
        .{{ $ptId }}.pt-tabs .mk-plan-tabs { display: flex; background: rgba(255,255,255,.06); border-radius: 999px; padding: 4px; gap: 2px; margin: 4px 0 22px; }
        .{{ $ptId }} .mk-plan-tabs button { flex: 1; border: 0; background: none; color: var(--mk-muted); font: inherit; font-size: 13px; font-weight: 600; padding: 9px 0; border-radius: 999px; cursor: pointer; transition: background .2s, color .2s; }
        .{{ $ptId }} .mk-plan-tabs button.on { background: var(--mk-accent); color: var(--mk-accent-text, #0a0a0a); }
        .{{ $ptId }}.pt-tabs .mk-plan:not(.is-active) { display: none; }
        .{{ $ptId }}.pt-tabs .mk-plan.is-active { animation: mkPtIn .3s ease; }
        @keyframes mkPtIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
    }
</style>

<section class="mk-section {{ $ptId }}{{ $ptTabs ? ' pt-tabs' : '' }}">
    <div class="mk-container">
        @if(!empty($c['eyebrow']))
            <div class="mk-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif
        @if(!empty($c['heading']))
            <h2 class="mk-section-title">{{ $c['heading'] }}</h2>
        @endif
        @if(!empty($c['subheading']))
            <p class="mk-section-sub">{{ $c['subheading'] }}</p>
        @endif

        @if($ptTabs && count($plans) > 1)
            <div class="mk-plan-tabs" role="tablist" aria-label="Plans">
                @foreach(array_values($plans) as $ptI => $ptP)
                    <button type="button" role="tab" data-i="{{ $ptI }}" class="{{ $ptI === $ptActive ? 'on' : '' }}" aria-selected="{{ $ptI === $ptActive ? 'true' : 'false' }}">{{ $ptP['name'] ?? ('Plan ' . ($ptI + 1)) }}</button>
                @endforeach
            </div>
        @endif
        <div class="mk-plan-grid">
            @foreach($plans as $plan)
                @php $isFeatured = ($plan['slug'] ?? null) === $featured; @endphp
                <div class="mk-plan {{ $isFeatured ? 'featured' : '' }}{{ $loop->index === $ptActive ? ' is-active' : '' }}">
                    @if($isFeatured)
                        <div class="mk-plan-badge">Most popular</div>
                    @endif
                    <div class="mk-plan-name">{{ $plan['name'] ?? '' }}</div>
                    <div class="mk-plan-price">
                        <sup>$</sup>{{ number_format(($plan['price_cents'] ?? 0) / 100, 0) }}<span>{{ $plan['period'] ?? '/mo' }}</span>
                    </div>
                    @if(!empty($plan['desc']))
                        <p class="mk-plan-desc">{{ $plan['desc'] }}</p>
                    @endif
                    <div class="mk-plan-feats">
                        @foreach(($plan['features'] ?? []) as $feat)
                            <div class="mk-plan-feat">
                                <div class="mk-check"></div>
                                {{ $feat }}
                            </div>
                        @endforeach
                    </div>
                    <a href="{{ $plan['cta_url'] ?? route('platform.signup') . '?plan=' . ($plan['slug'] ?? '') }}" class="mk-plan-btn">
                        {{ $plan['cta_label'] ?? 'Get started' }}
                    </a>
                </div>
            @endforeach
        </div>

        @if(!empty($c['footnote']))
            <p style="font-size:13px;color:var(--mk-dim);margin-top:16px">{{ $c['footnote'] }}</p>
        @endif
    </div>
</section>
<script>
/* MARKER-PRICING-PHONE-TABS — one plan at a time on phones */
(function () {
  var root = document.querySelector('.{{ $ptId }}');
  if (!root || root.dataset.ptReady) return;
  root.dataset.ptReady = '1';
  var tabs = root.querySelectorAll('.mk-plan-tabs button'), cards = root.querySelectorAll('.mk-plan');
  tabs.forEach(function (b) {
    b.addEventListener('click', function () {
      var i = +b.dataset.i;
      tabs.forEach(function (t, k) { t.classList.toggle('on', k === i); t.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
      cards.forEach(function (c, k) { c.classList.toggle('is-active', k === i); });
    });
  });
})();
</script>
