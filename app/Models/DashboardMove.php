<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** MARKER-DASH-PEOPLE: one move of people to another dashboard, kept so it can be undone. */
class DashboardMove extends Model
{
    protected $table = 'dashboard_moves';

    protected $fillable = [
        'admin_user_id', 'admin_name', 'reason', 'from_label', 'to_key', 'to_name', 'written',
        'people', 'shops', 'previous', 'undone_at',
    ];

    protected $casts = ['previous' => 'array', 'undone_at' => 'datetime'];
}
