@php $bgId = 'mkbg-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10); @endphp
@include('marketing.sections._section_bg', ['bgId' => $bgId])
{{--
    Pricing, live from master admin (MARKER-LIVE-PRICING).
    Plan prices: Billing > Plan prices. Add-on prices and who gets what:
    Billing > Add-ons. Plan one-liners and the basics rows: Billing > Pricing page.
    Nothing price-related is typed into this section, so the page can't drift
    from what signup actually charges.

    Content: eyebrow, heading, accent_words, subheading, footnote,
             featured (starter|branded|scale|'' for none; default branded),
             show_matrix (adds the plan-by-plan table under the cards; off by
             default, so the home page keeps just the three cards),
             phone_layout ('tabs' = swipeable row with plan pills, 'stack').
--}}
@php
    $ptId     = 'mkpt-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10);
    $plans    = \App\Support\PricingCatalog::plans();
    $basics   = \App\Support\PricingCatalog::basics();
    $featured = array_key_exists('featured', $c) ? (string) $c['featured'] : 'branded';
    // MARKER-PRICING-SECTION-RESTORE: table is opt-in; phone swipe is back
    $showMx   = ! empty($c['show_matrix']) && ! in_array((string) $c['show_matrix'], ['0', 'false'], true);
    $hideMx   = ! $showMx;
    $groups   = $hideMx ? [] : \App\Support\PricingCatalog::groups(true);
    $ptTabs   = ($c['phone_layout'] ?? 'tabs') !== 'stack';
    $signup   = route('platform.signup');
    $money    = fn (int $cents) => number_format($cents / 100, $cents % 100 ? 2 : 0);
    $heading  = e($c['heading'] ?? '');
    $aw       = trim((string) ($c['accent_words'] ?? ''));
    if ($aw !== '' && stripos($heading, e($aw)) !== false) {
        $heading = preg_replace('/' . preg_quote(e($aw), '/') . '/i', '<span class="lp-accent">$0</span>', $heading, 1);
    }
    $hotIdx   = array_search($featured, array_column($plans, 'k'), true);
    $ptActive = $hotIdx === false ? 0 : $hotIdx;
    $check    = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>';
    $footnote = trim((string) ($c['footnote'] ?? '')) ?: 'Prices in US dollars, billed monthly. Each extra location is billed at your plan\'s price.';
