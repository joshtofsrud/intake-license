<?php
// MARKER-LEDGER-CORE

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesCommissionEntry extends Model
{
    use HasUuids;

    protected $table = 'sales_commission_entries';

    protected $fillable = [
        'agency_id', 'sales_rep_id', 'sales_prospect_id', 'tenant_id',
        'stripe_invoice_id', 'amount_collected_cents', 'rate',
        'commission_cents', 'basis', 'collected_at', 'status', 'paid_at',
    ];

    protected $casts = [
        'rate'         => 'decimal:4',
        'collected_at' => 'datetime',
        'paid_at'      => 'datetime',
    ];

    public const STATUSES = [
        'accrued' => 'Accrued',
        'paid'    => 'Paid',
        'void'    => 'Void',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(SalesAgency::class, 'agency_id');
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(SalesRep::class, 'sales_rep_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function scopeUnpaid($query)
    {
        return $query->where('status', 'accrued');
    }
}
