<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-NAV-ORDER — a customisation of one sidebar item. No row means the
// item still appears, exactly as its class declares it.
class AdminNavItem extends Model
{
    protected $table = 'admin_nav_items';

    protected $fillable = ['class', 'group', 'label', 'sort', 'hidden'];

    protected $casts = ['hidden' => 'boolean', 'sort' => 'integer'];
}
