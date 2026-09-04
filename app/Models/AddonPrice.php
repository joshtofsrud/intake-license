<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-ADDON-CATALOG — one price for one add-on, from a date.
class AddonPrice extends Model
{
    protected $table = 'addon_prices';

    protected $fillable = ['addon_code', 'price_cents', 'effective_from', 'created_by'];

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
