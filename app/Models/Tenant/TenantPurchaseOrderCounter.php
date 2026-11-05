<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/** MARKER-PO: per-tenant PO number counter (mirrors the special order counter). */
class TenantPurchaseOrderCounter extends Model
{
    protected $table = 'tenant_purchase_order_counters';
    protected $primaryKey = 'tenant_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['tenant_id', 'next_number'];
}
