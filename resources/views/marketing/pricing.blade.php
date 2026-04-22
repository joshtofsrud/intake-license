@extends('marketing.layout')
@section('title', 'Pricing — Intake')
@section('meta_description', 'Simple, transparent pricing for service shops. Start free, no credit card required.')

@push('styles')
<style>
.mk-pricing-hero{padding:clamp(48px,7vw,88px) 0 clamp(32px,5vw,56px);text-align:center;border-bottom:0.5px solid var(--mk-border)}
.mk-plan-grid-full{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:48px}
.mk-plan{background:rgba(255,255,255,.03);border:0.5px solid var(--mk-border);border-radius:var(--mk-r-lg);padding:28px;display:flex;flex-direction:column}
.mk-plan.featured{border-color:rgba(190,242,100,.4);background:rgba(190,242,100,.03)}
.mk-plan-badge{font-size:10px;text-transform:uppercase;letter-spacing:.08em;background:rgba(190,242,100,.12);color:var(--mk-accent);padding:3px 10px;border-radius:4px;display:inline-block;margin-bottom:12px;font-weight:600}
.mk-plan-name{font-size:16px;font-weight:700;margin-bottom:6px}
.mk-plan-price{font-size:38px;font-weight:800;letter-spacing:-.02em;line-height:1;margin-bottom:6px}
.mk-plan-price sup{font-size:20px;font-weight:600;vertical-align:top;margin-top:8px;display:inline-block}
.mk-plan-price span{font-size:15px;font-weight:400;color:var(--mk-muted)}
.mk-plan-desc{font-size:13px;color:var(--mk-muted);margin:12px 0 20px;padding-bottom:20px;border-bottom:0.5px solid var(--mk-border);line-height:1.6}
.mk-plan-feats{display:flex;flex-direction:column;gap:10px;flex:1;margin-bottom:20px}
.mk-plan-feat{font-size:13px;color:rgba(255,255,255,.65);display:flex;align-items:flex-start;gap:9px;line-height:1.45}
.mk-check{width:15px;height:15px;border-radius:50%;background:var(--mk-accent-dim);border:0.5px solid rgba(190,242,100,.3);flex-shrink:0;margin-top:1px;display:flex;align-items:center;justify-content:center}
.mk-check::after{content:'';width:5px;height:5px;border-radius:50%;background:var(--mk-accent);opacity:.7}
.mk-plan-btn{display:block;text-align:center;padding:12px;border-radius:var(--mk-r);font-size:14px;font-weight:600;border:0.5px solid var(--mk-border2);color:var(--mk-muted);transition:all .15s;margin-top:auto}
.mk-plan-btn:hover{border-color:rgba(255,255,255,.3);color:var(--mk-text)}
.mk-plan.featured .mk-plan-btn{background:var(--mk-accent);color:var(--mk-accent-text);border-color:var(--mk-accent)}
.mk-plan.featured .mk-plan-btn:hover{filter:brightness(.92)}
.mk-compare-table{width:100%;border-collapse:collapse;font-size:13px}
.mk-compare-table th{padding:12px 16px;text-align:left;font-weight:600;border-bottom:0.5px solid var(--mk-border);font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--mk-muted)}
.mk-compare-table th:first-child{width:40%}
.mk-compare-table td{padding:12px 16px;border-bottom:0.5px solid var(--mk-border);color:rgba(255,255,255,.65)}
.mk-compare-table td:not(:first-child){text-align:center}
.mk-compare-table tr:last-child td{border-bottom:none}
.mk-quiz-cta{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:rgba(190,242,100,.08);border:0.5px solid rgba(190,242,100,.25);color:var(--mk-accent);border-radius:999px;font-size:13px;font-weight:500;cursor:pointer;font-family:inherit;transition:all .15s}
.mk-quiz-cta:hover{background:rgba(190,242,100,.14);border-color:rgba(190,242,100,.4)}
.mk-quiz-cta-icon{font-size:12px}
.mk-compare-table .section-row td{font-weight:600;color:var(--mk-text);background:rgba(255,255,255,.03);font-size:12px;text-transform:uppercase;letter-spacing:.07em;padding:10px 16px}
.mk-tick{color:var(--mk-accent);font-size:14px}
.mk-dash{color:var(--mk-dim)}
.mk-faq-item{padding:20px 0;border-bottom:0.5px solid var(--mk-border)}
.mk-faq-item:last-child{border-bottom:none}
.mk-faq-q{font-size:15px;font-weight:600;margin-bottom:8px}
.mk-faq-a{font-size:14px;color:var(--mk-muted);line-height:1.65}
@media(max-width:780px){.mk-plan-grid-full{grid-template-columns:1fr}.mk-compare-table{display:none}}
</style>
@endpush

