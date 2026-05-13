#!/bin/bash
# ============================================================================
# patch-66-theme-settings-plumbing.sh
# ----------------------------------------------------------------------------
# Phase 1 + 2 of the Master Admin theme editor. Plumbing only — no UI yet.
#
# What this lands:
#   - theme_settings table (key/value, draft + published, per theme)
#   - ThemeSetting model + ThemeSettingsService
#   - Seeder that captures current values from theme-b.css and theme-c.css
#   - ThemeOverrideHelper::styleTag() emits a <style> block
#   - app.blade.php injects override style block in <head>
#
# What this does NOT touch:
#   - The :root blocks inside theme-b.css and theme-c.css
#   - The Filament UI for editing
#   - The audit log
#   - The accent picker on tenant settings page
#
# Why we keep the :root blocks in CSS: they act as fallback values if the DB
# is unreachable or the table doesn't exist yet. The injected <style> block
# uses higher CSS specificity (html.ia-theme-X selector) so it wins when
# present. This is a safe-by-default deploy — even a botched migration can't
# break visual output.
#
# After this ships, future patches can edit DB rows to change theme colors
# without a CSS deploy.
#
# Files created:
#   - database/migrations/2026_05_13_000001_create_theme_settings_table.php
#   - app/Models/ThemeSetting.php
#   - app/Services/ThemeSettingsService.php
#   - app/Support/ThemeOverrideHelper.php
#   - database/seeders/ThemeSettingsSeeder.php
#
# Files modified:
#   - resources/views/layouts/tenant/app.blade.php  (1 line added in <head>)
#   - database/seeders/DatabaseSeeder.php  (1 line added — if it exists)
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

# ─── 1. Migration ─────────────────────────────────────────────────────
MIGRATION_PATH="database/migrations/2026_05_13_000001_create_theme_settings_table.php"
if [ -f "$MIGRATION_PATH" ]; then
    echo "    SKIP migration — already exists"
else
cat > "$MIGRATION_PATH" <<'PHPEOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * theme_settings — platform-wide theme token overrides.
 *
 * One row per (theme × token). The published_value is what tenants see;
 * draft_value is the master admin's pending edit. publish() copies draft
 * to published. revert_to_default() nulls both (returns to seeded value).
 *
 * Tenants never touch this table directly — it's master-admin-only.
 * Tenant-customized accent_color still lives on tenants.accent_color
 * and overrides --ia-accent via inline style in app.blade.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_settings', function (Blueprint $t) {
            $t->id();
            $t->string('theme', 8);              // 'b' or 'c'
            $t->string('token_key', 64);         // 'ia-bg', 'ia-side-bg', etc. (without --)
            $t->string('published_value', 255);  // active value, never null after seed
            $t->string('draft_value', 255)->nullable();
            $t->unsignedBigInteger('updated_by_user_id')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();

            $t->unique(['theme', 'token_key']);
            $t->index('theme');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_settings');
    }
};
PHPEOF
echo "    CREATED migration: $MIGRATION_PATH"
fi

# ─── 2. Model ─────────────────────────────────────────────────────────
if [ -f "app/Models/ThemeSetting.php" ]; then
    echo "    SKIP model — already exists"
else
cat > app/Models/ThemeSetting.php <<'PHPEOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ThemeSetting — one row per (theme × token).
 *
 * theme:           'b' (light) or 'c' (dark)
 * token_key:       'ia-bg', 'ia-surface', 'ia-side-bg', etc. (no leading --)
 * published_value: live value tenants see
 * draft_value:     master admin's pending edit (null = no pending change)
 *
 * Use ThemeSettingsService for reads, writes, publish, revert.
 */
class ThemeSetting extends Model
{
    protected $fillable = [
        'theme', 'token_key', 'published_value', 'draft_value',
        'updated_by_user_id', 'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];
}
PHPEOF
echo "    CREATED app/Models/ThemeSetting.php"
fi

# ─── 3. Service ───────────────────────────────────────────────────────
if [ -f "app/Services/ThemeSettingsService.php" ]; then
    echo "    SKIP service — already exists"
else
cat > app/Services/ThemeSettingsService.php <<'PHPEOF'
<?php

namespace App\Services;

use App\Models\ThemeSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * ThemeSettingsService — reads + writes platform theme tokens.
 *
 * Reads are cached aggressively (forever, busted on publish) because
 * theme values change rarely but are read on every tenant page load.
 *
 * Safe-by-default: if the table doesn't exist (migration not run yet,
 * fresh install, etc.) all methods return empty arrays so the helper
 * emits no <style> block and CSS files remain authoritative.
 */
