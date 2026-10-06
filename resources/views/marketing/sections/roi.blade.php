@php $bgId = 'mkbg-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10); @endphp {{-- MARKER-MKT-SECTION-BG --}}
@include('marketing.sections._section_bg', ['bgId' => $bgId])
{{--
  MARKER-ROI-SECTION — results with their sources, plus an optional rental-extension calculator.
  Content: eyebrow, heading, accent_words, subheading,
           s{1..3}_big / _label / _source / _note  (blank big line hides that result),
           calc_on, calc_title, calc_intro, calc_fleet, calc_rate, calc_idle, calc_length,
           calc_takeup, calc_discount, calc_note, accent_color.
--}}
@php
    $rid    = 'roi-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10);
    $hex    = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($v)) ? trim($v) : null;
    $accent = $hex($c['accent_color'] ?? null) ?: 'var(--mk-accent)';
    $heading = e($c['heading'] ?? '');
    $aw = trim((string) ($c['accent_words'] ?? ''));
    if ($aw !== '' && stripos($heading, e($aw)) !== false) {
        $heading = preg_replace('/' . preg_quote(e($aw), '/') . '/i', '<span class="roi-accent">$0</span>', $heading, 1);
    }
    // "$8 → $3,000+" : cost dim, arrow and return in the accent
    $big = function (string $s): string {
        $parts = preg_split('/\s*(?:→|->)\s*/u', trim($s), 2);
        if (count($parts) === 2) {
            return '<span class="roi-cost">' . e($parts[0]) . '</span><span class="roi-arrow" aria-hidden="true">→</span><span class="roi-ret">' . e($parts[1]) . '</span>';
        }
        return e($s);
    };
    $results = [];
    foreach ([1, 2, 3] as $n) {
        $b = trim((string) ($c["s{$n}_big"] ?? ''));
        if ($b === '') continue;
        $results[] = ['big' => $b, 'label' => trim((string) ($c["s{$n}_label"] ?? '')), 'source' => trim((string) ($c["s{$n}_source"] ?? '')), 'note' => trim((string) ($c["s{$n}_note"] ?? ''))];
    }
    $calcOn = array_key_exists('calc_on', $c) ? ! in_array((string) $c['calc_on'], ['', '0', 'false'], true) : true;
    $num = fn ($k, $d, $lo, $hi) => max($lo, min($hi, is_numeric($c[$k] ?? null) ? (float) $c[$k] : $d));
    $calc = [
        'fleet'    => (int) $num('calc_fleet', 30, 1, 500),
        'rate'     => (int) $num('calc_rate', 100, 1, 5000),
        'idle'     => (int) $num('calc_idle', 50, 0, 95),
        'length'   => (int) $num('calc_length', 1, 1, 30),
        'takeup'   => (int) $num('calc_takeup', 40, 0, 100),
        'discount' => (int) $num('calc_discount', 50, 0, 90),
    ];
