{{-- Services (legacy tenant section — not useful on marketing site, but included for parity) --}}
<section class="{{ $padding }}">
    <div class="mk-container" style="text-align:center">
        @if(!empty($c['heading']))
            <h2>{{ $c['heading'] }}</h2>
        @endif
        <p style="color:var(--mk-text-muted);opacity:.6;font-size:14px">
            Services section — only renders on tenant sites with a service catalog.
        </p>
    </div>
</section>
