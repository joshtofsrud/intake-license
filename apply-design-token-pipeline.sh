#!/usr/bin/env bash
set -euo pipefail
# apply-design-token-pipeline.sh — MARKER-TOKENS
# Makes the five site templates actually different from each other.
#
# THE DEFECT: SiteTemplateService::apply() maps five tokens onto tenant columns
# (accent, text, bg, font_heading, font_body). The other eight a template
# defines — surface, muted, hero_bg, hero_text, button_radius, button_style,
# heading_weight, heading_transform — are stashed in design_tokens and NEVER
# read at render time. Only the template THUMBNAIL honours them, so the
# preview promises a look the live site cannot deliver. Templates currently
# differ in production by three colours and two fonts.
#
# WHAT THIS DOES
#   1. App\Support\DesignTokens — one resolver. Cascade, most specific first:
#      tenant columns (the five, still source of truth — booking, portal and
#      email read them) → design_tokens overrides → the active template's
#      defaults → hardcoded fallbacks. Returns a complete set every time, so
#      callers never null-check.
#   2. All sixteen tokens emitted as CSS variables in the three shells
#      (layout, _chrome-inline, account/_shell). surface and muted stop being
#      three separate hardcoded copies.
#   3. The tokens hooked into rules that already exist: h1-h4 take
#      heading_weight and heading_transform; .p-btn takes its own --p-btn-r
#      instead of the shared --p-r; .p-btn--primary honours button_style
#      (solid / outline / ghost).
#   4. SECTION INHERIT. 19 of 25 section types seed a hardcoded bg_color
#      ('#ffffff', hero '#1a1a1a'), so sections paint over any template and
#      the colours would look broken. New sections now seed the sentinel
#      'inherit', resolved to the right token at render. Done at the single
#      @include choke point in layout.blade.php rather than in 19 section
#      views, so no section partial changes and none can end up with a blank
#      background. EXISTING sections keep their explicit hex, untouched.
#
# NOT included, deliberately: the customizer UI (this is its plumbing) and
# moving colours/fonts out of Settings->Branding.

SUP=app/Support/DesignTokens.php
LAYOUT=resources/views/public/layout.blade.php
CHROME=resources/views/public/_chrome-inline.blade.php
SHELL=resources/views/public/account/_shell.blade.php
PB=app/Http/Controllers/Tenant/PageBuilderController.php

for f in "$LAYOUT" "$CHROME" "$SHELL" "$PB"; do
  [ -f "$f" ] || { echo "MISSING $f — run from the repo root"; exit 1; }
done

if grep -q "MARKER-TOKENS" "$LAYOUT"; then
  echo "Already applied (MARKER-TOKENS present) — no-op."
  exit 0
fi

# ================================================================ resolver
if [ -f "$SUP" ]; then echo "ok   resolver already present"; else
cat <<'EOF' > "$SUP"
<?php

namespace App\Support;

// MARKER-TOKENS — the single source of truth for what a tenant's public site
// looks like. Everything that renders public chrome asks this, so the shells
// can't drift apart the way surface/muted did (three hardcoded copies).

use App\Models\Tenant;

class DesignTokens
{
    /** Last-resort values. Match the pre-token hardcoded CSS exactly, so a
     *  tenant with no template and no overrides renders byte-identical. */
    public const FALLBACKS = [
        'accent'            => '#BEF264',
        'text'              => '#111111',
        'bg'                => '#ffffff',
        'surface'           => 'rgba(0,0,0,.03)',
        'muted'             => 'rgba(0,0,0,.5)',
        'border'            => 'rgba(0,0,0,.1)',
        'hero_bg'           => '',        // '' = fall through to the section's own bg
        'hero_text'         => '',
        'font_heading'      => 'Inter',
        'font_body'         => 'Inter',
        'heading_weight'    => 700,
        'heading_transform' => 'none',
        'button_radius'     => 8,
        'button_style'      => 'solid',
        'card_radius'       => 8,
    ];

