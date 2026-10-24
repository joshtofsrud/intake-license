<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One published layout of a template. Kept for history and rollback. */
class DashboardTemplateVersion extends Model
{
    protected $table = 'dashboard_template_versions';

    protected $fillable = ['dashboard_template_id', 'version', 'layout', 'note', 'published_by_user_id', 'published_at'];

    protected $casts = [
        'layout'       => 'array',
        'published_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(DashboardTemplate::class, 'dashboard_template_id');
    }
}
