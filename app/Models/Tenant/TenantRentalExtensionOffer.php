<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// MARKER-RENTAL-EXT
class TenantRentalExtensionOffer extends Model
{
    use HasUuids;

    protected $table = 'tenant_rental_extension_offers';

    protected $fillable = [
        'tenant_id', 'rental_id', 'token', 'status', 'channel',
        'offer_from', 'extend_to', 'discount_pct',
        'subtotal_cents', 'tax_cents', 'total_cents',
        'sent_at', 'responded_at', 'expires_at',
        'stripe_payment_intent_id', 'sale_id', 'meta',
    ];

    protected $casts = [
        'offer_from'   => 'datetime',
        'extend_to'    => 'datetime',
        'sent_at'      => 'datetime',
        'responded_at' => 'datetime',
        'expires_at'   => 'datetime',
        'meta'         => 'array',
    ];

    public function rental(): BelongsTo
    {
        return $this->belongsTo(TenantRental::class, 'rental_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'sent'
            && (!$this->expires_at || $this->expires_at->isFuture());
    }
}