    /** Which token a section inherits when its bg_color is 'inherit'. */
    public const SECTION_BG = [
        'hero'               => 'hero_bg',
        'cta_banner'         => 'surface',
        'services'           => 'surface',
        'feature_grid'       => 'surface',
        'pricing_table'      => 'surface',
        'stats_row'          => 'surface',
        'faq_accordion'      => 'surface',
        'logo_bar'           => 'surface',
        'step_timeline'      => 'surface',
        'products_showcase'  => 'surface',
        'rentals_showcase'   => 'surface',
        'classes_embed'      => 'surface',
    ];
    // Anything not listed inherits the page background.

    public static function resolve(?Tenant $tenant): array
    {
        $out = self::FALLBACKS;

        if (! $tenant) {
            return $out;
        }

        // 3) the active template's own token set
        if ($tenant->site_template) {
            // tokens() returns null for a key that no longer exists.
            foreach ((SiteTemplate::tokens($tenant->site_template) ?? []) as $k => $v) {
                if ($v !== null && $v !== '') {
                    $out[$k] = $v;
                }
            }
        }

        // 2) per-tenant overrides. '_prev' is the template revert snapshot,
        //    not a token — never let it leak into the output.
        foreach ((array) ($tenant->design_tokens ?? []) as $k => $v) {
            if ($k === '_prev' || ! array_key_exists($k, $out)) {
                continue;
            }
            if ($v !== null && $v !== '') {
                $out[$k] = $v;
            }
        }

        // 1) the five discrete columns win — they are what booking, the
        //    customer portal and transactional email already read, so the
        //    site must not disagree with them.
        $columns = [
            'accent'       => $tenant->accent_color,
            'text'         => $tenant->text_color,
            'bg'           => $tenant->bg_color,
            'font_heading' => $tenant->font_heading,
            'font_body'    => $tenant->font_body,
        ];
        foreach ($columns as $k => $v) {
            if ($v !== null && $v !== '') {
                $out[$k] = $v;
            }
        }

        $out['accent_text'] = ColorHelper::accentTextColor($out['accent']);

        // A template that doesn't state hero colours just uses the page's.
        if ($out['hero_bg'] === '')   { $out['hero_bg']   = $out['bg']; }
        if ($out['hero_text'] === '') { $out['hero_text'] = $out['text']; }

        return $out;
    }

    /** The CSS custom-property block, shared by all three shells. */
    public static function cssVars(array $t, string $indent = '      '): string
    {
        $lines = [
            '--p-accent: '        . $t['accent'],
            '--p-accent-text: '   . $t['accent_text'],
            '--p-text: '          . $t['text'],
            '--p-bg: '            . $t['bg'],
            '--p-surface: '       . $t['surface'],
            '--p-muted: '         . $t['muted'],
            '--p-border: '        . $t['border'],
            '--p-hero-bg: '       . $t['hero_bg'],
            '--p-hero-text: '     . $t['hero_text'],
            "--p-font-heading: '" . $t['font_heading'] . "', -apple-system, sans-serif",
            "--p-font-body: '"    . $t['font_body']    . "', -apple-system, sans-serif",
            '--p-heading-weight: '    . (int) $t['heading_weight'],
            '--p-heading-transform: ' . $t['heading_transform'],
            '--p-btn-r: '         . (int) $t['button_radius'] . 'px',
            '--p-card-r: '        . (int) $t['card_radius'] . 'px',
        ];

        return implode("\n", array_map(fn ($l) => $indent . $l . ';', $lines));
    }

    /**
     * Resolve a section's stored bg_color. 'inherit' (the new seeded default)
     * and blank both map to the token this section type should follow;
     * anything else is an explicit choice the tenant made in the builder and
     * is returned untouched.
     */
    public static function sectionBg(?string $stored, string $sectionType, array $t): string
    {
        if ($stored !== null && $stored !== '' && $stored !== 'inherit') {
            return $stored;
        }

        $key = self::SECTION_BG[$sectionType] ?? 'bg';

        return $t[$key] ?? $t['bg'];
    }
}
EOF
echo "ok   resolver created"; fi

# ================================================================ layout
python3 - "$LAYOUT" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

def edit(old, new, label):
    global src
    n = src.count(old)
    if n != 1:
        print(f"FAIL {label}: anchor found {n} times"); sys.exit(1)
    src = src.replace(old, new, 1)
    print(f"ok   {label}")

