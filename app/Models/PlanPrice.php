<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-PLAN-PRICING — one price for one tier, from a date.
class PlanPrice extends Model
{
    protected $table = 'plan_prices';

    protected $fillable = ['tier', 'price_cents', 'effective_from', 'created_by'];

    protected $casts = [
        'effective_from' => 'date',
        'price_cents'    => 'integer',
    ];

    public function isScheduled(): bool
    {
        return $this->effective_from && $this->effective_from->isFuture();
    }

    public function dollars(): string
    {
        return '$' . number_format($this->price_cents / 100, 2);
    }
}
