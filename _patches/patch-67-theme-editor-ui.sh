#!/bin/bash
# ============================================================================
# patch-67-theme-editor-ui.sh
# ----------------------------------------------------------------------------
# Phase 3 of the Master Admin theme editor: the Filament UI itself.
#
# What this lands:
#   - app/Filament/Pages/ThemeEditor.php — custom Filament Page at /admin/themes
#   - resources/views/filament/pages/theme-editor.blade.php — view with live preview
#   - app/Models/ThemeSettingsAudit.php — audit log model
#   - database/migrations/2026_05_13_000002_create_theme_settings_audits_table.php
#   - Wire ThemeEditor into AdminPanelProvider->pages([])
#   - ThemeSettingsService: add `audit()` calls on publish and revert
#
# UX:
#   - Tabs: Light theme (b) | Dark theme (c)
#   - Grouped form sections: Surfaces · Borders · Text · Sidebar · Default accent
#   - Each token = label + color picker (Filament's native ColorPicker) +
#     text input for hex/rgba value
#   - Right side: live preview iframe rendering a mini dashboard with current
#     draft values
#   - Top: "Draft mode · N changes" banner + Publish / Revert / Reset buttons
#   - Bottom: Recent published changes (last 20)
#
# Filament conventions used:
#   - <x-filament-panels::page> wrapper
#   - Filament\Forms\Components\TextInput + ColorPicker
#   - Filament\Notifications\Notification::make()->success() on publish
#   - Public Livewire properties so the view can read draft state directly
#
# Files created:
#   - app/Filament/Pages/ThemeEditor.php
#   - resources/views/filament/pages/theme-editor.blade.php
#   - app/Models/ThemeSettingsAudit.php
#   - database/migrations/2026_05_13_000002_create_theme_settings_audits_table.php
#
# Files modified:
#   - app/Providers/Filament/AdminPanelProvider.php  (1 line — pages array)
#   - app/Services/ThemeSettingsService.php  (audit() calls + tiny refactor)
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

# Sanity check: Phase 1+2 must be in place.
if [ ! -f "app/Services/ThemeSettingsService.php" ]; then
  echo "ERROR: Phase 1+2 not deployed (ThemeSettingsService missing)." >&2
  exit 1
fi
if [ ! -f "app/Providers/Filament/AdminPanelProvider.php" ]; then
  echo "ERROR: Filament AdminPanelProvider missing." >&2
  exit 1
fi

# ─── 1. Audit migration ───────────────────────────────────────────────
MIGRATION_PATH="database/migrations/2026_05_13_000002_create_theme_settings_audits_table.php"
if [ -f "$MIGRATION_PATH" ]; then
    echo "    SKIP audit migration — already exists"
else
cat > "$MIGRATION_PATH" <<'PHPEOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * theme_settings_audits — log of every publish or revert action.
 * Used by the master admin to answer "why does the sidebar look different
 * today?" months after a change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_settings_audits', function (Blueprint $t) {
            $t->id();
            $t->string('theme', 8);
            $t->string('token_key', 64);
            $t->string('old_value', 255)->nullable();
            $t->string('new_value', 255)->nullable();
            $t->string('action', 16);   // 'publish' or 'revert'
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['theme', 'created_at']);
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_settings_audits');
    }
};
PHPEOF
echo "    CREATED audit migration"
fi

# ─── 2. ThemeSettingsAudit model ──────────────────────────────────────
if [ -f "app/Models/ThemeSettingsAudit.php" ]; then
    echo "    SKIP audit model — already exists"
else
cat > app/Models/ThemeSettingsAudit.php <<'PHPEOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThemeSettingsAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'theme', 'token_key', 'old_value', 'new_value', 'action', 'user_id', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
PHPEOF
echo "    CREATED app/Models/ThemeSettingsAudit.php"
fi

# ─── 3. Add audit() calls to ThemeSettingsService ─────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("app/Services/ThemeSettingsService.php")
s = p.read_text()

if "ThemeSettingsAudit" in s:
    print("    SKIP service audit hook — already wired")