@endphp
<style>
    .{{ $ptId }} .lp-head { text-align: center; max-width: 720px; margin: 0 auto 40px; }
    .{{ $ptId }} .lp-head h2 { font-size: clamp(32px, 4.6vw, 56px); font-weight: 500; letter-spacing: -.04em; line-height: 1.04; }
    .{{ $ptId }} .lp-accent { color: rgba(255,255,255,.5); }
    .{{ $ptId }} .lp-head p { color: var(--mk-muted); font-size: 17px; margin-top: 14px; }
    .{{ $ptId }} .lp-cards { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; max-width: 1080px; margin: 0 auto; }
    .{{ $ptId }} .lp-card { position: relative; display: flex; flex-direction: column; padding: 26px 24px 24px; border-radius: 18px; overflow: hidden;
        border: 1px solid rgba(255,255,255,.09); background: linear-gradient(180deg, rgba(255,255,255,.045), rgba(255,255,255,.012)); }
    .{{ $ptId }} .lp-card.hot { border-color: rgba(190,242,100,.28);
        background: radial-gradient(120% 70% at 50% 100%, rgba(190,242,100,.2), rgba(190,242,100,.04) 55%, transparent), linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.015)); }
    .{{ $ptId }} .lp-name { font-size: 15px; font-weight: 500; }
    .{{ $ptId }} .lp-price { display: flex; align-items: baseline; gap: 6px; margin: 14px 0 8px; }
    .{{ $ptId }} .lp-price b { font-size: 46px; font-weight: 500; letter-spacing: -.045em; line-height: 1; }
    .{{ $ptId }} .lp-price span { color: var(--mk-muted); font-size: 15px; }
    .{{ $ptId }} .lp-blurb { color: rgba(255,255,255,.62); font-size: 14px; line-height: 1.55; min-height: 48px; padding-bottom: 18px; border-bottom: 1px solid var(--mk-border); }
    .{{ $ptId }} .lp-list { list-style: none; margin: 16px 0 28px; padding: 0; display: grid; gap: 11px; font-size: 13.5px; }
    .{{ $ptId }} .lp-list li { display: flex; gap: 10px; align-items: flex-start; color: rgba(255,255,255,.72); line-height: 1.4; }
    .{{ $ptId }} .lp-list li svg { flex: none; width: 14px; height: 14px; margin-top: 2px; stroke: rgba(255,255,255,.55); fill: none; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
    .{{ $ptId }} .lp-list li.lead { color: rgba(255,255,255,.45); }
    .{{ $ptId }} .lp-btn { margin-top: auto; display: block; text-align: center; padding: 11px; border-radius: 10px; background: rgba(255,255,255,.1); color: #fff; font-size: 14px; font-weight: 500; transition: background .15s; }
    .{{ $ptId }} .lp-btn:hover { background: rgba(255,255,255,.16); }
    .{{ $ptId }} .lp-card.hot .lp-btn { background: #fff; color: #0a0a0a; font-weight: 600; }
    .{{ $ptId }} .lp-mx { max-width: 1080px; margin: clamp(64px, 8vw, 100px) auto 0; }
    .{{ $ptId }} .lp-mx h3 { text-align: center; font-size: clamp(26px, 3.4vw, 38px); font-weight: 500; letter-spacing: -.035em; margin-bottom: 8px; }
    .{{ $ptId }} .lp-mx > p { text-align: center; color: var(--mk-muted); font-size: 14.5px; margin-bottom: 18px; }
    .{{ $ptId }} table { width: 100%; border-collapse: collapse; font-size: 14.5px; }
    .{{ $ptId }} thead th { position: sticky; top: 0; z-index: 2; background: rgba(12,12,12,.94); backdrop-filter: blur(8px); text-align: center; font-weight: 600; padding: 26px 10px 14px; border-bottom: 1px solid var(--mk-border2); }
    .{{ $ptId }} thead th:first-child { text-align: left; }
    .{{ $ptId }} thead th small { display: block; font-weight: 500; color: var(--mk-muted); font-size: 12.5px; }
    .{{ $ptId }} tbody th { text-align: left; font-weight: 500; padding: 13px 10px 13px 0; color: rgba(255,255,255,.88); }
    .{{ $ptId }} tbody td { text-align: center; padding: 13px 10px; }
    .{{ $ptId }} tbody tr { border-bottom: 1px dotted rgba(255,255,255,.1); }
    .{{ $ptId }} tr.grp th { padding: 30px 0 10px; font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: rgba(255,255,255,.4); font-weight: 700; border-bottom: 1px solid var(--mk-border2); }
    .{{ $ptId }} td.hot, .{{ $ptId }} th.hot { background-color: rgba(255,255,255,.03); }
    .{{ $ptId }} .inc { color: var(--mk-accent); font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
    .{{ $ptId }} .inc svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
    .{{ $ptId }} .pr { color: rgba(255,255,255,.8); font-variant-numeric: tabular-nums; }
    .{{ $ptId }} .ask { color: rgba(255,255,255,.6); font-size: 13.5px; }
    .{{ $ptId }} .no { color: rgba(255,255,255,.22); }
    .{{ $ptId }} .lp-foot { margin: 22px auto 0; max-width: 1080px; font-size: 13px; color: var(--mk-muted); line-height: 1.6; }
    .{{ $ptId }} .lp-pick { display: none; }
    .{{ $ptId }} .lp-tabs { display: none; }
    @media (max-width: 820px) {
        .{{ $ptId }} .lp-cards { grid-template-columns: 1fr; }
        .{{ $ptId }}.pt-tabs .lp-tabs { display: flex; background: rgba(255,255,255,.06); border-radius: 999px; padding: 4px; gap: 2px; margin: 4px 0 22px; }
        .{{ $ptId }} .lp-tabs button { flex: 1; border: 0; background: none; color: var(--mk-muted); font: inherit; font-size: 13px; font-weight: 600; padding: 9px 0; border-radius: 999px; cursor: pointer; transition: background .2s, color .2s; }
        .{{ $ptId }} .lp-tabs button.on { background: var(--mk-accent); color: var(--mk-accent-text, #0a0a0a); }
        .{{ $ptId }}.pt-tabs .lp-cards { display: flex; grid-template-columns: none; gap: 12px; overflow-x: auto; scroll-snap-type: x mandatory;
            margin: 0 calc(-1 * var(--mk-gutter, 20px)); padding: 14px 7% 6px; scrollbar-width: none; -webkit-overflow-scrolling: touch; }
        .{{ $ptId }}.pt-tabs .lp-cards::-webkit-scrollbar { display: none; }
        .{{ $ptId }}.pt-tabs .lp-card { flex: 0 0 86%; scroll-snap-align: center; }
        .{{ $ptId }} .lp-blurb { min-height: 0; }
        .{{ $ptId }} .lp-pick { display: flex; gap: 6px; margin-bottom: 12px; }
        .{{ $ptId }} .lp-pick button { flex: 1; border: 1px solid var(--mk-border2); background: none; color: var(--mk-text); border-radius: 999px; padding: 8px; font: inherit; font-size: 13.5px; cursor: pointer; }
        .{{ $ptId }} .lp-pick button.on { background: var(--mk-accent); color: #0a0a0a; border-color: var(--mk-accent); font-weight: 600; }
        .{{ $ptId }} table [data-p] { display: none; }
        .{{ $ptId }} table [data-p].show { display: table-cell; }
    }
</style>

<section class="mk-section {{ $ptId }} {{ $bgId }}{{ $ptTabs ? ' pt-tabs' : '' }}">
    <div class="mk-container">
        @if(!empty($c['eyebrow']) || $heading !== '' || !empty($c['subheading']))
            <div class="lp-head">
                @if(!empty($c['eyebrow']))<div class="mk-eyebrow">{{ $c['eyebrow'] }}</div>@endif
                @if($heading !== '')<h2>{!! $heading !!}</h2>@endif
                @if(!empty($c['subheading']))<p>{{ $c['subheading'] }}</p>@endif
            </div>
        @endif

        @if($ptTabs)
            <div class="lp-tabs" role="tablist" aria-label="Plans">
                @foreach($plans as $i => $p)
                    <button type="button" role="tab" data-i="{{ $i }}" class="{{ $i === $ptActive ? 'on' : '' }}" aria-selected="{{ $i === $ptActive ? 'true' : 'false' }}">{{ $p['name'] }}</button>
                @endforeach
            </div>
        @endif
        <div class="lp-cards">
            @foreach($plans as $i => $p)
                <div class="lp-card{{ $p['k'] === $featured ? ' hot' : '' }}" data-plan="{{ $p['k'] }}">
                    <div class="lp-name">{{ $p['name'] }}</div>
                    <div class="lp-price"><b>${{ $money($p['cents']) }}</b><span>/mo</span></div>
                    <p class="lp-blurb">{{ $p['blurb'] }}</p>
                    <ul class="lp-list">
                        @if($i > 0)<li class="lead">Everything in {{ $plans[$i - 1]['name'] }}, plus:</li>@endif
                        @foreach(\App\Support\PricingCatalog::adds($i) as $line)
                            <li>{!! $check !!}<span>{{ $line }}</span></li>
                        @endforeach
                    </ul>
                    <a class="lp-btn" href="{{ $signup }}?plan={{ $p['k'] }}">Start free trial</a>
                </div>
            @endforeach
        </div>

        @unless($hideMx)
            <div class="lp-mx">
                <h3>Plans and features</h3>
                <p>Included, available as an add-on, or not on that plan.</p>
                <div class="lp-pick" role="tablist" aria-label="Plan">
                    @foreach($plans as $i => $p)
                        <button type="button" data-i="{{ $i }}" class="{{ $i === ($hotIdx === false ? 0 : $hotIdx) ? 'on' : '' }}">{{ $p['name'] }}</button>
                    @endforeach
                </div>
                @php $showIdx = $hotIdx === false ? 0 : $hotIdx; @endphp
                <table>
                    <thead><tr><th></th>
                        @foreach($plans as $i => $p)
                            <th data-p="{{ $i }}" class="{{ $i === $showIdx ? 'show' : '' }}{{ $i === $hotIdx ? ' hot' : '' }}">{{ $p['name'] }}<small>${{ $money($p['cents']) }}/mo</small></th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        <tr class="grp"><th colspan="4">The basics</th></tr>
                        @foreach($basics as $b)
                            <tr><th>{{ $b[0] }}</th>
                                @foreach([1, 2, 3] as $col)
                                    @php $v = trim((string) ($b[$col] ?? '')); $lv = strtolower($v); @endphp
                                    <td data-p="{{ $col - 1 }}" class="{{ $col - 1 === $showIdx ? 'show' : '' }}{{ $col - 1 === $hotIdx ? ' hot' : '' }}">
                                        @if($lv === 'yes')<span class="inc">{!! $check !!}</span>
                                        @elseif($v === '' || in_array($lv, ['no', '-', '—'], true))<span class="no">&mdash;</span>
                                        @else<span>{{ $v }}</span>@endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        @foreach($groups as $gName => $rows)
                            <tr class="grp"><th colspan="4">{{ $gName }}</th></tr>
                            @foreach($rows as $r)
                                <tr><th>{{ $r['addon']->name }}</th>
                                    @foreach(array_values($r['cells']) as $i => $cell)
                                        <td data-p="{{ $i }}" class="{{ $i === $showIdx ? 'show' : '' }}{{ $i === $hotIdx ? ' hot' : '' }}">
                                            @if($cell['t'] === 'inc')<span class="inc">{!! $check !!}Included</span>
                                            @elseif($cell['t'] === 'price')<span class="pr">{{ $cell['s'] }}</span>
                                            @elseif($cell['t'] === 'ask')<span class="ask">Ask us</span>
                                            @else<span class="no">&mdash;</span>@endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endunless

        <p class="lp-foot">{{ $footnote }}</p>
    </div>
</section>
<script>
/* phone: pills and swipe kept in sync; the table's pills pick a column */
(function () {
  var root = document.querySelector('.{{ $ptId }}');
  if (!root || root.dataset.lpReady) return;
  root.dataset.lpReady = '1';
  var row = root.querySelector('.lp-cards'), tabs = root.querySelectorAll('.lp-tabs button'), cards = root.querySelectorAll('.lp-card');
  if (row && tabs.length) {
    var phone = window.matchMedia('(max-width: 820px)');
    var mark = function (i) { tabs.forEach(function (t, k) { t.classList.toggle('on', k === i); t.setAttribute('aria-selected', k === i ? 'true' : 'false'); }); };
    var go = function (i, smooth) {
      var c = cards[i]; if (!c) return;
      var rr = row.getBoundingClientRect(), cr = c.getBoundingClientRect();
      row.scrollTo({ left: row.scrollLeft + (cr.left + cr.width / 2) - (rr.left + rr.width / 2), behavior: smooth ? 'smooth' : 'auto' });
      mark(i);
    };
    var raf = 0;
    row.addEventListener('scroll', function () {
      if (raf) return;
      raf = requestAnimationFrame(function () {
        raf = 0;
        var rr = row.getBoundingClientRect(), mid = rr.left + rr.width / 2, best = 0, d = Infinity;
        cards.forEach(function (c, k) { var cr = c.getBoundingClientRect(), m = Math.abs(cr.left + cr.width / 2 - mid); if (m < d) { d = m; best = k; } });
        mark(best);
      });
    }, { passive: true });
    tabs.forEach(function (b) { b.addEventListener('click', function () { go(+b.dataset.i, true); }); });
    var start = function () { if (phone.matches) go({{ $ptActive }}, false); };
    window.addEventListener('load', function () { setTimeout(start, 30); });
    start();
  }
  var pick = root.querySelector('.lp-pick');
  if (pick) pick.addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    pick.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
    root.querySelectorAll('table [data-p]').forEach(function (c) { c.classList.toggle('show', c.getAttribute('data-p') === b.getAttribute('data-i')); });
  });
})();
</script>
