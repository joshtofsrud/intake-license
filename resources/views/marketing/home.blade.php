@extends('marketing.layout')
@section('title', 'Intake — Online booking for service shops')

@push('styles')
<style>
.mk-hero{padding:clamp(64px,10vw,120px) 0 clamp(48px,7vw,88px);text-align:center;border-bottom:0.5px solid var(--mk-border)}
.mk-hero h1{font-size:clamp(36px,6vw,72px);font-weight:800;letter-spacing:-.03em;line-height:1.04;margin-bottom:20px;max-width:720px;margin-left:auto;margin-right:auto}
.mk-hero h1 em{font-style:normal;color:var(--mk-accent)}
.mk-hero-sub{font-size:clamp(15px,2vw,19px);color:var(--mk-muted);max-width:500px;margin:0 auto 32px;line-height:1.65}
.mk-hero-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:14px}
.mk-hero-note{font-size:12px;color:var(--mk-dim)}
.mk-proof{padding:18px 0;border-bottom:0.5px solid var(--mk-border)}
.mk-proof-inner{display:flex;align-items:center;gap:24px;flex-wrap:wrap}
.mk-proof-label{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--mk-dim);white-space:nowrap}
.mk-proof-shops{display:flex;gap:20px;flex-wrap:wrap}
.mk-proof-shop{font-size:13px;color:rgba(255,255,255,.3);display:flex;align-items:center;gap:6px}
.mk-proof-shop::before{content:'';width:5px;height:5px;border-radius:50%;background:var(--mk-accent);opacity:.5;flex-shrink:0}
.mk-feat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.mk-feat-card{background:rgba(255,255,255,.03);border:0.5px solid var(--mk-border);border-radius:var(--mk-r-lg);padding:22px;transition:border-color .15s}
.mk-feat-card:hover{border-color:rgba(255,255,255,.16)}
.mk-feat-icon{width:36px;height:36px;background:var(--mk-accent-dim);border-radius:8px;display:flex;align-items:center;justify-content:center;margin-bottom:14px}
.mk-feat-icon svg{width:18px;height:18px;stroke:var(--mk-accent);fill:none;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round}
.mk-feat-title{font-size:14px;font-weight:600;margin-bottom:6px}
.mk-feat-desc{font-size:13px;color:var(--mk-muted);line-height:1.6}
.mk-how-steps{display:grid;grid-template-columns:repeat(4,1fr);gap:0;position:relative;margin-top:8px}
.mk-how-steps::before{content:'';position:absolute;top:20px;left:calc(12.5% + 20px);right:calc(12.5% + 20px);height:0.5px;background:rgba(255,255,255,.08);z-index:0}
.mk-how-step{text-align:center;padding:0 12px}
.mk-how-num{width:40px;height:40px;border-radius:50%;border:0.5px solid var(--mk-border2);display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:14px;font-weight:600;background:var(--mk-bg);position:relative;z-index:1;color:var(--mk-muted);transition:all .2s}
.mk-how-step.done .mk-how-num{background:var(--mk-accent);color:var(--mk-accent-text);border-color:var(--mk-accent)}
.mk-how-title{font-size:14px;font-weight:600;margin-bottom:5px}
.mk-how-desc{font-size:12px;color:var(--mk-muted);line-height:1.55}
.mk-plan-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.mk-plan{background:rgba(255,255,255,.03);border:0.5px solid var(--mk-border);border-radius:var(--mk-r-lg);padding:24px;display:flex;flex-direction:column}
.mk-plan.featured{border-color:rgba(190,242,100,.4);background:rgba(190,242,100,.03)}
.mk-plan-badge{font-size:10px;text-transform:uppercase;letter-spacing:.08em;background:rgba(190,242,100,.12);color:var(--mk-accent);padding:3px 10px;border-radius:4px;display:inline-block;margin-bottom:12px;font-weight:600;width:fit-content}
.mk-plan-name{font-size:14px;font-weight:600;margin-bottom:4px}
.mk-plan-price{font-size:32px;font-weight:800;letter-spacing:-.02em;margin-bottom:4px;line-height:1}
.mk-plan-price sup{font-size:18px;font-weight:600;vertical-align:top;margin-top:6px;display:inline-block}
.mk-plan-price span{font-size:14px;font-weight:400;color:var(--mk-muted)}
.mk-plan-desc{font-size:13px;color:var(--mk-muted);margin:10px 0 18px;padding-bottom:18px;border-bottom:0.5px solid var(--mk-border);line-height:1.55}
.mk-plan-feats{display:flex;flex-direction:column;gap:8px;flex:1}
.mk-plan-feat{font-size:13px;color:rgba(255,255,255,.6);display:flex;align-items:flex-start;gap:8px;line-height:1.4}
.mk-check{width:14px;height:14px;border-radius:50%;background:var(--mk-accent-dim);border:0.5px solid rgba(190,242,100,.3);flex-shrink:0;margin-top:1px;display:flex;align-items:center;justify-content:center}
.mk-check::after{content:'';width:5px;height:5px;border-radius:50%;background:var(--mk-accent);opacity:.7}
.mk-plan-btn{margin-top:20px;display:block;text-align:center;padding:11px;border-radius:var(--mk-r);font-size:14px;font-weight:600;border:0.5px solid var(--mk-border2);color:var(--mk-muted);transition:all .15s}
.mk-plan-btn:hover{border-color:rgba(255,255,255,.25);color:var(--mk-text)}
.mk-plan.featured .mk-plan-btn{background:var(--mk-accent);color:var(--mk-accent-text);border-color:var(--mk-accent)}
.mk-plan.featured .mk-plan-btn:hover{filter:brightness(.92)}
.mk-cta-strip{padding:clamp(48px,7vw,88px) 0;text-align:center}
.mk-cta-h2{font-size:clamp(26px,4vw,48px);font-weight:800;letter-spacing:-.03em;margin-bottom:10px}
.mk-cta-sub{font-size:16px;color:var(--mk-muted);margin-bottom:28px}
@media(max-width:860px){.mk-feat-grid{grid-template-columns:1fr 1fr}.mk-plan-grid{grid-template-columns:1fr}.mk-how-steps{grid-template-columns:1fr 1fr;gap:24px}.mk-how-steps::before{display:none}}
@media(max-width:560px){.mk-feat-grid{grid-template-columns:1fr}}
</style>
@endpush

