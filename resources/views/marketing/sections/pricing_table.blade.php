{{--
    Pricing table. Content: heading, subheading, source, featured, plans

    When source='config' (default), pulls plans + prices from config('intake.plan_prices').
    When source='manual', renders $c['plans'] — each entry: name, price_cents, period, features[], cta_label, cta_url.
--}}
@php
    if (($c['source'] ?? 'config') === 'config') {
        $prices = config('intake.plan_prices', []);
        $plans = [
            ['slug'=>'basic',   'name'=>'Basic',   'price_cents'=>$prices['basic']   ?? 2900,  'period'=>'/mo', 'blurb'=>'For getting started',   'features'=>['Custom booking site','Unlimited appointments','Email notifications','Stripe & PayPal','Customer database']],
            ['slug'=>'branded', 'name'=>'Branded', 'price_cents'=>$prices['branded'] ?? 7900,  'period'=>'/mo', 'blurb'=>'For growing businesses','features'=>['Everything in Basic','Custom branding & colors','Email campaigns','Review system','SMS reminders (add-on)','Priority support']],
            ['slug'=>'custom',  'name'=>'Custom',  'price_cents'=>$prices['custom']  ?? 19900, 'period'=>'/mo', 'blurb'=>'For established shops','features'=>['Everything in Branded','Custom HTML blocks','Custom domain','API access','White-glove onboarding','Dedicated support']],
        ];
    } else {
        $plans = $c['plans'] ?? [];
    }
    $featured = $c['featured'] ?? 'branded';
@endphp

<section class="{{ $padding }}" @if(!empty($section->bg_color)) style="background:{{ $section->bg_color }}" @endif>
    <div class="mk-container">
        @if(!empty($c['heading']) || !empty($c['subheading']))
            <div style="text-align:center;margin-bottom:48px;max-width:640px;margin-left:auto;margin-right:auto">
                @if(!empty($c['heading']))      <h2>{{ $c['heading'] }}</h2>      @endif
                @if(!empty($c['subheading']))   <p style="font-size:18px">{{ $c['subheading'] }}</p>   @endif
            </div>
        @endif

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:24px;max-width:1100px;margin:0 auto">
            @foreach($plans as $plan)
                @php $isFeatured = ($plan['slug'] ?? null) === $featured; @endphp
                <div style="
                    border: 2px solid {{ $isFeatured ? 'var(--mk-accent)' : 'var(--mk-border)' }};
                    border-radius: 16px;
                    padding: 32px;
                    background: white;
                    position: relative;
                    {{ $isFeatured ? 'transform: scale(1.02); box-shadow: 0 20px 40px -12px rgba(124, 58, 237, 0.15);' : '' }}
                ">
                    @if($isFeatured)
                        <div style="position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:var(--mk-accent);color:white;padding:4px 12px;border-radius:100px;font-size:12px;font-weight:600">MOST POPULAR</div>
                    @endif

                    <h3 style="font-size:20px;margin-bottom:4px">{{ $plan['name'] }}</h3>
                    <p style="font-size:14px;margin-bottom:24px;color:var(--mk-text-muted)">{{ $plan['blurb'] ?? '' }}</p>

                    <div style="margin-bottom:32px">
                        <span style="font-size:48px;font-weight:800;letter-spacing:-.03em">${{ number_format(($plan['price_cents'] ?? 0)/100, 0) }}</span>
                        <span style="font-size:16px;color:var(--mk-text-muted)">{{ $plan['period'] ?? '/mo' }}</span>
                    </div>

                    <a href="{{ $plan['cta_url'] ?? 'https://app.intake.works/signup?plan=' . ($plan['slug'] ?? '') }}"
                       class="mk-btn {{ $isFeatured ? 'mk-btn--primary' : 'mk-btn--secondary' }}"
                       style="display:block;text-align:center;margin-bottom:24px">
                        {{ $plan['cta_label'] ?? 'Start free trial' }}
                    </a>

                    <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:10px">
                        @foreach(($plan['features'] ?? []) as $feature)
                            <li style="font-size:14px;display:flex;align-items:flex-start;gap:10px">
                                <span style="color:var(--mk-accent);flex-shrink:0">✓</span>
                                <span>{{ $feature }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </div>
</section>