@section('content')

<section class="mk-pricing-hero">
  <div class="mk-container">
    <div class="mk-eyebrow">Pricing</div>
    <h1 class="mk-section-title" style="font-size:clamp(28px,5vw,52px);max-width:500px;margin:0 auto 12px">
      Simple plans, no surprises
    </h1>
    <p class="mk-section-sub" style="margin:0 auto 20px">
      Start free. Upgrade when you're ready. Cancel anytime.
    </p>
    <button type="button" data-open-quiz class="mk-quiz-cta">
      <span class="mk-quiz-cta-icon">✨</span>
      <span>Not sure which plan? Take 30 seconds →</span>
    </button>
  </div>
</section>

<section class="mk-section">
  <div class="mk-container">

    {{-- Plan cards --}}
    <div class="mk-plan-grid-full">
      @php
        $planDetails = [
          'starter' => [
            'popular' => false,
            'desc'    => 'Everything you need to start taking bookings online.',
            'features'=> ['Online booking form','Customer CRM','Work order management','intake.works subdomain','Stripe + PayPal payments','Page builder (website)','Capacity management','Email confirmations','Up to 3 team members'],
          ],
          'branded' => [
            'popular' => true,
            'desc'    => 'Your own domain and brand. Nothing that says "Intake".',
            'features'=> ['Everything in Starter','Custom domain','Remove Intake branding','Priority email support','Email campaigns','Up to 10 team members','Advanced form builder'],
          ],
          'scale'   => [
            'popular' => false,
            'desc'    => 'Multi-location, full white-label, and advanced automations.',
            'features'=> ['Everything in Branded','Multi-location support','Full white-label','Dedicated account manager','Advanced automations','Unlimited team members','SLA guarantee'],
          ],
        ];
      @endphp

      @foreach($plans as $slug => $plan)
        @php $detail = $planDetails[$slug]; @endphp
        <div class="mk-plan {{ $detail['popular'] ? 'featured' : '' }}">
          @if($detail['popular'])<div class="mk-plan-badge">Most popular</div>@endif
          <div class="mk-plan-name">{{ $plan['name'] }}</div>
          <div class="mk-plan-price">
            <sup>$</sup>{{ number_format($plan['price']) }}<span>/mo</span>
          </div>
          <p class="mk-plan-desc">{{ $detail['desc'] }}</p>
          <div class="mk-plan-feats">
            @foreach($detail['features'] as $feat)
              <div class="mk-plan-feat"><div class="mk-check"></div>{{ $feat }}</div>
            @endforeach
          </div>
          <a href="{{ route('platform.signup') }}?plan={{ $slug }}" class="mk-plan-btn">
            Start free trial
          </a>
        </div>
      @endforeach
    </div>

    {{-- Comparison table --}}
    <h3 style="font-size:16px;font-weight:600;margin-bottom:20px">Compare plans</h3>
    <div style="border:0.5px solid var(--mk-border);border-radius:var(--mk-r-lg);overflow:hidden">
      <table class="mk-compare-table">
        <thead>
          <tr>
            <th>Feature</th>
            <th>Basic</th>
            <th>Branded</th>
            <th>Custom</th>
          </tr>
        </thead>
        <tbody>
          @php
          $rows = [
            ['section' => 'Booking'],
            ['label' => 'Online booking form',      'starter' => true,  'branded' => true,  'scale' => true],
            ['label' => 'Service catalog + tiers',  'starter' => true,  'branded' => true,  'scale' => true],
            ['label' => 'Capacity management',      'starter' => true,  'branded' => true,  'scale' => true],
            ['label' => 'Custom form fields',       'starter' => true,  'branded' => true,  'scale' => true],
            ['label' => 'Email confirmations',      'starter' => true,  'branded' => true,  'scale' => true],
            ['section' => 'Payments'],
            ['label' => 'Stripe',                   'starter' => true,  'branded' => true,  'scale' => true],
            ['label' => 'PayPal',                   'starter' => true,  'branded' => true,  'scale' => true],
            ['section' => 'Website'],
            ['label' => 'Page builder',             'starter' => true,  'branded' => true,  'scale' => true],
            ['label' => 'intake.works subdomain',   'starter' => true,  'branded' => true,  'scale' => true],
            ['label' => 'Custom domain',            'starter' => false, 'branded' => true,  'scale' => true],
            ['label' => 'Remove Intake branding',   'starter' => false, 'branded' => true,  'scale' => true],
            ['section' => 'Management'],
            ['label' => 'Customer CRM',             'starter' => true,  'branded' => true,  'scale' => true],
            ['label' => 'Work order management',    'starter' => true,  'branded' => true,  'scale' => true],
            ['label' => 'Team members',             'starter' => '3',   'branded' => '10',  'scale' => 'Unlimited'],
            ['label' => 'Email campaigns',          'starter' => false, 'branded' => true,  'scale' => true],
            ['section' => 'Support'],
            ['label' => 'Email support',            'starter' => true,  'branded' => true,  'scale' => true],
            ['label' => 'Priority support',         'starter' => false, 'branded' => true,  'scale' => true],
            ['label' => 'Dedicated account manager','starter' => false, 'branded' => false, 'scale' => true],
          ];
          @endphp
          @foreach($rows as $row)
            @if(isset($row['section']))
              <tr class="section-row"><td colspan="4">{{ $row['section'] }}</td></tr>
            @else
              <tr>
                <td>{{ $row['label'] }}</td>
                @foreach(['starter','branded','scale'] as $plan)
                  <td>
                    @if($row[$plan] === true)
                      <span class="mk-tick">✓</span>
                    @elseif($row[$plan] === false)
                      <span class="mk-dash">—</span>
                    @else
                      {{ $row[$plan] }}
                    @endif
                  </td>
                @endforeach
              </tr>
            @endif
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</section>

