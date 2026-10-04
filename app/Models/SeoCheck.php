<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** MARKER-SEO-SIGNALS — one row per site per seo:check run. */
class SeoCheck extends Model
{
    protected $fillable = ['host', 'tenant_id', 'expect', 'status', 'problems', 'urls_checked', 'checked_at'];

    protected $casts = [
        'problems'   => 'array',
        'checked_at' => 'datetime',
    ];
}
