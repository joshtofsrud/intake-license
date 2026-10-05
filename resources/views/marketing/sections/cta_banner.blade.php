@php $bgId = 'mkbg-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10); @endphp {{-- MARKER-MKT-SECTION-BG --}}
@include('marketing.sections._section_bg', ['bgId' => $bgId])
{{--
    CTA banner (intake.works). MARKER-MKT-CTA-V2 — reads every editor setting:
    eyebrow, headline with accent phrase (color, italic), subheading, up to 4
    buttons (Primary / Outline / Ghost / Link) shown separately or as a pill
    bar, a note under the buttons, alignment, content width (slider), text /
    body / accent colors and button fill + text colors. Backgrounds (color,
    gradient, image, blend, continue) come from the shared renderer above.
    On phones the buttons stack full-width.
--}}
@php
    $ctId    = 'mkcta-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10);
    $ok      = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($v)) ? trim($v) : null;
    $align   = in_array($c['text_align'] ?? 'center', ['left', 'center', 'right'], true) ? ($c['text_align'] ?? 'center') : 'center';
    $justify = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][$align];
    $maxW    = max(320, min(1200, (int) ($c['content_max_width'] ?? 640)));
    $txt     = $ok($c['text_color'] ?? null)      ?: 'var(--mk-text)';
    $body    = $ok($c['text_color_body'] ?? null) ?: 'var(--mk-muted)';
    $accent  = $ok($c['accent_color'] ?? null)    ?: 'var(--mk-accent)';
    $bFill   = $ok($c['btn_fill'] ?? null)        ?: 'var(--mk-accent)';
    $bText   = $ok($c['btn_text'] ?? null)        ?: 'var(--mk-accent-text, #0a0a0a)';
    $italic  = ! in_array((string) ($c['accent_italic'] ?? '0'), ['0', 'false', ''], true);
    $pill    = ($c['buttons_style'] ?? 'separate') === 'pill';
    // MARKER-MKT-CTA-V3 — optional fixed sizes (0 = automatic)
    $hSize   = max(0, min(96, (int) ($c['headline_size'] ?? 0)));
    $sSize   = max(0, min(28, (int) ($c['sub_size'] ?? 0)));
    $nSize   = max(0, min(20, (int) ($c['note_size'] ?? 0)));

    $buttons = $c['buttons'] ?? [];
    if (is_string($buttons)) { $d = json_decode($buttons, true); $buttons = is_array($d) ? $d : []; }
    if (! is_array($buttons)) $buttons = [];
    $buttons = array_slice(array_values(array_filter($buttons, fn ($b) => is_array($b) && trim((string) ($b['label'] ?? '')) !== '')), 0, 4);
    // Old single-button fields only for a banner whose list was never saved.
    if (! $buttons && ! array_key_exists('buttons', $c) && ! empty($c['cta_label'])) {
        $buttons = [['label' => $c['cta_label'], 'url' => $c['cta_url'] ?? '#', 'style' => 'primary']];
    }

    $head = e($c['headline'] ?? '');
    $acc  = trim((string) ($c['accent_words'] ?? ''));
    if ($acc !== '' && stripos($head, e($acc)) !== false) $head = str_ireplace(e($acc), '<em>' . e($acc) . '</em>', $head);
    $head = nl2br($head);