{{-- FAQ --}}
<section class="mk-section">
  <div class="mk-container" style="max-width:680px">
    <h2 class="mk-section-title">Frequently asked questions</h2>
    @php
    $faqs = [
      ['q' => 'How does the free trial work?', 'a' => 'You get 14 days free with full access to all features on your chosen plan. No credit card required. At the end of your trial you can subscribe or your account pauses — no charges, no deletions.'],
      ['q' => 'Can I switch plans later?', 'a' => 'Yes, any time. Upgrades take effect immediately. Downgrades take effect at the end of your current billing period.'],
      ['q' => 'Do you take a cut of my bookings?', 'a' => 'No transaction fees from Intake ever. You pay your plan fee plus Stripe\'s or PayPal\'s standard processing rates (typically 2.9% + 30¢). That\'s it.'],
      ['q' => 'What happens to my data if I cancel?', 'a' => 'Your account pauses — all data is retained for 90 days. You can export everything or reactivate at any time within that window.'],
      ['q' => 'Does the WordPress plugin work with any plan?', 'a' => 'Yes. The Intake WordPress plugin connects to your account on any plan. It\'s a free download from your dashboard.'],
      ['q' => 'Can I use my own domain on Basic?', 'a' => 'Custom domains are available on the Branded plan and above. On Basic you get a yourshop.intake.works subdomain.'],
    ];
    @endphp
    @foreach($faqs as $faq)
      <div class="mk-faq-item">
        <div class="mk-faq-q">{{ $faq['q'] }}</div>
        <p class="mk-faq-a">{{ $faq['a'] }}</p>
      </div>
    @endforeach
  </div>
</section>

@endsection