class ThemeSettingsService
{
    private const CACHE_KEY_PUBLISHED = 'theme_settings.published.v1';
    private const CACHE_KEY_DRAFT     = 'theme_settings.draft.v1';

    /**
     * All published values grouped by theme.
     * Returns: ['b' => ['ia-bg' => '#F7F8FA', ...], 'c' => [...]]
     */
    public function published(): array
    {
        if (!Schema::hasTable('theme_settings')) {
            return [];
        }
        return Cache::rememberForever(self::CACHE_KEY_PUBLISHED, function () {
            $rows = ThemeSetting::all(['theme', 'token_key', 'published_value']);
            $out = [];
            foreach ($rows as $r) {
                $out[$r->theme][$r->token_key] = $r->published_value;
            }
            return $out;
        });
    }

    /**
     * Draft values grouped by theme (only rows with non-null draft_value).
     * Used by the master admin editor preview, not by tenant pages.
     */
    public function draft(): array
    {
        if (!Schema::hasTable('theme_settings')) {
            return [];
        }
        return Cache::rememberForever(self::CACHE_KEY_DRAFT, function () {
            $rows = ThemeSetting::whereNotNull('draft_value')
                ->get(['theme', 'token_key', 'draft_value']);
            $out = [];
            foreach ($rows as $r) {
                $out[$r->theme][$r->token_key] = $r->draft_value;
            }
            return $out;
        });
    }

    /**
     * Combined view: published values overlaid with drafts.
     * Used by the master admin preview pane.
     */
    public function effective(): array
    {
        $pub = $this->published();
        $drf = $this->draft();
        foreach ($drf as $theme => $tokens) {
            foreach ($tokens as $k => $v) {
                $pub[$theme][$k] = $v;
            }
        }
        return $pub;
    }

    /**
     * Set a draft value for one token. Does NOT publish.
     * Caller is responsible for tracking who's editing (passed in).
     */
    public function setDraft(string $theme, string $tokenKey, string $value, ?int $userId = null): void
    {
        $row = ThemeSetting::firstOrCreate(
            ['theme' => $theme, 'token_key' => $tokenKey],
            ['published_value' => $value]  // first-time write: also set as published
        );
        $row->draft_value = $value;
        $row->updated_by_user_id = $userId;
        $row->save();

        $this->bustCaches();
    }

    /**
     * Publish all draft values for a theme (or all themes if null).
     * Copies draft_value → published_value, clears draft, stamps published_at.
     */
    public function publish(?string $theme = null, ?int $userId = null): int
    {
        $q = ThemeSetting::whereNotNull('draft_value');
        if ($theme) {
            $q->where('theme', $theme);
        }
        $count = $q->get()->each(function (ThemeSetting $row) use ($userId) {
            $row->published_value = $row->draft_value;
            $row->draft_value = null;
            $row->updated_by_user_id = $userId;
            $row->published_at = now();
            $row->save();
        })->count();

        $this->bustCaches();
        return $count;
    }

    /**
     * Drop all drafts for a theme (or all themes if null).
     * Doesn't touch published values.
     */
    public function revertDraft(?string $theme = null): int
    {
        $q = ThemeSetting::whereNotNull('draft_value');
        if ($theme) {
            $q->where('theme', $theme);
        }
        $count = $q->update(['draft_value' => null]);

        $this->bustCaches();
        return $count;
    }

    public function bustCaches(): void
    {
        Cache::forget(self::CACHE_KEY_PUBLISHED);
        Cache::forget(self::CACHE_KEY_DRAFT);
    }
}
PHPEOF
echo "    CREATED app/Services/ThemeSettingsService.php"
fi

# ─── 4. Helper ────────────────────────────────────────────────────────
if [ -f "app/Support/ThemeOverrideHelper.php" ]; then
    echo "    SKIP helper — already exists"
else
cat > app/Support/ThemeOverrideHelper.php <<'PHPEOF'
<?php

namespace App\Support;

use App\Services\ThemeSettingsService;

/**
 * ThemeOverrideHelper — emits a <style> block for the active theme.
 *
 * Reads published values from ThemeSettingsService and writes them as
 * CSS variables under the appropriate `html.ia-theme-X` selector. Higher
 * specificity than the `body.ia-theme-X` rules in the static CSS files,
 * so DB values win when present. Empty array = empty output = no override.
 *
 * Output is plain text containing escaped values; not user input, but we
 * still strip quotes/newlines defensively.
 */
