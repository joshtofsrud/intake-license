<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A dashboard block as master admin sees it. Built-in blocks draw from code
 * (App\Support\Dashboard\BlockRegistry); this row carries their name and
 * whether they're hidden everywhere.
 */
class DashboardBlock extends Model
{
    protected $table = 'dashboard_blocks';

    protected $fillable = ['key', 'name', 'kind', 'is_builtin', 'origin', 'config', 'hidden_at'];

    protected $casts = [
        'is_builtin' => 'boolean',
        'config'     => 'array',
        'hidden_at'  => 'datetime',
    ];
}