# 1) resolve once, near the existing font vars
edit("""    $headingFont = $currentTenant->font_heading ?? 'Inter';
    $bodyFont    = $currentTenant->font_body    ?? 'Inter';""",
"""    // MARKER-TOKENS — one resolve for the whole page.
    $dt = \\App\\Support\\DesignTokens::resolve($currentTenant);
    $headingFont = $dt['font_heading'];
    $bodyFont    = $dt['font_body'];""",
"layout resolve")

# 2) emit the full set
edit("""    :root {
      --p-accent:       {{ $currentTenant->accent_color  ?? '#BEF264' }};
      --p-text:         {{ $currentTenant->text_color    ?? '#111111' }};
      --p-bg:           {{ $currentTenant->bg_color      ?? '#ffffff' }};
      --p-font-heading: '{{ $headingFont }}', -apple-system, sans-serif;
      --p-font-body:    '{{ $bodyFont }}',    -apple-system, sans-serif;
      --p-accent-text:  {{ \\App\\Support\\ColorHelper::accentTextColor($currentTenant->accent_color ?? '#BEF264') }};
      --p-r:            8px;""",
"""    :root {
{!! \\App\\Support\\DesignTokens::cssVars($dt) !!} {{-- MARKER-TOKENS --}}
      --p-r:            8px;""",
"layout vars")

# 3) headings honour weight + transform
edit("""    h1,h2,h3,h4 {
      font-family: var(--p-font-heading);
      line-height: 1.2;
      font-weight: 700;
    }""",
"""    h1,h2,h3,h4 {
      font-family: var(--p-font-heading);
      line-height: 1.2;
      font-weight: var(--p-heading-weight, 700);      /* MARKER-TOKENS */
      text-transform: var(--p-heading-transform, none);
    }""",
"layout headings")

# 4) buttons get their own radius, so card radius can differ
edit("""    .p-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 12px 24px;
      border-radius: var(--p-r);""",
"""    .p-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 12px 24px;
      border-radius: var(--p-btn-r, var(--p-r));      /* MARKER-TOKENS */""",
"layout button radius")

# 5) button_style — applied as a body class so the cascade stays readable
edit("""    .p-btn--primary {
      background: var(--p-accent);
      color: var(--p-accent-text);
      border-color: var(--p-accent);
    }""",
"""    .p-btn--primary {
      background: var(--p-accent);
      color: var(--p-accent-text);
      border-color: var(--p-accent);
    }
    /* MARKER-TOKENS — template button styles. Outline and ghost keep the
       accent as the visible edge or fill hint rather than a solid slab. */
    body.p-btn-outline .p-btn--primary {
      background: transparent;
      color: var(--p-text);
      border-color: var(--p-accent);
    }
    body.p-btn-outline .p-btn--primary:hover {
      background: var(--p-accent);
      color: var(--p-accent-text);
      filter: none;
    }
    body.p-btn-ghost .p-btn--primary {
      background: var(--p-surface);
      color: var(--p-text);
      border-color: transparent;
    }
    body.p-btn-ghost .p-btn--primary:hover { filter: brightness(.96); }""",
"layout button style")

# 6) section inherit — resolved at the single include choke point
edit("""      @include($partial, [
        'c'        => $section->content ?? [],
        'section'  => $section,""",
"""      {{-- MARKER-TOKENS — resolve an inheriting background here, once, rather
           than in 19 section partials. An explicit hex passes through
           untouched, so nothing a tenant chose in the builder changes. --}}
      @php
        $sc = $section->content ?? [];
        $sc['bg_color'] = \\App\\Support\\DesignTokens::sectionBg(
            $sc['bg_color'] ?? null, $section->section_type, $dt
        );
      @endphp
      @include($partial, [
        'c'        => $sc,
        'section'  => $section,""",
"layout section inherit")

open(path, 'w').write(src)
PY

# body class for button style
python3 - "$LAYOUT" <<'PY'
import sys, re
path = sys.argv[1]
src = open(path).read()

m = re.search(r'<body([^>]*)>', src)
if not m:
    print("FAIL body tag: not found"); sys.exit(1)

attrs = m.group(1)
if 'class=' in attrs:
    new = re.sub(r'class="', 'class="p-btn-{{ $dt[\'button_style\'] }} ', attrs, count=1)
else:
    new = attrs + ' class="p-btn-{{ $dt[\'button_style\'] }}"'

