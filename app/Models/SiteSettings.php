<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * SiteSettings — single-row table holding global marketing/brand settings.
 * Always accessed via SiteSettings::current().
 *
 * Defensive: returns a transient instance if the table doesn't exist yet
 * (e.g. between deploy and migration). Prevents Filament from crashing at
 * boot when resources are registered before migrations run.
 */
class SiteSettings extends Model
{
    protected $table = 'site_settings';

    protected $fillable = [
        'default_page_title',
        'default_meta_description',
        'footer_tagline',
        'logo_url',
        'favicon_url',
        'og_image_url',
        'twitter_url',
        'linkedin_url',
        'github_url',
        'plausible_domain',
        'gtm_id',
    ];

    public static function current(): self
    {
        // Guard against the table not existing yet (pre-migration state).
        if (! Schema::hasTable('site_settings')) {
            return new self(); // transient, not persisted
        }

        return static::firstOrCreate(['id' => 1]);
    }
}