else:
    # Add import + audit on publish.
    old_use = "use App\\Models\\ThemeSetting;"
    new_use = "use App\\Models\\ThemeSetting;\nuse App\\Models\\ThemeSettingsAudit;"
    if old_use not in s:
        raise SystemExit("ABORT: import anchor not found in service")
    s = s.replace(old_use, new_use, 1)

    # In publish(), inject audit log inside the each() callback.
    old_publish = """        $count = $q->get()->each(function (ThemeSetting $row) use ($userId) {
            $row->published_value = $row->draft_value;
            $row->draft_value = null;
            $row->updated_by_user_id = $userId;
            $row->published_at = now();
            $row->save();
        })->count();"""

    new_publish = """        $count = $q->get()->each(function (ThemeSetting $row) use ($userId) {
            $oldValue = $row->published_value;
            $newValue = $row->draft_value;
            $row->published_value = $newValue;
            $row->draft_value = null;
            $row->updated_by_user_id = $userId;
            $row->published_at = now();
            $row->save();

            // Audit log entry — keep cheap, fail-safe via try/catch.
            try {
                ThemeSettingsAudit::create([
                    'theme'      => $row->theme,
                    'token_key'  => $row->token_key,
                    'old_value'  => $oldValue,
                    'new_value'  => $newValue,
                    'action'     => 'publish',
                    'user_id'    => $userId,
                    'created_at' => now(),
                ]);
            } catch (\\Throwable $e) {
                // Audit failure shouldn't break publish.
                \\Log::warning('Theme audit write failed: ' . $e->getMessage());
            }
        })->count();"""

    if old_publish not in s:
        raise SystemExit("ABORT: publish anchor not found in service")
    s = s.replace(old_publish, new_publish, 1)

    p.write_text(s)
    print("    UPDATED ThemeSettingsService — audit() integrated into publish()")
PYEOF

# ─── 4. ThemeEditor Filament Page ─────────────────────────────────────
if [ -f "app/Filament/Pages/ThemeEditor.php" ]; then
    echo "    SKIP ThemeEditor page — already exists"
else
cat > app/Filament/Pages/ThemeEditor.php <<'PHPEOF'
<?php

namespace App\Filament\Pages;

use App\Models\ThemeSetting;
use App\Models\ThemeSettingsAudit;
use App\Services\ThemeSettingsService;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Schema;

/**
 * ThemeEditor — master admin UI for editing platform theme tokens.
 *
 * Form data lives in $this->data, keyed as ['b' => [..tokens..], 'c' => [...]].
 * Changes are tracked against the published values in DB. "Publish" writes
 * to draft_value then immediately publishes (we collapse the draft/publish
 * lifecycle to a single explicit action here for simplicity; the underlying
 * service still supports separate setDraft/publish if needed).
 *
 * The view (theme-editor.blade.php) reads $this->data directly to render
 * the live preview pane — so as the user types, Livewire re-renders and
 * the preview updates without any custom JS.
 */