@section('content')

{{-- Hero --}}
<section class="mk-hero">
  <div class="mk-container">
    <div class="mk-eyebrow">Built for service businesses</div>
    <h1>Online booking for <em>bike shops, ski shops,</em> and beyond</h1>
    <p class="mk-hero-sub">
      Your own branded booking page, customer management, and work orders —
      live in under 10 minutes.
    </p>
    <div class="mk-hero-actions">
      <a href="{{ route('platform.signup') }}" class="mk-btn mk-btn--primary">
        Start free trial →
      </a>
      <a href="{{ route('marketing.features') }}" class="mk-btn mk-btn--ghost">
        See all features
      </a>
    </div>
    <p class="mk-hero-note">Free 14-day trial · No credit card required</p>
  </div>
</section>

{{-- Social proof --}}
<div class="mk-proof">
  <div class="mk-container">
    <div class="mk-proof-inner">
      <span class="mk-proof-label">Trusted by shops like</span>
      <div class="mk-proof-shops">
        @foreach(['Spokes Cycle Works', 'Peak Ski + Board', 'Ridgeline Outdoor Co.', 'Coastal Bike Lab'] as $shop)
          <span class="mk-proof-shop">{{ $shop }}</span>
        @endforeach
      </div>
    </div>
  </div>
</div>

