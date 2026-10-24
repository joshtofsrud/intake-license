<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dashboard template: a named arrangement of blocks that shops pick from
 * their dashboard switch. Shops only ever see the live version; edits sit in
 * draft_layout until published.
 */
class DashboardTemplate extends Model
{
    protected $table = 'dashboard_templates';

    protected $fillable = [
        'key', 'name', 'is_builtin', 'frame', 'state', 'switch_label', 'beta_badge', 'icon',
        'rearrange', 'is_default', 'sort', 'live_version_id', 'draft_layout', 'audience', 'retired_at',
    ];

    protected $casts = [
        'is_builtin'   => 'boolean',
        'beta_badge'   => 'boolean',
        'is_default'   => 'boolean',
        'draft_layout' => 'array',
        'audience'     => 'array',
        'retired_at'   => 'datetime',
    ];

    public function liveVersion(): BelongsTo
    {
        return $this->belongsTo(DashboardTemplateVersion::class, 'live_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DashboardTemplateVersion::class, 'dashboard_template_id');
    }
}