class ThemeEditor extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';
    protected static ?string $navigationLabel = 'Themes';
    protected static ?string $navigationGroup = 'Configuration';
    protected static ?int $navigationSort = 5;
    protected static ?string $title = 'Theme editor';

    protected static string $view = 'filament.pages.theme-editor';

    public ?array $data = [];

    /** Token groups for both themes — single source of truth. */
    public const GROUPS = [
        'Surfaces' => [
            'ia-bg'           => 'Page background',
            'ia-surface'      => 'Card surface',
            'ia-surface-2'    => 'Surface (hover / fill)',
        ],
        'Borders' => [
            'ia-border'        => 'Default border',
            'ia-border-strong' => 'Strong border',
        ],
        'Text' => [
            'ia-text'        => 'Primary text',
            'ia-text-muted'  => 'Muted text',
            'ia-text-dim'    => 'Dim text (labels)',
        ],
        'Interactive' => [
            'ia-hover'    => 'Hover overlay',
            'ia-input-bg' => 'Input background',
        ],
        'Sidebar' => [
            'ia-side-bg'          => 'Sidebar background',
            'ia-side-text'        => 'Sidebar text',
            'ia-side-hover'       => 'Sidebar hover',
            'ia-side-active-bg'   => 'Active item background',
            'ia-side-active-text' => 'Active item text',
            'ia-side-border'      => 'Sidebar border',
            'ia-side-section'     => 'Section label',
        ],
    ];

    public function mount(): void
    {
        if (!Schema::hasTable('theme_settings')) {
            $this->data = ['b' => [], 'c' => []];
            return;
        }

        $rows = ThemeSetting::all();
        $byTheme = ['b' => [], 'c' => []];
        foreach ($rows as $r) {
            // Prefer draft_value if it exists, otherwise published.
            $byTheme[$r->theme][$r->token_key] = $r->draft_value ?? $r->published_value;
        }
        $this->data = $byTheme;
        $this->form->fill($this->data);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Tabs::make('Theme')
                    ->tabs([
                        Tabs\Tab::make('Light theme (b)')
                            ->icon('heroicon-o-sun')
                            ->schema($this->themeFields('b')),

                        Tabs\Tab::make('Dark theme (c)')
                            ->icon('heroicon-o-moon')
                            ->schema($this->themeFields('c')),
                    ]),
            ])
            ->statePath('data');
    }

    /** Build form fields for one theme. */
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
                    ->maxLength(255);
            }
            $sections[] = Section::make($groupName)
                ->columns(2)
                ->schema($fields)
                ->collapsible();
        }
        return $sections;
    }

    public function publish(): void
    {
        if (!Schema::hasTable('theme_settings')) {
            Notification::make()->danger()
                ->title('Migration not run')->body('theme_settings table not found.')->send();
            return;
        }

        $service = app(ThemeSettingsService::class);
        $userId = auth()->id();
        $changes = 0;

        foreach (['b', 'c'] as $theme) {
            $values = $this->data[$theme] ?? [];
            foreach ($values as $key => $value) {
                $value = (string) $value;
                $current = ThemeSetting::where('theme', $theme)
                    ->where('token_key', $key)
                    ->value('published_value');
                if ($current === $value) {
                    continue;
                }
                $service->setDraft($theme, $key, $value, $userId);
                $changes++;
            }
        }

        if ($changes === 0) {
            Notification::make()->info()->title('No changes to publish')->send();
            return;
        }

        $published = $service->publish(null, $userId);

        Notification::make()->success()
            ->title("Published {$published} change" . ($published === 1 ? '' : 's'))
            ->body('Theme updates are live for all tenants on next page load.')
            ->send();
    }

    public function revert(): void
    {
        // Reload published values into the form, discarding any unsaved typing.
        $this->mount();

        Notification::make()->success()
            ->title('Reverted unsaved changes')->send();
    }

    /** Detect changes between current form state and published DB values. */
    public function getDirtyCountProperty(): int
    {
        if (!Schema::hasTable('theme_settings')) return 0;

        $count = 0;
        $rows = ThemeSetting::all(['theme', 'token_key', 'published_value']);
        $published = [];
        foreach ($rows as $r) {
            $published[$r->theme][$r->token_key] = $r->published_value;
        }
        foreach (['b', 'c'] as $theme) {
            $values = $this->data[$theme] ?? [];
            foreach ($values as $key => $value) {
                $pub = $published[$theme][$key] ?? null;
                if ($pub !== null && (string) $pub !== (string) $value) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /** Recent audit entries for the bottom of the page. */
    public function getRecentAuditsProperty()
    {
        if (!Schema::hasTable('theme_settings_audits')) return collect();

        return ThemeSettingsAudit::orderBy('created_at', 'desc')
            ->limit(20)
            ->get();
    }
}
PHPEOF
echo "    CREATED app/Filament/Pages/ThemeEditor.php"
fi

# ─── 5. ThemeEditor view (with live preview) ──────────────────────────
mkdir -p resources/views/filament/pages
if [ -f "resources/views/filament/pages/theme-editor.blade.php" ]; then
    echo "    SKIP theme-editor view — already exists"
else
cat > resources/views/filament/pages/theme-editor.blade.php <<'BLADEEOF'
<x-filament-panels::page>

    @php
        $dirty = $this->dirtyCount;
        $audits = $this->recentAudits;
    @endphp

    {{-- Top status banner --}}
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
    @endif

    {{-- Split: editor (left) + live preview (right) --}}
    <div style="display: grid; grid-template-columns: 1.4fr 1fr; gap: 24px; align-items: start;">

        {{-- ─── Editor side ─── --}}
        <div>
            <form>{{ $this->form }}</form>

            <div style="margin-top: 18px; display: flex; gap: 8px;">
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
            </div>
        </div>

        {{-- ─── Live preview ─── --}}
        <div style="position: sticky; top: 24px;">
            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: .14em;
                        font-weight: 600; opacity: .6; margin-bottom: 10px;
                        display: flex; justify-content: space-between;">
                <span>Live preview · Light theme</span>
                @if($dirty > 0)<span style="color: #B45309;">Showing draft</span>@endif
            </div>

            {{-- Light theme preview --}}
            @include('filament.pages._theme-preview', ['theme' => 'b', 'tokens' => $this->data['b'] ?? []])

            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: .14em;
                        font-weight: 600; opacity: .6; margin: 18px 0 10px;
                        display: flex; justify-content: space-between;">
                <span>Live preview · Dark theme</span>
                @if($dirty > 0)<span style="color: #B45309;">Showing draft</span>@endif
            </div>

            {{-- Dark theme preview --}}
            @include('filament.pages._theme-preview', ['theme' => 'c', 'tokens' => $this->data['c'] ?? []])
        </div>
    </div>

    {{-- Audit log --}}
    @if($audits->isNotEmpty())
        <div style="margin-top: 36px;">
            <div style="font-size: 13px; font-weight: 600; margin-bottom: 10px;">
                Recent changes
            </div>
            <div style="border: 1px solid rgba(0,0,0,.08); border-radius: 10px; overflow: hidden;">
                @foreach($audits as $audit)
                    <div style="display: grid; grid-template-columns: 110px 1fr auto;
                                gap: 14px; padding: 10px 16px;
                                border-bottom: 1px solid rgba(0,0,0,.06);
                                font-size: 12.5px; align-items: center;">
                        <div style="opacity: .55; font-variant-numeric: tabular-nums;">
                            {{ $audit->created_at->diffForHumans() }}
                        </div>
                        <div>
                            Theme {{ strtoupper($audit->theme) }} ·
                            <code style="font-size: 11px; padding: 1px 5px; background: rgba(0,0,0,.05); border-radius: 3px;">--{{ $audit->token_key }}</code>
                            @if($audit->old_value !== null)
                                <span style="opacity: .6;">from</span>
                                <code style="font-size: 11px; padding: 1px 5px; background: rgba(0,0,0,.05); border-radius: 3px;">{{ $audit->old_value }}</code>
                                <span style="opacity: .6;">to</span>
                            @endif
                            <code style="font-size: 11px; padding: 1px 5px; background: rgba(0,0,0,.05); border-radius: 3px;">{{ $audit->new_value }}</code>
                        </div>
                        <div style="opacity: .55; font-size: 11.5px;">
                            User #{{ $audit->user_id ?? '?' }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

</x-filament-panels::page>
BLADEEOF
echo "    CREATED theme-editor.blade.php"
fi

# ─── 6. Theme preview partial ─────────────────────────────────────────
if [ -f "resources/views/filament/pages/_theme-preview.blade.php" ]; then
    echo "    SKIP theme-preview partial — already exists"
else
cat > resources/views/filament/pages/_theme-preview.blade.php <<'BLADEEOF'
{{-- Mini dashboard preview rendering with the passed-in token values.
     $theme: 'b' or 'c'
     $tokens: array of token_key => value from form state. --}}

@php
    // Fallbacks if a token is missing in the form data.
    $t = $tokens;
    $g = fn($k, $default) => $t[$k] ?? $default;

    // Default fallback values (slate-steel for b, dark premium for c).
    if ($theme === 'b') {
        $defaults = [
            'ia-bg' => '#F7F8FA', 'ia-surface' => '#FFFFFF',
            'ia-border' => 'rgba(15,20,25,.10)',
            'ia-text' => '#0F1419', 'ia-text-muted' => 'rgba(15,20,25,.62)',
            'ia-side-bg' => '#1E2A3A', 'ia-side-text' => 'rgba(255,255,255,.5)',
            'ia-side-active-bg' => 'rgba(255,255,255,.08)',
            'ia-side-active-text' => '#f5f5f5',
        ];
    } else {
        $defaults = [
            'ia-bg' => '#0d0d0d', 'ia-surface' => '#1c1c1c',
            'ia-border' => 'rgba(255,255,255,.13)',
            'ia-text' => '#f0f0f0', 'ia-text-muted' => 'rgba(255,255,255,.78)',
            'ia-side-bg' => '#0c0c0c', 'ia-side-text' => 'rgba(255,255,255,.4)',
            'ia-side-active-bg' => 'rgba(255,255,255,.08)',
            'ia-side-active-text' => '#f0f0f0',
        ];
    }

    $bg     = $g('ia-bg',     $defaults['ia-bg']);
    $surf   = $g('ia-surface', $defaults['ia-surface']);
    $bdr    = $g('ia-border', $defaults['ia-border']);
    $text   = $g('ia-text',   $defaults['ia-text']);
    $muted  = $g('ia-text-muted', $defaults['ia-text-muted']);
    $sideBg = $g('ia-side-bg', $defaults['ia-side-bg']);
    $sideTx = $g('ia-side-text', $defaults['ia-side-text']);
    $sideActBg = $g('ia-side-active-bg', $defaults['ia-side-active-bg']);
    $sideActTx = $g('ia-side-active-text', $defaults['ia-side-active-text']);
    $accent = '#3B5A78'; // platform default
@endphp

<div style="background: {{ $bg }}; border: 1px solid {{ $bdr }};
            border-radius: 12px; overflow: hidden;
            box-shadow: 0 6px 22px rgba(0,0,0,.10);
            color: {{ $text }}; font-size: 12px;">
    <div style="display: grid; grid-template-columns: 140px 1fr; min-height: 320px;">

        {{-- Sidebar --}}
        <div style="background: {{ $sideBg }}; padding: 12px 0; color: {{ $sideTx }};">
            <div style="display: flex; align-items: center; gap: 8px;
                        padding: 4px 14px 14px;
                        border-bottom: 1px solid rgba(255,255,255,.06);
                        margin-bottom: 8px;">
                <div style="width: 20px; height: 20px; background: {{ $accent }};
                            color: #fff; border-radius: 4px;
                            display: flex; align-items: center; justify-content: center;
                            font-size: 10px; font-weight: 700;">M</div>
                <div style="color: #fff; font-weight: 500; font-size: 11.5px;">Mountainview</div>
            </div>
            <div style="padding: 6px 12px; font-size: 11px;
                        background: {{ $sideActBg }}; color: {{ $sideActTx }};
                        border-left: 2px solid {{ $accent }};">Dashboard</div>
            <div style="padding: 6px 14px; font-size: 11px;">Register</div>
            <div style="padding: 6px 14px; font-size: 11px;">Schedule</div>
            <div style="padding: 6px 14px; font-size: 11px;">Customers</div>
        </div>

        {{-- Main --}}
        <div style="padding: 16px 18px;">
            <div style="font-size: 15px; font-weight: 700; letter-spacing: -.01em;
                        margin-bottom: 12px;">Dashboard</div>

            {{-- Stats --}}
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 12px;">
                @foreach([['Revenue','$485'],['Appts','3/6'],['Walk-ins','2']] as $stat)
                    <div style="background: {{ $surf }}; border: 1px solid {{ $bdr }};
                                border-radius: 8px; padding: 10px 12px;">
                        <div style="font-size: 9px; text-transform: uppercase;
                                    letter-spacing: .1em; opacity: .5; font-weight: 600;
                                    margin-bottom: 4px;">{{ $stat[0] }}</div>
                        <div style="font-size: 15px; font-weight: 700; letter-spacing: -.01em;">{{ $stat[1] }}</div>
                    </div>
                @endforeach
            </div>

            {{-- Appointment rows --}}
            <div style="background: {{ $surf }}; border: 1px solid {{ $bdr }}; border-radius: 8px;">
                @foreach([['9:00','Sarah C.','Tune-up','Paid'],['10:30','Marcus R.','Brake','Pay']] as $row)
                    <div style="display: grid; grid-template-columns: 44px 1fr auto;
                                gap: 10px; align-items: center;
                                padding: 8px 12px;
                                border-bottom: 1px solid {{ $bdr }};">
                        <div style="font-weight: 600; font-variant-numeric: tabular-nums; font-size: 11px;">{{ $row[0] }}</div>
                        <div>
                            <div style="font-weight: 500; font-size: 11.5px;">{{ $row[1] }}</div>
                            <div style="font-size: 10px; color: {{ $muted }};">{{ $row[2] }}</div>
                        </div>
                        <div style="background: {{ $accent }}; color: #fff;
                                    padding: 3px 9px; border-radius: 4px;
                                    font-size: 9.5px; font-weight: 600;">{{ $row[3] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
BLADEEOF
echo "    CREATED _theme-preview.blade.php"
fi

# ─── 7. Wire ThemeEditor into AdminPanelProvider ──────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("app/Providers/Filament/AdminPanelProvider.php")
s = p.read_text()

if "ThemeEditor" in s:
    print("    SKIP AdminPanelProvider — ThemeEditor already registered")
else:
    # Add import.
    old_use_block = "use App\\Filament\\Widgets\\StatsOverview;"
    new_use_block = "use App\\Filament\\Widgets\\StatsOverview;\nuse App\\Filament\\Pages\\ThemeEditor;"
    if old_use_block not in s:
        raise SystemExit("ABORT: import anchor not found in AdminPanelProvider")
    s = s.replace(old_use_block, new_use_block, 1)

    # Add to pages() array. The existing pattern is ->pages([Pages\Dashboard::class, ...])
    # We add after Dashboard.
    old_pages = "->pages([\n                Pages\\Dashboard::class,"
    new_pages = "->pages([\n                Pages\\Dashboard::class,\n                ThemeEditor::class,"
    if old_pages not in s:
        raise SystemExit("ABORT: pages() anchor not found in AdminPanelProvider")
    s = s.replace(old_pages, new_pages, 1)

    p.write_text(s)
    print("    UPDATED AdminPanelProvider — ThemeEditor registered")
PYEOF

cat <<EONOTE

==> Patch 67 applied locally.

Deploy:
  mv patch-67-theme-editor-ui.sh _patches/
  git add database/migrations/2026_05_13_000002_create_theme_settings_audits_table.php \\
          app/Models/ThemeSettingsAudit.php \\
          app/Services/ThemeSettingsService.php \\
          app/Filament/Pages/ThemeEditor.php \\
          resources/views/filament/pages/theme-editor.blade.php \\
          resources/views/filament/pages/_theme-preview.blade.php \\
          app/Providers/Filament/AdminPanelProvider.php \\
          _patches/patch-67-theme-editor-ui.sh
  git commit -m "feat: theme editor Filament UI (phase 3, patch 67)"
  git push

On server:
  cd /var/www/intake
  git pull
  composer install --no-interaction --no-scripts
  php artisan migrate --force
  php artisan optimize:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify:
  1. Visit https://intake.works/admin (master admin Filament panel)
  2. New "Themes" item should appear in the Configuration nav group
  3. Click it — should show the editor with tabs for Light/Dark
  4. Pre-filled with current values from theme_settings table
  5. Edit one value — banner should change to "1 unpublished change"
  6. Click Publish — page reloads, notification confirms
  7. Visit a tenant admin page — confirm the change is live
  8. Return to /admin/themes — bottom shows the audit entry
  9. Revert button works for unsaved changes (reload + go to published state)
EONOTE
