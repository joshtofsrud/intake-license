<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A structured quality signal on a customer (recovery engine).
 * Rendered as a note in the customer timeline; queried by the at-risk detector.
 */
class TenantCustomerSignal extends Model
{
    use HasUuids;

    protected $table = 'tenant_customer_signals';

    protected $fillable = [
        'tenant_id', 'customer_id', 'appointment_id',
        'type', 'occurred_at', 'meta',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'meta'        => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(TenantAppointment::class, 'appointment_id');
    }
}