class ThemeOverrideHelper
{
    public static function styleTag(): string
    {
        $service = app(ThemeSettingsService::class);
        $all = $service->published();

        if (empty($all)) {
            return '';
        }

        $css = '';
        foreach ($all as $theme => $tokens) {
            if (empty($tokens)) {
                continue;
            }
            $css .= "html.ia-theme-{$theme} {\n";
            foreach ($tokens as $key => $value) {
                $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $key);
                $safeValue = self::sanitizeValue($value);
                if ($safeKey === '' || $safeValue === '') {
                    continue;
                }
                $css .= "  --{$safeKey}: {$safeValue};\n";
            }
            $css .= "}\n";
        }

        return "<style data-theme-overrides>\n{$css}</style>";
    }

    /**
     * Strip anything that doesn't belong in a CSS value: quotes, semicolons,
     * angle brackets, line breaks. Hex/rgba/var()/keywords pass through.
     */
    private static function sanitizeValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        // Reject anything with characters that would break the style block.
        if (preg_match('/[<>"\'{};]/', $value)) {
            return '';
        }
        // Reject linebreaks.
        if (preg_match('/[\r\n]/', $value)) {
            return '';
        }
        return $value;
    }
}
PHPEOF
echo "    CREATED app/Support/ThemeOverrideHelper.php"
fi

# ─── 5. Seeder ────────────────────────────────────────────────────────
if [ -f "database/seeders/ThemeSettingsSeeder.php" ]; then
    echo "    SKIP seeder — already exists"
else
cat > database/seeders/ThemeSettingsSeeder.php <<'PHPEOF'
<?php

namespace Database\Seeders;

use App\Models\ThemeSetting;
use Illuminate\Database\Seeder;

/**
 * Seeds the current hardcoded theme values from theme-b.css and theme-c.css
 * into the theme_settings table as published_value. Idempotent: uses
 * updateOrCreate keyed on (theme, token_key).
 *
 * After running, the values in the DB match the CSS files exactly. The
 * <style> block injected by ThemeOverrideHelper emits the same values that
 * the CSS file would have provided — zero visual change.
 */
class ThemeSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $tokens = [
            // ─────────────── THEME B (Light) — slate-steel ───────────────
            'b' => [
                'ia-bg'                => '#F7F8FA',
                'ia-surface'           => '#FFFFFF',
                'ia-surface-2'         => '#F1F3F6',
                'ia-border'            => 'rgba(15,20,25,.10)',
                'ia-border-strong'     => 'rgba(15,20,25,.20)',
                'ia-text'              => '#0F1419',
                'ia-text-muted'        => 'rgba(15,20,25,.62)',
                'ia-text-dim'          => 'rgba(15,20,25,.42)',
                'ia-hover'             => 'rgba(15,20,25,.06)',
                'ia-input-bg'          => '#FFFFFF',
                'ia-side-bg'           => '#1E2A3A',
                'ia-side-text'         => 'rgba(255,255,255,.5)',
                'ia-side-hover'        => 'rgba(255,255,255,.05)',
                'ia-side-active-bg'    => 'rgba(255,255,255,.08)',
                'ia-side-active-text'  => '#f5f5f5',
                'ia-side-border'       => 'rgba(255,255,255,.07)',
                'ia-side-section'      => 'rgba(255,255,255,.28)',
            ],

            // ─────────────── THEME C (Dark) — premium ───────────────
            'c' => [
                'ia-bg'                => '#0d0d0d',
                'ia-surface'           => '#1c1c1c',
                'ia-surface-2'         => '#262626',
                'ia-border'            => 'rgba(255,255,255,.13)',
                'ia-border-strong'     => 'rgba(255,255,255,.22)',
                'ia-text'              => '#f0f0f0',
                'ia-text-muted'        => 'rgba(255,255,255,.78)',
                'ia-text-dim'          => 'rgba(255,255,255,.55)',
                'ia-hover'             => 'rgba(255,255,255,.07)',
                'ia-input-bg'          => 'rgba(255,255,255,.07)',
                'ia-side-bg'           => '#0c0c0c',
                'ia-side-text'         => 'rgba(255,255,255,.4)',
                'ia-side-hover'        => 'rgba(255,255,255,.05)',
                'ia-side-active-bg'    => 'rgba(255,255,255,.08)',
                'ia-side-active-text'  => '#f0f0f0',
                'ia-side-border'       => 'rgba(255,255,255,.07)',
                'ia-side-section'      => 'rgba(255,255,255,.22)',
            ],
        ];

        $count = 0;
        foreach ($tokens as $theme => $kv) {
            foreach ($kv as $key => $value) {
                ThemeSetting::updateOrCreate(
                    ['theme' => $theme, 'token_key' => $key],
                    ['published_value' => $value, 'published_at' => now()]
                );
                $count++;
            }
        }

        // Bust the service cache so the new values are picked up immediately
        // by anything else running in the same artisan invocation.
        app(\App\Services\ThemeSettingsService::class)->bustCaches();

        $this->command->info("Seeded {$count} theme_settings rows (themes b + c).");
    }
}
PHPEOF
echo "    CREATED database/seeders/ThemeSettingsSeeder.php"
fi

