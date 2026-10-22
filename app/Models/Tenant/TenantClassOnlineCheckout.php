<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One online class payment: a pass bought on /classes/passes, or a class
 * paid for online. Settled once by ClassOnlineSaleService::finalize, from
 * the browser return or the Stripe webhook, whichever is first.
 * MARKER-CLASSES-P3
 */
class TenantClassOnlineCheckout extends Model
{
    use HasUuids;

    protected $table = 'tenant_class_online_checkouts';

    protected $fillable = [
        'tenant_id', 'customer_id', 'kind', 'product_id', 'session_id',
        'amount_cents', 'tax_cents', 'stripe_payment_intent_id', 'status',
        'sale_id', 'registration_id', 'pass_id', 'note',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'tax_cents'    => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function session()
    {
        return $this->belongsTo(TenantClassSession::class, 'session_id');
    }
}
