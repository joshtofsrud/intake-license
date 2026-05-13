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