# ─── 6. Wire ThemeOverrideHelper into app.blade.php ─────────────────────
python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/layouts/tenant/app.blade.php")
s = p.read_text()

old = """  {{-- Tenant accent color injected at runtime --}}
  <style>
    body {
      --ia-accent: {{ $currentTenant->accent_color ?? '#3B5A78' }};
      --ia-accent-text: {{ \\App\\Support\\ColorHelper::accentTextColor($currentTenant->accent_color ?? '#3B5A78') }};
      --ia-accent-soft: {{ \\App\\Support\\ColorHelper::accentSoft($currentTenant->accent_color ?? '#3B5A78') }};
    }
  </style>"""

new = """  {{-- Master-admin theme overrides (theme_settings table) --}}
  {!! \\App\\Support\\ThemeOverrideHelper::styleTag() !!}

  {{-- Tenant accent color injected at runtime --}}
  <style>
    body {
      --ia-accent: {{ $currentTenant->accent_color ?? '#3B5A78' }};
      --ia-accent-text: {{ \\App\\Support\\ColorHelper::accentTextColor($currentTenant->accent_color ?? '#3B5A78') }};
      --ia-accent-soft: {{ \\App\\Support\\ColorHelper::accentSoft($currentTenant->accent_color ?? '#3B5A78') }};
    }
  </style>"""

if "ThemeOverrideHelper::styleTag" in s:
    print("    SKIP app.blade.php — ThemeOverrideHelper already wired")
elif old not in s:
    raise SystemExit("ABORT app.blade.php: anchor not found (expected post-patch-63 accent block)")
else:
    s = s.replace(old, new, 1)
    p.write_text(s)
    print("    UPDATED app.blade.php — ThemeOverrideHelper wired into <head>")
PYEOF

cat <<EONOTE

==> Patch 66 applied locally.

Deploy:
  mv patch-66-theme-settings-plumbing.sh _patches/
  git add database/migrations/2026_05_13_000001_create_theme_settings_table.php \\
          app/Models/ThemeSetting.php \\
          app/Services/ThemeSettingsService.php \\
          app/Support/ThemeOverrideHelper.php \\
          database/seeders/ThemeSettingsSeeder.php \\
          resources/views/layouts/tenant/app.blade.php \\
          _patches/patch-66-theme-settings-plumbing.sh
  git commit -m "feat: theme_settings table + service + helper (phase 1+2, patch 66)"
  git push

On server:
  cd /var/www/intake
  git pull
  composer install --no-interaction --no-scripts
  php artisan migrate --force
  php artisan db:seed --class=ThemeSettingsSeeder --force
  php artisan optimize:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify (THIS IS IMPORTANT):
  1. Visit a tenant admin page. View source. Confirm a <style data-theme-overrides>
     block appears in <head> with the same values as theme-b.css / theme-c.css.
  2. Confirm visual output is IDENTICAL to pre-deploy. No color should shift.
  3. Try changing one value manually:
       php artisan tinker
       >>> app(\\App\\Services\\ThemeSettingsService::class)
       >>>   ->setDraft('b', 'ia-bg', '#FF0000', 1);
       >>> app(\\App\\Services\\ThemeSettingsService::class)
       >>>   ->publish('b', 1);
     Reload a light-theme tenant page — background should be red. Revert:
       >>> app(\\App\\Services\\ThemeSettingsService::class)
       >>>   ->setDraft('b', 'ia-bg', '#F7F8FA', 1);
       >>> app(\\App\\Services\\ThemeSettingsService::class)
       >>>   ->publish('b', 1);

If anything looks off: the :root blocks in theme-b.css and theme-c.css are
untouched, so commenting out the ThemeOverrideHelper line in app.blade.php
restores prior behavior fully. Zero-risk rollback.

Phase 3 (Filament UI) is the next patch.
EONOTE
