<?php
// MARKER-PATCH-635 — cached Stripe payout with charge breakdown.

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantStripePayout extends Model
{
    use HasUuids;

    protected $table = 'tenant_stripe_payouts';
    protected $fillable = [
        'tenant_id', 'payout_id', 'arrived_on', 'gross_cents', 'fee_cents', 'net_cents',
        'charge_count', 'unmatched_count', 'items', 'fetched_at',
    ];
    protected $casts = ['arrived_on' => 'date', 'items' => 'array', 'fetched_at' => 'datetime'];
}

