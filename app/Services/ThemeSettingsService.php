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
