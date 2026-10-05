{{--
    Footer — shell section, rendered once per page. Mirror of the old
    layout.blade.php footer. Not editable from the page editor.
--}}
<style>
    .mk-footer {
        padding: clamp(32px, 5vw, 64px) 0 clamp(24px, 3vw, 40px);
        border-top: 0.5px solid var(--mk-border);
    }
    .mk-footer-inner {
        display: grid;
        grid-template-columns: 1.5fr 1fr 1fr 1fr;
        gap: 40px;
        padding-bottom: 40px;
        border-bottom: 0.5px solid var(--mk-border);
        margin-bottom: 28px;
    }
    .mk-footer-brand-name {
        font-size: 15px; font-weight: 700; margin-bottom: 8px;
        display: flex; align-items: center; gap: 8px;
    }
    .mk-footer-tagline { font-size: 13px; color: var(--mk-muted); line-height: 1.6; max-width: 260px; }
    .mk-footer-col-title {
        font-size: 11px; text-transform: uppercase; letter-spacing: .08em;
        font-weight: 600; color: var(--mk-dim); margin-bottom: 12px;
    }
    .mk-footer-link {
        display: block; font-size: 13px; color: var(--mk-muted);
        margin-bottom: 8px; transition: color .12s;
    }
    .mk-footer-link:hover { color: var(--mk-text); }
    .mk-footer-bottom { display: flex; align-items: center; justify-content: space-between; }
    .mk-footer-copy { font-size: 12px; color: var(--mk-dim); }
    .mk-footer-legal { display: flex; gap: 20px; }
    .mk-footer-legal a { font-size: 12px; color: var(--mk-dim); transition: color .12s; }
    .mk-footer-legal a:hover { color: var(--mk-muted); }

    @media (max-width: 860px) { .mk-footer-inner { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 520px) {
        .mk-footer-inner { grid-template-columns: 1fr; }
        .mk-footer-bottom { flex-direction: column; gap: 10px; text-align: center; }
    }
</style>

<footer class="mk-footer">
    <div class="mk-container">
        <div class="mk-footer-inner">
            <div>
                <div class="mk-footer-brand-name">
                    {{-- MARKER-BRAND-CANON — was a letter "I", not the mark --}}
                    <img src="{{ \App\Support\Brand::url('logo') }}" alt="Intake" style="display:block;height:22px;width:auto">
                </div>
                @php
                    // MARKER-MKT-FOOTER — from Site & content › Navigation (preview may pass $menuFooter)
                    $mkFt    = \App\Support\MarketingNav::footer(isset($menuFooter) && is_array($menuFooter) ? $menuFooter : null);
                    $mkFtPgs = \App\Support\MarketingNav::pages();
                    $mkFtL   = fn ($r) => \App\Support\MarketingNav::footerLink($r, $mkFtPgs);
                @endphp
                @if($mkFt['tagline'] !== '')<p class="mk-footer-tagline">{{ $mkFt['tagline'] }}</p>@endif
            </div>
            @foreach($mkFt['columns'] as $mkCol)
                <div>
                    @if($mkCol['title'] !== '')<div class="mk-footer-col-title">{{ $mkCol['title'] }}</div>@endif
                    @foreach($mkCol['rows'] as $mkR)
                        @php $mkLk = $mkFtL($mkR); @endphp
                        @if($mkLk)
                            <a href="{{ $mkLk['url'] }}" class="mk-footer-link" @if($mkLk['quiz']) data-open-quiz @endif @if($mkLk['tab']) target="_blank" rel="noopener" @endif>{{ $mkLk['label'] }}</a>
                        @endif
                    @endforeach
                </div>
            @endforeach
        </div>
        <div class="mk-footer-bottom">
            <div class="mk-footer-copy">{{ str_replace('{year}', date('Y'), $mkFt['copyright']) }}</div>
            <div class="mk-footer-legal">
                @foreach($mkFt['legal'] as $mkR)
                    @php $mkLk = $mkFtL($mkR); @endphp
                    @if($mkLk)
                        <a href="{{ $mkLk['url'] }}" @if($mkLk['quiz']) data-open-quiz @endif @if($mkLk['tab']) target="_blank" rel="noopener" @endif>{{ $mkLk['label'] }}</a>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
</footer>
