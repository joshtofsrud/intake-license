{{--
    Footer — shell section, rendered once per page. Mirror of the old
    layout.blade.php footer. Not editable from the page editor.
--}}
<style>
    .mk-footer {
        padding: clamp(32px, 5vw, 64px) 0 clamp(24px, 3vw, 40px);
    }
    .mk-footer-inner {
        display: grid;
        grid-template-columns: 1.5fr 1fr 1fr 1fr;
        gap: 40px;
        padding-bottom: 40px;
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
        .mk-footer-legal { flex-wrap: wrap; justify-content: center; gap: 8px 18px; }
    }
    /* MARKER-MKT-FOOTER-PHONE — column titles are plain titles unless the accordion is on */
    .mk-footer-col > summary { list-style: none; cursor: default; pointer-events: none; }
    .mk-footer-col > summary::-webkit-details-marker { display: none; }
    @media (max-width: 520px) {
        .mk-ft-grid .mk-footer-inner { grid-template-columns: 1fr 1fr; gap: 28px 20px; }
        .mk-ft-grid .mk-footer-inner > :first-child { grid-column: 1 / -1; }
        .mk-ft-accordion .mk-footer-inner { gap: 4px; }
        .mk-ft-accordion .mk-footer-inner > :first-child { margin-bottom: 18px; }
        .mk-ft-accordion .mk-footer-col > summary { pointer-events: auto; cursor: pointer; display: flex; justify-content: space-between; align-items: center; padding: 12px 0; margin: 0; }
        .mk-ft-accordion .mk-footer-col > summary::after { content: '+'; font-size: 18px; font-weight: 400; letter-spacing: 0; color: var(--mk-muted); transition: transform .2s; }
        .mk-ft-accordion .mk-footer-col[open] > summary::after { transform: rotate(45deg); }
        .mk-ft-accordion .mk-footer-col[open] { padding-bottom: 8px; }
    }
</style>

<footer class="mk-footer mk-ft-{{ \App\Support\MarketingNav::footer(isset($menuFooter) && is_array($menuFooter) ? $menuFooter : null)['phone'] }}">
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
                <details class="mk-footer-col" open>
                    @if($mkCol['title'] !== '')<summary class="mk-footer-col-title">{{ $mkCol['title'] }}</summary>@else<summary class="mk-footer-col-title" aria-hidden="true"></summary>@endif
                    @foreach($mkCol['rows'] as $mkR)
                        @php $mkLk = $mkFtL($mkR); @endphp
                        @if($mkLk)
                            <a href="{{ $mkLk['url'] }}" class="mk-footer-link" @if($mkLk['quiz']) data-open-quiz @endif @if($mkLk['tab']) target="_blank" rel="noopener" @endif>{{ $mkLk['label'] }}</a>
                        @endif
                    @endforeach
                </details>
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
<script>
/* MARKER-MKT-FOOTER-PHONE — accordion: closed on phones, always open on wider screens */
(function () {
  var ft = document.querySelector('footer.mk-ft-accordion');
  if (!ft) return;
  var mq = window.matchMedia('(max-width: 520px)');
  function set() { ft.querySelectorAll('details.mk-footer-col').forEach(function (d) { d.open = !mq.matches; }); }
  set();
  if (mq.addEventListener) mq.addEventListener('change', set);
})();
</script>
