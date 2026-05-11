<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SiteSettings — single-row table holding global marketing/brand settings.
 * Always accessed via SiteSettings::current().
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
        return static::firstOrCreate(['id' => 1]);
    }
}