{{-- Features --}}
<section class="mk-section">
  <div class="mk-container">
    <div class="mk-eyebrow">Everything included</div>
    <h2 class="mk-section-title">One platform, zero chaos</h2>
    <div class="mk-feat-grid">
      @php
        $features = [
          ['icon' => '<rect x="2" y="3" width="12" height="9" rx="1.5"/><path d="M5 3V1M11 3V1M2 6.5h12"/>', 'title' => 'Online booking form', 'desc' => 'Multi-step form with service selection, calendar, and integrated payment.'],
          ['icon' => '<circle cx="8" cy="5.5" r="3"/><path d="M2 14.5c0-3.5 2.7-5.5 6-5.5s6 2 6 5.5"/>', 'title' => 'Customer CRM', 'desc' => "Every customer's history, lifetime spend, and notes in one place."],
          ['icon' => '<path d="M2 4h12M4 8h8M6 12h4"/>', 'title' => 'Work order management', 'desc' => 'Track every job from intake through pickup with status, charges, and notes.'],
          ['icon' => '<circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/>', 'title' => 'Capacity management', 'desc' => 'Set daily booking limits and block out holidays with date overrides.'],
          ['icon' => '<rect x="1.5" y="1.5" width="13" height="13" rx="2"/><path d="M8 5v6M5 8h6"/>', 'title' => 'Stripe + PayPal', 'desc' => 'Collect deposits or full payment at booking. Funds go straight to you.'],
          ['icon' => '<rect x="2" y="2" width="10" height="12" rx="1.5"/><path d="M5 6h6M5 9h4"/><circle cx="13" cy="13" r="3"/><path d="M12 13h2M13 12v2"/>', 'title' => 'Page builder', 'desc' => 'Build your website with drag-and-drop sections. Hero, services, gallery, and more.'],
        ];
      @endphp
      @foreach($features as $feat)
        <div class="mk-feat-card">
          <div class="mk-feat-icon">
            <svg viewBox="0 0 16 16">{!! $feat['icon'] !!}</svg>
          </div>
          <div class="mk-feat-title">{{ $feat['title'] }}</div>
          <p class="mk-feat-desc">{{ $feat['desc'] }}</p>
        </div>
      @endforeach
    </div>
    <div style="margin-top:24px">
      <a href="{{ route('marketing.features') }}" class="mk-btn mk-btn--ghost mk-btn--sm">
        See all features →
      </a>
    </div>
  </div>
</section>

{{-- How it works --}}
<section class="mk-section">
  <div class="mk-container">
    <div class="mk-eyebrow">How it works</div>
    <h2 class="mk-section-title">Up and running in minutes</h2>
    <div class="mk-how-steps">
      @foreach([
        ['done' => true,  'title' => 'Sign up',      'desc' => 'Create your account and claim your subdomain'],
        ['done' => true,  'title' => 'Add services',  'desc' => 'Build your catalog with tiers and pricing'],
        ['done' => true,  'title' => 'Customize',     'desc' => 'Your logo, colors, and website — yours in minutes'],
        ['done' => false, 'title' => 'Share & book',  'desc' => 'Send your booking link and start taking jobs'],
      ] as $i => $step)
        <div class="mk-how-step {{ $step['done'] ? 'done' : '' }}">
          <div class="mk-how-num">{{ $i + 1 }}</div>
          <div class="mk-how-title">{{ $step['title'] }}</div>
          <p class="mk-how-desc">{{ $step['desc'] }}</p>
        </div>
      @endforeach
    </div>
  </div>
</section>

{{-- Pricing --}}
<section class="mk-section">
  <div class="mk-container">
    <div class="mk-eyebrow">Pricing</div>
    <h2 class="mk-section-title">Simple plans, no surprises</h2>
    <div class="mk-plan-grid">
      @php
        $planDetails = [
          'basic'   => ['popular' => false, 'desc' => 'Everything you need to start taking bookings online.', 'features' => ['Booking form', 'Customer CRM', 'Work orders', 'intake.works subdomain', 'Stripe + PayPal']],
          'branded' => ['popular' => true,  'desc' => 'Your own domain and brand — nothing that says "Intake".', 'features' => ['Everything in Basic', 'Custom domain', 'Remove Intake branding', 'Priority support', 'Email campaigns']],
          'custom'  => ['popular' => false, 'desc' => 'Multiple locations, full white-label, and custom integrations.', 'features' => ['Everything in Branded', 'Multi-location', 'Full white-label', 'Dedicated support', 'Custom integrations']],
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
            {{ $slug === 'custom' ? 'Contact us' : 'Get started' }}
          </a>
        </div>
      @endforeach
    </div>
    <p style="font-size:13px;color:var(--mk-dim);margin-top:16px">
      All plans include a 14-day free trial. Cancel anytime.
    </p>
  </div>
</section>

{{-- CTA --}}
<section class="mk-cta-strip">
  <div class="mk-container">
    <h2 class="mk-cta-h2">Ready to fill your calendar?</h2>
    <p class="mk-cta-sub">Free 14-day trial. No credit card required.</p>
    <a href="{{ route('platform.signup') }}" class="mk-btn mk-btn--primary">
      Start your free trial →
    </a>
  </div>
</section>

@endsection
