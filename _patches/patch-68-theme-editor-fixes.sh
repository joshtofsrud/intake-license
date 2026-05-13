#!/bin/bash
# ============================================================================
# patch-68-theme-editor-fixes.sh
# ----------------------------------------------------------------------------
# Fixes two issues with the theme editor (patch 67):
#
# 1. BUG: Publish and Revert buttons never enable because form fields lack
#    ->live(). Without it, Livewire only syncs on form submit, but we use
#    wire:click handlers not a form action. Adding ->live(debounce: 250)
#    makes the banner reactive to keystrokes.
#
# 2. UX: Publish + Revert moved into a sticky banner at the top, with the
#    duplicate buttons below the form removed.
#
# Files touched:
#   - app/Filament/Pages/ThemeEditor.php
#   - resources/views/filament/pages/theme-editor.blade.php
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "app/Filament/Pages/ThemeEditor.php" ]; then
  echo "ERROR: ThemeEditor.php not found." >&2
  exit 1
fi
if [ ! -f "resources/views/filament/pages/theme-editor.blade.php" ]; then
  echo "ERROR: theme-editor.blade.php not found." >&2
  exit 1
fi

# ─── 1. ThemeEditor.php — add ->live() to form fields ─────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("app/Filament/Pages/ThemeEditor.php")
s = p.read_text()

old_field = """                $fields[] = TextInput::make("{$theme}.{$key}")
                    ->label($label)
                    ->helperText("--{$key}")
                    ->required()
                    ->maxLength(255);"""

new_field = """                $fields[] = TextInput::make("{$theme}.{$key}")
                    ->label($label)
                    ->helperText("--{$key}")
                    ->required()
                    ->maxLength(255)
                    ->live(debounce: 250);"""

if "->live(debounce" in s:
    print("    SKIP form fields — already ->live()")
elif old_field not in s:
    raise SystemExit("ABORT: form field anchor not found")
else:
    s = s.replace(old_field, new_field, 1)
    p.write_text(s)
    print("    UPDATED TextInput fields — added ->live(debounce: 250)")
PYEOF

# ─── 2. blade — sticky banner, idempotency via STICKY_BANNER_V1 marker ──
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/filament/pages/theme-editor.blade.php")
s = p.read_text()

if "STICKY_BANNER_V1" in s:
    print("    SKIP banner refactor — already sticky v1")
else:
    old_banner = """    {{-- Top status banner --}}
    @if($dirty > 0)
        <div style="padding: 14px 18px; border-radius: 10px; margin-bottom: 18px;
                    background: rgba(244,184,96,.10); border: 1px solid rgba(244,184,96,.35);">
            <div style="font-weight: 600; font-size: 14px; margin-bottom: 2px;">
                ⚠ Draft mode · {{ $dirty }} unpublished {{ $dirty === 1 ? 'change' : 'changes' }}
            </div>
            <div style="font-size: 12.5px; opacity: .7;">
                Tenants still see the previously published values until you click <strong>Publish</strong>.
            </div>
        </div>
    @else
        <div style="padding: 12px 18px; border-radius: 10px; margin-bottom: 18px;
                    background: rgba(90,168,224,.08); border: 1px solid rgba(90,168,224,.25);
                    font-size: 13px;">
            ● All changes published. Edit any value below to start a new draft.
        </div>
    @endif"""

    new_banner = """    {{-- STICKY_BANNER_V1 · status + Publish/Revert always visible --}}
    <div style="position: sticky; top: 0; z-index: 30;
                background: {{ $dirty > 0 ? 'rgba(244,184,96,.10)' : 'rgba(90,168,224,.08)' }};
                border: 1px solid {{ $dirty > 0 ? 'rgba(244,184,96,.40)' : 'rgba(90,168,224,.25)' }};
                border-radius: 10px;
                padding: 12px 18px;
                margin-bottom: 18px;
                backdrop-filter: blur(8px);
                display: flex; align-items: center; justify-content: space-between;
                gap: 16px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 240px;">
            @if($dirty > 0)
                <div style="font-weight: 600; font-size: 14px;">
                    ⚠ Draft mode · {{ $dirty }} unpublished {{ $dirty === 1 ? 'change' : 'changes' }}
                </div>
                <div style="font-size: 12.5px; opacity: .7; margin-top: 2px;">
                    Tenants still see published values until you click Publish.
                </div>
            @else
                <div style="font-size: 13px; font-weight: 500;">
                    ● All changes published.
                    <span style="opacity: .65; font-weight: 400;">
                        Edit any value below to start a new draft.
                    </span>
                </div>
            @endif
        </div>
        <div style="display: flex; gap: 8px; flex-shrink: 0;">
            <x-filament::button
                wire:click="revert"
                color="gray"
                size="sm"
                :disabled="$dirty === 0">
                Revert
            </x-filament::button>
            <x-filament::button
                wire:click="publish"
                size="sm"
                :disabled="$dirty === 0">
                @if($dirty > 0)
                    Publish {{ $dirty }} {{ $dirty === 1 ? 'change' : 'changes' }}
                @else
                    Publish
                @endif
            </x-filament::button>
        </div>
    </div>"""

    if old_banner not in s:
        raise SystemExit("ABORT: top banner anchor not found")
    s = s.replace(old_banner, new_banner, 1)

    old_bottom = """            <div style="margin-top: 18px; display: flex; gap: 8px;">
                <x-filament::button wire:click="publish" :disabled="$dirty === 0">
                    @if($dirty > 0)
                        Publish {{ $dirty }} {{ $dirty === 1 ? 'change' : 'changes' }}
                    @else
                        Publish
                    @endif
                </x-filament::button>

                <x-filament::button wire:click="revert" color="gray" :disabled="$dirty === 0">
                    Revert
                </x-filament::button>
            </div>"""

    if old_bottom not in s:
        raise SystemExit("ABORT: bottom buttons anchor not found")
    s = s.replace(old_bottom, "", 1)

    p.write_text(s)
    print("    UPDATED — sticky top banner, removed duplicate bottom buttons")
PYEOF

cat <<EONOTE

==> Patch 68 applied locally.

Deploy:
  mv patch-68-theme-editor-fixes.sh _patches/
  git add app/Filament/Pages/ThemeEditor.php \\
          resources/views/filament/pages/theme-editor.blade.php \\
          _patches/patch-68-theme-editor-fixes.sh
  git commit -m "fix: theme editor live state + sticky action banner (patch 68)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan optimize:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

After deploy:
  - Edit a token value, banner updates within ~250ms
  - Publish/Revert buttons enable
  - Banner sticks to top as you scroll
  - Publish writes the change, notification confirms, banner resets to clean
EONOTE
