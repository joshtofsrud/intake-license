<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** MARKER-LIVE-VIEW: see App\Services\LiveView. */
class LiveViewSession extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'accepted_at' => 'datetime',
        'ended_at'    => 'datetime',
        'expires_at'  => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['requested', 'active'], true);
    }
}
