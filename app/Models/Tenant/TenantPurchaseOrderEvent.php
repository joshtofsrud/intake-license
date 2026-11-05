<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** MARKER-PO: the PO's history. */
class TenantPurchaseOrderEvent extends Model
{
    use HasUuids;

    protected $table = 'tenant_purchase_order_events';
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'purchase_order_id', 'user_id', 'body', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(TenantUser::class, 'user_id'); }
}
