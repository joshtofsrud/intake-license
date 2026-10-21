<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One credit in or out for a customer's pack or membership. Written by
 * App\Services\ClassCreditLedger only; never edited. MARKER-CLASSES-P1
 *
 * delta: pack credits gained (+) or spent (-); for a membership, classes
 * used this period (-1 booked, +1 given back). Reasons: purchased, granted,
 * booked, restored, expired, revoked, refunded.
 */
class TenantClassCreditEntry extends Model
{
    use HasUuids;

    protected $table = 'tenant_class_credit_entries';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'customer_id', 'pack_id', 'membership_id', 'registration_id',
        'sale_id', 'delta', 'reason', 'note', 'actor_user_id',
    ];

    protected $casts = ['delta' => 'integer'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(TenantCustomerPack::class, 'pack_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantCustomerMembership::class, 'membership_id');
    }
}

