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
