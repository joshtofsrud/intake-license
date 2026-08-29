<?php

namespace App\Models;

// MARKER-CONTRIBUTIONS
use Illuminate\Database\Eloquent\Model;

class Contribution extends Model
{
    protected $fillable = [
        'name', 'email', 'amount_cents', 'currency', 'note',
        'status', 'stripe_session_id', 'stripe_payment_intent', 'paid_at', 'ip',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'paid_at'      => 'datetime',
    ];

    public function getAmountAttribute(): float
    {
        return $this->amount_cents / 100;
    }

    /** Only settled money counts. Pending sessions are abandoned carts. */
    public static function totalPaidCents(): int
    {
        return (int) static::where('status', 'paid')->sum('amount_cents');
    }
}