src = src[:m.start()] + '<body' + new + '>' + src[m.end():]
open(path, 'w').write(src)
print("ok   layout body class")
PY

# ================================================================ chrome-inline
python3 - "$CHROME" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """    .p-chrome-scope {
      --p-accent:       {{ $ciTenant->accent_color ?? '#BEF264' }};
      --p-text:         {{ $ciTenant->text_color   ?? '#111111' }};
      --p-bg:           {{ $ciTenant->bg_color     ?? '#ffffff' }};
      --p-font-heading: '{{ $ciTenant->font_heading ?? 'Inter' }}', -apple-system, sans-serif;
      --p-font-body:    '{{ $ciTenant->font_body    ?? 'Inter' }}', -apple-system, sans-serif;
      --p-accent-text:  {{ \\App\\Support\\ColorHelper::accentTextColor($ciTenant->accent_color ?? '#BEF264') }};
      --p-r: 8px; --p-r-lg: 12px; --p-max: 1160px;"""
new = """    .p-chrome-scope {
{!! \\App\\Support\\DesignTokens::cssVars(\\App\\Support\\DesignTokens::resolve($ciTenant)) !!} {{-- MARKER-TOKENS --}}
      --p-r: 8px; --p-r-lg: 12px; --p-max: 1160px;"""
n = src.count(old)
if n != 1:
    print(f"FAIL chrome vars: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   chrome vars")

open(path, 'w').write(src)
PY

# ================================================================ account shell
python3 - "$SHELL" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """    :root {
      --p-accent:      {{ $currentTenant->accent_color ?? '#BEF264' }};
      --p-accent-text: {{ \\App\\Support\\ColorHelper::accentTextColor($currentTenant->accent_color ?? '#BEF264') }};
      --p-text:        {{ $currentTenant->text_color ?? '#111111' }};
      --p-bg:          {{ $currentTenant->bg_color ?? '#ffffff' }};
      --p-muted:       rgba(0,0,0,.5);
      --p-border:      rgba(0,0,0,.1);
      --p-surface:     rgba(0,0,0,.03);
      --p-font-heading:'{{ $currentTenant->font_heading ?? 'Inter' }}', -apple-system, sans-serif;
      --p-font-body:   '{{ $currentTenant->font_body ?? 'Inter' }}', -apple-system, sans-serif;
      --p-r: 8px; --p-r-lg: 12px; --p-max: 680px;"""
new = """    :root {
{!! \\App\\Support\\DesignTokens::cssVars(\\App\\Support\\DesignTokens::resolve($currentTenant)) !!} {{-- MARKER-TOKENS --}}
      --p-r: 8px; --p-r-lg: 12px; --p-max: 680px;"""
n = src.count(old)
if n != 1:
    print(f"FAIL shell vars: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   shell vars")

open(path, 'w').write(src)
PY

# ================================================================ seeded defaults
python3 - "$PB" <<'PY'
import sys, re
path = sys.argv[1]
src = open(path).read()

# Only inside the DEFAULTS constant, and only bg_color keys. Existing rows in
# the database are untouched — this changes what NEW sections seed with.
# ARRAY_FIELDS is declared BEFORE DEFAULTS, so it can't be the end boundary.
# Use the first method declaration after DEFAULTS instead.
start = src.index('const DEFAULTS')
ends = [src.index(m, start) for m in ('\n    private function', '\n    public function') if m in src[start:]]
if not ends:
    print("FAIL section defaults: no method after DEFAULTS"); sys.exit(1)
end = min(ends)
block = src[start:end]

new_block, n = re.subn(
    r"('bg_color'\s*=>\s*)'#[0-9a-fA-F]{3,8}'",
    r"\1'inherit'",
    block,
)
if n == 0:
    print("FAIL section defaults: no hardcoded bg_color found"); sys.exit(1)

src = src[:start] + new_block + src[end:]
print(f"ok   section defaults -> inherit ({n} section types)")

open(path, 'w').write(src)
PY

php -l "$SUP"
php -l "$PB"

echo ""
echo "SUCCESS — apply-design-token-pipeline applied."
echo "Existing sections keep their explicit colours, so a hand-built site like"
echo "Ground Control looks identical. The difference shows on new sections and"
echo "when a template is applied."
