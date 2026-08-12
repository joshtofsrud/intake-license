<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-RAISE-ADMIN
class Investor extends Model
{
    /** The SAFE cap the round is priced against. Change here and the cap table follows. */
    public const CAP    = 1000000;
    public const TARGET = 100000;

    protected $fillable = [
        'name', 'email', 'entity', 'amount', 'amount_received',
        'invited_at', 'committed_at', 'signed_at', 'funded_at', 'declined_at',
        'funding_method', 'notes',
    ];

    protected $casts = [
        'invited_at'   => 'datetime',
        'committed_at' => 'datetime',
        'signed_at'    => 'datetime',
        'funded_at'    => 'datetime',
        'declined_at'  => 'datetime',
    ];

    /** Status is DERIVED from events, never typed. Declined is the one manual state. */
    public function getStatusAttribute(): string
    {
        if ($this->declined_at)  return 'Declined';
        if ($this->funded_at)    return 'Funded';
        if ($this->signed_at)    return 'Signed';
        if ($this->committed_at) return 'Committed';
        if ($this->invited_at)   return 'Invited';

        return 'Added';
    }

    public function getPercentAttribute(): float
    {
        return self::CAP > 0 ? round($this->amount / self::CAP * 100, 2) : 0.0;
    }

    public function scopeActive($query)
    {
        return $query->whereNull('declined_at');
    }
}
