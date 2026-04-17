{{-- Booking embed (legacy tenant section — shows a CTA to signup on marketing site) --}}
<section class="{{ $padding }}" style="text-align:center">
    <div class="mk-container" style="max-width:600px">
        @if(!empty($c['heading']))
            <h2>{{ $c['heading'] }}</h2>
        @endif
        @if(!empty($c['subheading']))
            <p style="font-size:18px">{{ $c['subheading'] }}</p>
        @endif
        <a href="https://app.intake.works/signup" class="mk-btn mk-btn--primary" style="margin-top:12px">
            {{ $c['btn_label'] ?? 'Start free trial' }}
        </a>
    </div>
</section>
