<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Tenant;

class TenantSale extends Model
{
    use HasUuids;

    protected $table = 'tenant_sales';

    protected $fillable = [
        'tenant_id', 'sale_number', 'sale_date',
        'status', 'payment_status',
        'customer_id', 'assigned_staff_id', 'appointment_id',
        'rang_up_by_user_id', 'refund_of_sale_id',
        'transaction_id',
        'notes',
        'subtotal_cents', 'discount_cents', 'tax_cents',
        'surcharge_cents', 'tip_cents', 'total_cents',
        'paid_at', 'payment_method', 'payment_reference',
        'register_id',
        'location_id',
        'quote_expires_at',
    ];

    protected $casts = [
        'sale_date'      => 'date',
        'paid_at'        => 'datetime',
        'quote_expires_at' => 'datetime',
        'subtotal_cents' => 'integer',
        'discount_cents' => 'integer',
        'tax_cents'      => 'integer',
        'surcharge_cents'=> 'integer',
        'tip_cents'      => 'integer',
        'total_cents'    => 'integer',
    ];

    public function tenant(): BelongsTo        { return $this->belongsTo(Tenant::class); }
    public function customer(): BelongsTo      { return $this->belongsTo(TenantCustomer::class, 'customer_id'); }
    public function assignedStaff(): BelongsTo { return $this->belongsTo(TenantUser::class, 'assigned_staff_id'); }
    public function appointment(): BelongsTo   { return $this->belongsTo(TenantAppointment::class, 'appointment_id'); }
    public function location(): BelongsTo      { return $this->belongsTo(TenantLocation::class, 'location_id'); }
    public function rangUpBy(): BelongsTo      { return $this->belongsTo(TenantUser::class, 'rang_up_by_user_id'); }
    public function refundOf(): BelongsTo      { return $this->belongsTo(TenantSale::class, 'refund_of_sale_id'); }
    public function refunds(): HasMany         { return $this->hasMany(TenantSale::class, 'refund_of_sale_id'); }
    public function items(): HasMany           { return $this->hasMany(TenantSaleItem::class, 'sale_id'); }

    public function scopeActive($q)            { return $q->whereNotIn('status', ['cancelled']); }
    public function scopeUnpaid($q)            { return $q->where('payment_status', 'unpaid'); }
    public function scopePaid($q)              { return $q->where('payment_status', 'paid'); }
    public function scopeDrafts($q)            { return $q->where('payment_status', 'draft'); }
    public function scopeQuotes($q)            { return $q->where('payment_status', 'quote'); }
    public function scopeCommitted($q)         { return $q->whereIn('payment_status', ['unpaid', 'partial', 'paid', 'refunded']); }

    public function isPaid(): bool             { return $this->payment_status === 'paid'; }
    public function isDraft(): bool            { return $this->payment_status === 'draft'; }
    public function isQuote(): bool            { return $this->payment_status === 'quote'; }
    public function isRefunded(): bool         { return $this->payment_status === 'refunded'; }
    public function isCompleted(): bool        { return $this->status === 'completed'; }
    public function isCancelled(): bool        { return $this->status === 'cancelled'; }
    public function isRefund(): bool           { return $this->refund_of_sale_id !== null; }
    public function isWalkIn(): bool           { return $this->appointment_id === null; }
    public function isServiceJob(): bool       { return $this->appointment_id !== null; }
}
