<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-RAISE-ADMIN
class Investor extends Model
{
    /** The SAFE cap the round is priced against. Change here and the cap table follows. */
    // MARKER-RAISE-SETUP — constants are now DEFAULTS; the live values come from raise_settings.
    public const CAP    = 1000000;
    public const TARGET = 100000;

    public static function cap(): int
    {
        return (int) (RaiseSetting::get('cap') ?: self::CAP);
    }

    public static function target(): int
    {
        return (int) (RaiseSetting::get('target') ?: self::TARGET);
    }

    protected $fillable = [
        'name', 'email', 'self_declared', 'signature_request_id', 'safe_sent_at', 'entity', 'token', 'amount', 'amount_received',
        'invited_at', 'committed_at', 'signed_at', 'funded_at', 'declined_at',
        'funding_method', 'notes',
    ];

    // MARKER-RAISE-RECORDS
    protected static function booted(): void
    {
        static::creating(function (self $investor) {
            $investor->token = $investor->token ?: \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(40));
        });
    }

    public function documents()
    {
        return $this->hasMany(InvestorDocument::class)->latest('id');
    }

    public function events()
    {
        return $this->hasMany(InvestorEvent::class)->latest('id');
    }

    public function portalUrl(): string
    {
        return url('/invest/i/' . $this->token);
    }

    protected $casts = [
        'safe_sent_at' => 'datetime',   // MARKER-SIGNING-SEND
        'self_declared' => 'boolean',   // MARKER-SHARED-COMMIT
        'invited_at'     => 'datetime',
        'opened_at'      => 'datetime',
        'portal_seen_at' => 'datetime',
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
        if ($this->opened_at)    return 'Opened';
        if ($this->invited_at)   return 'Invited';

        return 'Added';
    }

    public function getPercentAttribute(): float
    {
        $cap = self::cap();

        return $cap > 0 ? round($this->amount / $cap * 100, 2) : 0.0;
    }

    public function scopeActive($query)
    {
        return $query->whereNull('declined_at');
    }
}
