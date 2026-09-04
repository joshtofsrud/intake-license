<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-NAV-ORDER — order and label for one sidebar group.
class AdminNavGroup extends Model
{
    protected $table = 'admin_nav_groups';

    protected $fillable = ['name', 'label', 'sort', 'collapsed'];

    protected $casts = ['collapsed' => 'boolean', 'sort' => 'integer'];
}