@endphp
<section class="{{ $padding }} {{ $bgId }} {{ $rid }}" @if(!empty($inlineStyle ?? '')) style="{{ $inlineStyle }}" @endif>
<style>
  .{{ $rid }} .roi-wrap { max-width: 1100px; margin: 0 auto; }
  .{{ $rid }} .roi-accent { color: {{ $accent }}; }
  .{{ $rid }} .roi-head { max-width: 720px; margin-bottom: 44px; }
  .{{ $rid }} .roi-head p { color: var(--mk-muted); font-size: 17px; line-height: 1.6; }
  .{{ $rid }} .roi-results { display: grid; grid-template-columns: repeat({{ max(1, count($results)) }}, minmax(0, 1fr)); border-top: .5px solid var(--mk-border2); }
  .{{ $rid }} .roi-r { padding: 28px 28px 8px 0; }
  .{{ $rid }} .roi-r + .roi-r { padding-left: 28px; border-left: .5px solid var(--mk-border2); }
  .{{ $rid }} .roi-big { font-size: clamp(26px, 3.2vw, 40px); font-weight: 700; letter-spacing: -.025em; line-height: 1.1; display: flex; flex-wrap: wrap; align-items: baseline; gap: 0 12px; }
  .{{ $rid }} .roi-cost { color: rgba(255,255,255,.5); }
  .{{ $rid }} .roi-arrow, .{{ $rid }} .roi-ret { color: {{ $accent }}; }
  .{{ $rid }} .roi-label { color: rgba(255,255,255,.78); font-size: 15.5px; line-height: 1.55; margin-top: 14px; }
  .{{ $rid }} .roi-src { display: inline-flex; align-items: center; gap: 7px; margin-top: 14px; font-size: 12.5px; color: rgba(255,255,255,.62); }
  .{{ $rid }} .roi-src::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: {{ $accent }}; }
  .{{ $rid }} .roi-note { color: var(--mk-dim); font-size: 12.5px; line-height: 1.5; margin-top: 8px; }
  .{{ $rid }} .roi-calc { margin-top: 48px; display: grid; grid-template-columns: 1.15fr 1fr; gap: clamp(24px, 5vw, 64px); align-items: center; padding: clamp(24px, 4vw, 40px); border-radius: 18px; background: linear-gradient(180deg, #141414, #0f0f0f); border: .5px solid rgba(255,255,255,.1); }
  .{{ $rid }} .roi-calc h3 { font-size: 22px; font-weight: 700; letter-spacing: -.015em; margin-bottom: 6px; }
  .{{ $rid }} .roi-calc .roi-ci { color: var(--mk-muted); font-size: 15px; line-height: 1.6; margin-bottom: 22px; }
  .{{ $rid }} .roi-sl { margin-bottom: 16px; }
  .{{ $rid }} .roi-sl label { display: flex; justify-content: space-between; font-size: 13.5px; color: rgba(255,255,255,.78); margin-bottom: 7px; }
  .{{ $rid }} .roi-sl label b { color: #fff; font-variant-numeric: tabular-nums; font-weight: 600; }
  .{{ $rid }} .roi-sl input[type=range] { width: 100%; accent-color: {{ $accent }}; }
  .{{ $rid }} .roi-out { text-align: left; }
  .{{ $rid }} .roi-out .k { font-size: 13px; color: rgba(255,255,255,.62); }
  .{{ $rid }} .roi-out .v { font-size: clamp(40px, 6vw, 64px); font-weight: 750; letter-spacing: -.03em; color: {{ $accent }}; line-height: 1.05; font-variant-numeric: tabular-nums; margin: 6px 0 4px; }
  .{{ $rid }} .roi-out .per { font-size: 15px; color: rgba(255,255,255,.62); }
  .{{ $rid }} .roi-out .how { margin-top: 18px; padding-top: 14px; border-top: .5px solid var(--mk-border2); font-size: 13.5px; color: rgba(255,255,255,.7); line-height: 1.7; font-variant-numeric: tabular-nums; }
  @media (max-width: 860px) {
    .{{ $rid }} .roi-results { grid-template-columns: 1fr; }
    .{{ $rid }} .roi-r, .{{ $rid }} .roi-r + .roi-r { padding: 24px 0 8px; border-left: 0; }
    .{{ $rid }} .roi-r + .roi-r { border-top: .5px solid var(--mk-border2); }
    .{{ $rid }} .roi-calc { grid-template-columns: 1fr; }
  }
</style>
    <div class="mk-container"><div class="roi-wrap">
        @if(!empty($c['eyebrow']) || !empty($c['heading']) || !empty($c['subheading']))
        <div class="roi-head">
            @if(!empty($c['eyebrow']))<div class="mk-eyebrow" style="color:{{ $accent }}">{{ $c['eyebrow'] }}</div>@endif
            @if(!empty($c['heading']))<h2 class="mk-section-title">{!! $heading !!}</h2>@endif
            @if(!empty($c['subheading']))<p>{!! nl2br(e($c['subheading'])) !!}</p>@endif
        </div>
        @endif

        @if($results)
        <div class="roi-results">
            @foreach($results as $r)
                <div class="roi-r">
                    <div class="roi-big">{!! $big($r['big']) !!}</div>
                    @if($r['label'] !== '')<div class="roi-label">{{ $r['label'] }}</div>@endif
                    @if($r['source'] !== '')<div class="roi-src">{{ $r['source'] }}</div>@endif
                    @if($r['note'] !== '')<div class="roi-note">{{ $r['note'] }}</div>@endif
                </div>
            @endforeach
        </div>
        @endif

        @if($calcOn)
        <div class="roi-calc" data-roi-calc>
            <div>
                <h3>{{ trim((string) ($c['calc_title'] ?? '')) ?: 'Rentals that extend themselves' }}</h3>
                <p class="roi-ci">{{ trim((string) ($c['calc_intro'] ?? '')) ?: 'Before a rental ends, customers are offered an extra day at a discount. Drag to match your fleet.' }}</p>
                @foreach([
                    ['fleet', 'Items in your fleet', 1, 200, 1, ''],
                    ['rate', 'Daily rate', 10, 500, 5, '$'],
                    ['idle', 'Idle part of the month', 0, 90, 5, '%'],
                    ['length', 'Typical rental', 1, 14, 1, 'd'],
                    ['takeup', 'Customers who extend', 0, 100, 5, '%'],
                    ['discount', 'Extension discount', 0, 80, 5, '%'],
                ] as [$k, $lab, $lo, $hi, $st, $u])
                    @php $v = max($lo, min(max($hi, $calc[$k]), $calc[$k])); @endphp
                    <div class="roi-sl">
                        <label for="{{ $rid }}-{{ $k }}">{{ $lab }} <b data-roi-show="{{ $k }}" data-unit="{{ $u }}"></b></label>
                        <input type="range" id="{{ $rid }}-{{ $k }}" data-roi="{{ $k }}" min="{{ $lo }}" max="{{ max($hi, $calc[$k]) }}" step="{{ $st }}" value="{{ $v }}">
                    </div>
                @endforeach
            </div>
            <div class="roi-out" aria-live="polite">
                <div class="k">Extra rental income</div>
                <div class="v" data-roi-out="total">$0</div>
                <div class="per">a month, from days that would sit idle</div>
                <div class="how" data-roi-out="how"></div>
                <div class="roi-note">{{ trim((string) ($c['calc_note'] ?? '')) ?: 'Estimate. One extra day per accepted offer, never more than your idle days.' }}</div>
            </div>
        </div>
        @endif
    </div></div>
</section>
@if($calcOn)
<script>
  // MARKER-ROI-SECTION — rental extension calculator; one listener for every calculator on the page.
  (function () {
    if (window.__roiCalc) { window.__roiCalc(); return; }
    var fmt = function (n) { return '$' + Math.round(n).toLocaleString('en-US'); };
    function run(box) {
      var v = {};
      box.querySelectorAll('[data-roi]').forEach(function (i) { v[i.getAttribute('data-roi')] = +i.value; });
      box.querySelectorAll('[data-roi-show]').forEach(function (b) {
        var k = b.getAttribute('data-roi-show'), u = b.getAttribute('data-unit');
        b.textContent = u === '$' ? fmt(v[k]) : (u === '%' ? v[k] + '%' : (u === 'd' ? v[k] + (v[k] === 1 ? ' day' : ' days') : v[k]));
      });
      var itemDays = v.fleet * 30;
      var idleDays = itemDays * v.idle / 100;
      var rentals  = (itemDays - idleDays) / Math.max(1, v.length);
      var ext      = Math.min(rentals * v.takeup / 100, idleDays);
      var each     = v.rate * (1 - v.discount / 100);
      box.querySelector('[data-roi-out="total"]').textContent = fmt(ext * each);
      box.querySelector('[data-roi-out="how"]').innerHTML =
        Math.round(rentals).toLocaleString('en-US') + ' rentals a month<br>' +
        Math.round(ext).toLocaleString('en-US') + ' take the extra day, at ' + fmt(each) + ' each<br>' +
        Math.round(idleDays).toLocaleString('en-US') + ' idle item-days available';
    }
    function scan() { document.querySelectorAll('[data-roi-calc]').forEach(function (b) { if (b.__roi) return; b.__roi = true; b.addEventListener('input', function () { run(b); }); run(b); }); } // only new calculators: run() changes text, which the observer would see
    window.__roiCalc = scan;
    scan();
    if (window.MutationObserver) new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
  })();
</script>
@endif
