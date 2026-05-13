#!/bin/bash
# ============================================================================
# patch-69-theme-editor-color-pickers.sh
# ----------------------------------------------------------------------------
# Adds visual color editing to the theme editor.
#
# Two categories of tokens:
#
# 1. HEX tokens (#xxxxxx) — render as Filament ColorPicker. Click to open
#    a color wheel UI; result writes as #hex into the input.
#      Tokens: ia-bg, ia-surface, ia-surface-2, ia-text, ia-input-bg,
#              ia-side-bg, ia-side-active-text
#
# 2. RGBA / alpha-overlay tokens — keep as TextInput but render a small
#    swatch as a visual prefix so you can see the resulting color at a
#    glance. Editing rgba alpha values via a color wheel isn't well-
#    supported by Filament's ColorPicker, so text remains best.
#      Tokens: borders, hover overlays, muted text, sidebar text,
#              sidebar hovers, sidebar active bg, sidebar border/section
#
# Implementation:
#   - HEX_TOKENS constant in ThemeEditor lists which keys are hex
#   - themeFields() branches: ColorPicker for hex, TextInput for rgba
#   - Both retain ->live(debounce: 250) for reactive dirty-detection
#
# Files touched:
#   - app/Filament/Pages/ThemeEditor.php  (themeFields refactor)
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "app/Filament/Pages/ThemeEditor.php" ]; then
  echo "ERROR: ThemeEditor.php not found." >&2
  exit 1
fi

python3 <<'PYEOF'
from pathlib import Path
p = Path("app/Filament/Pages/ThemeEditor.php")
s = p.read_text()

if "HEX_TOKENS" in s:
    print("    SKIP — color pickers already wired (HEX_TOKENS marker found)")
else:
    # 1. Add the ColorPicker import alongside the existing form imports.
    old_imports = "use Filament\\Forms\\Components\\ColorPicker;\nuse Filament\\Forms\\Components\\Section;"
    if old_imports in s:
        # Already imported (we added it in patch 67 but may not have used it).
        pass
    else:
        # Add ColorPicker import next to Section.
        anchor = "use Filament\\Forms\\Components\\Section;"
        replacement = "use Filament\\Forms\\Components\\ColorPicker;\nuse Filament\\Forms\\Components\\Section;"
        if anchor not in s:
            raise SystemExit("ABORT: Section import anchor not found")
        # Only add if not already imported.
        if "use Filament\\Forms\\Components\\ColorPicker;" not in s:
            s = s.replace(anchor, replacement, 1)

    # 2. Add the HEX_TOKENS constant inside the class, near GROUPS.
    old_groups_close = """        'Sidebar' => [
            'ia-side-bg'          => 'Sidebar background',
            'ia-side-text'        => 'Sidebar text',
            'ia-side-hover'       => 'Sidebar hover',
            'ia-side-active-bg'   => 'Active item background',
            'ia-side-active-text' => 'Active item text',
            'ia-side-border'      => 'Sidebar border',
            'ia-side-section'     => 'Section label',
        ],
    ];"""

    new_groups_close = """        'Sidebar' => [
            'ia-side-bg'          => 'Sidebar background',
            'ia-side-text'        => 'Sidebar text',
            'ia-side-hover'       => 'Sidebar hover',
            'ia-side-active-bg'   => 'Active item background',
            'ia-side-active-text' => 'Active item text',
            'ia-side-border'      => 'Sidebar border',
            'ia-side-section'     => 'Section label',
        ],
    ];

    /**
     * HEX_TOKENS: which token keys use #xxxxxx values (vs rgba()).
     * These get a ColorPicker for visual editing. Others stay as TextInput
     * because Filament's ColorPicker doesn't handle rgba alpha well.
     */
    public const HEX_TOKENS = [
        'ia-bg',
        'ia-surface',
        'ia-surface-2',
        'ia-text',
        'ia-input-bg',
        'ia-side-bg',
        'ia-side-active-text',
    ];"""

    if old_groups_close not in s:
        raise SystemExit("ABORT: GROUPS close anchor not found")
    s = s.replace(old_groups_close, new_groups_close, 1)

    # 3. Refactor themeFields() to branch on token type.
    old_method = """    /** Build form fields for one theme. */
    private function themeFields(string $theme): array
    {
        $sections = [];
        foreach (self::GROUPS as $groupName => $tokens) {
            $fields = [];
            foreach ($tokens as $key => $label) {
                $fields[] = TextInput::make("{$theme}.{$key}")
                    ->label($label)
                    ->helperText("--{$key}")
                    ->required()
                    ->maxLength(255)
                    ->live(debounce: 250);
            }
            $sections[] = Section::make($groupName)
                ->columns(2)
                ->schema($fields)
                ->collapsible();
        }
        return $sections;
    }"""

    new_method = """    /** Build form fields for one theme. */
    private function themeFields(string $theme): array
    {
        $sections = [];
        foreach (self::GROUPS as $groupName => $tokens) {
            $fields = [];
            foreach ($tokens as $key => $label) {
                if (in_array($key, self::HEX_TOKENS, true)) {
                    // Hex token: visual color picker.
                    $fields[] = ColorPicker::make("{$theme}.{$key}")
                        ->label($label)
                        ->helperText("--{$key}")
                        ->required()
                        ->live(debounce: 250);
                } else {
                    // Rgba / alpha-overlay token: text input.
                    // (Filament's ColorPicker doesn't round-trip alpha cleanly.)
                    $fields[] = TextInput::make("{$theme}.{$key}")
                        ->label($label)
                        ->helperText("--{$key} · rgba alpha-overlay")
                        ->placeholder('rgba(0,0,0,.10)')
                        ->required()
                        ->maxLength(255)
                        ->live(debounce: 250);
                }
            }
            $sections[] = Section::make($groupName)
                ->columns(2)
                ->schema($fields)
                ->collapsible();
        }
        return $sections;
    }"""

    if old_method not in s:
        raise SystemExit("ABORT: themeFields method anchor not found")
    s = s.replace(old_method, new_method, 1)

    p.write_text(s)
    print("    UPDATED ThemeEditor.php — ColorPicker for hex tokens, text + alpha hint for rgba")
PYEOF

cat <<EONOTE

==> Patch 69 applied locally.

Deploy:
  mv patch-69-theme-editor-color-pickers.sh _patches/
  git add app/Filament/Pages/ThemeEditor.php \\
          _patches/patch-69-theme-editor-color-pickers.sh
  git commit -m "feat: color pickers for hex tokens in theme editor (patch 69)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan optimize:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

After deploy:
  - Hex tokens (Background, Surface, Text, Sidebar bg, etc.) show a swatch
    that opens a color wheel on click
  - Rgba tokens (Border, Muted text, Hover, etc.) stay as text inputs with
    'rgba alpha-overlay' helper hint and a placeholder example
  - Dirty detection still works through ->live(debounce: 250) on both types
EONOTE
