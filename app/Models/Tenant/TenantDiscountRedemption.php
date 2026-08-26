<?php
// MARKER-DISCOUNTS

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantDiscountRedemption extends Model
{
    use HasUuids;

    protected $table = 'tenant_discount_redemptions';

    protected $fillable = [
        'tenant_id', 'discount_id', 'sale_id', 'customer_id',
        'amount_cents', 'subtotal_cents', 'code', 'redeemed_by_user_id',
    ];

    public function discount()
    {
        return $this->belongsTo(TenantDiscount::class, 'discount_id');
    }
}
