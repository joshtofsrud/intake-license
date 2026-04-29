<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantClassRegistration extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tenant_class_registrations';

    protected $fillable = [
        'tenant_id',
        'class_session_id',
        'customer_id',
        'status',
        'payment_method',
        'membership_id',
        'pack_id',
        'paid_cents',
        'stripe_payment_intent_id',
        'waitlist_position',
        'registered_at',
        'cancelled_at',
        'metadata',
    ];

    protected $casts = [
        'paid_cents'        => 'integer',
        'waitlist_position' => 'integer',
        'registered_at'     => 'datetime',
        'cancelled_at'      => 'datetime',
        'metadata'          => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TenantClassSession::class, 'class_session_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantCustomerMembership::class, 'membership_id');
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(TenantCustomerPack::class, 'pack_id');
    }

    public function scopeForTenant(Builder $q, string $tenantId): Builder
    {
        return $q->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', ['registered', 'checked_in']);
    }

    public function scopeWaitlisted(Builder $q): Builder
    {
        return $q->where('status', 'waitlisted')->orderBy('waitlist_position');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['registered', 'checked_in']);
    }

    public function cancel(): void
    {
        $this->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
        ]);
    }
}