@endphp
<style>
    .{{ $ctId }} { padding: clamp(48px, 7vw, 88px) 0; text-align: {{ $align }}; color: {{ $txt }}; }
    .{{ $ctId }} .cta-in { max-width: {{ $maxW }}px; @if($align === 'center') margin: 0 auto; @elseif($align === 'right') margin-left: auto; @endif }
    .{{ $ctId }} .cta-eyebrow { font-size: 12px; letter-spacing: .14em; text-transform: uppercase; font-weight: 600; color: {{ $accent }}; margin-bottom: 14px; }
    .{{ $ctId }} .cta-h { font-size: {{ $hSize ? 'min(' . $hSize . 'px, 11vw)' : 'clamp(26px, 4vw, 48px)' }}; font-weight: 800; letter-spacing: -.03em; line-height: 1.05; margin: 0 0 12px; color: {{ $txt }}; }
    .{{ $ctId }} .cta-h em { color: {{ $accent }}; font-style: {{ $italic ? 'italic' : 'normal' }}; }
    .{{ $ctId }} .cta-sub { font-size: {{ $sSize ? $sSize . 'px' : '16px' }}; line-height: 1.6; color: {{ $body }}; margin: 0 0 28px; }
    .{{ $ctId }} .cta-acts { display: flex; flex-wrap: wrap; gap: 10px; justify-content: {{ $justify }}; }
    .{{ $ctId }} .cta-pill { display: inline-flex; flex-wrap: wrap; gap: 4px; padding: 5px; border-radius: 999px; background: rgba(10,10,10,.5); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border: 0.5px solid rgba(255,255,255,.14); }
    .{{ $ctId }} .cta-btn { display: inline-flex; align-items: center; gap: 6px; padding: 12px 22px; border-radius: {{ $pill ? '999px' : '10px' }}; font-weight: 600; font-size: 15px; text-decoration: none; transition: filter .15s, transform .15s; }
    .{{ $ctId }} .cta-btn:hover { transform: translateY(-1px); }
    .{{ $ctId }} .cta-btn--primary { background: {{ $bFill }}; color: {{ $bText }}; }
    .{{ $ctId }} .cta-btn--outline { border: 1px solid {{ $bFill }}; color: {{ $txt }}; }
    .{{ $ctId }} .cta-btn--ghost { background: rgba(255,255,255,.06); color: {{ $txt }}; }
    .{{ $ctId }} .cta-btn--link { color: {{ $accent }}; padding-left: 6px; padding-right: 6px; }
    /* MARKER-CTA-PILL-HOVER — plain buttons inside the pill, highlight on hover */
    .{{ $ctId }} .cta-pill .cta-btn { transition: background-color .2s ease, opacity .2s ease, transform .15s; white-space: nowrap; }
    .{{ $ctId }} .cta-pill .cta-btn--ghost { background: transparent; opacity: .88; }
    .{{ $ctId }} .cta-pill .cta-btn--ghost:hover, .{{ $ctId }} .cta-pill .cta-btn--ghost:focus-visible { background: rgba(255,255,255,.1); opacity: 1; transform: none; }
    .{{ $ctId }} .cta-note { font-size: {{ $nSize ? $nSize . 'px' : '13px' }}; color: {{ $body }}; margin-top: 16px; opacity: .85; }
    @media (max-width: 600px) {
        .{{ $ctId }} .cta-acts, .{{ $ctId }} .cta-pill { flex-direction: column; align-items: stretch; width: 100%; }
        .{{ $ctId }} .cta-pill { border-radius: 22px; }
        .{{ $ctId }} .cta-btn { justify-content: center; }
    }
</style>
<section class="mk-cta-strip {{ $bgId }} {{ $ctId }}">
    <div class="mk-container"><div class="cta-in">
        @if(!empty($c['eyebrow']))<div class="cta-eyebrow">{{ $c['eyebrow'] }}</div>@endif
        <h2 class="cta-h">{!! $head !== '' ? $head : 'Ready to get started?' !!}</h2>
        @if(!empty($c['subheading']))<p class="cta-sub">{{ $c['subheading'] }}</p>@endif
        @if($buttons)
            <div class="cta-acts">
                @if($pill) <span class="cta-pill"> @endif
                @foreach($buttons as $b)
                    @php $st = in_array($b['style'] ?? 'primary', ['primary', 'outline', 'ghost', 'link'], true) ? ($b['style'] ?? 'primary') : 'primary'; @endphp
                    <a href="{{ $b['url'] ?? '#' }}" class="cta-btn cta-btn--{{ ($pill && ! $loop->first && $st === 'primary') ? 'ghost' : $st }}">{{ $b['label'] }}@if($loop->first && $st === 'primary') →@endif</a>
                @endforeach
                @if($pill) </span> @endif
            </div>
        @endif
        @if(!empty($c['note']))<div class="cta-note">{{ $c['note'] }}</div>@endif
    </div></div>
</section>
