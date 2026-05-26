<?php
// MARKER-PATCH-158-A

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's persistent asset — a bike, vehicle, pet, or whatever they
 * bring in for service. Belongs to a customer; survives across appointments.
 *
 * NOT to be confused with TenantAppointmentItem (which is a *service* on an
 * appointment). The vocabulary collision is unfortunate but the existing
 * column name is too entrenched to rename.
 *
 * Archive (set archived_at) rather than hard-delete to preserve the asset
 * history that powers "last seen Mar 12" hints in the picker.
 */
class TenantCustomerAsset extends Model
{
    use HasUuids;

    protected $table = 'tenant_customer_assets';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'name',
        'identifier',
        'metadata',
        'notes',
        'last_seen_at',
        'last_appointment_id',
        'archived_at',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'last_seen_at' => 'datetime',
        'archived_at'  => 'datetime',
    ];

    // ----------------------------------------------------------------
    // Relations
    // ----------------------------------------------------------------
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    /**
     * All appointment_assets rows referencing this asset — i.e. every time
     * this bike has been on an appointment. Used for the asset history view.
     */
    public function appointmentAssets(): HasMany
    {
        return $this->hasMany(TenantAppointmentAsset::class, 'customer_asset_id');
    }

    // ----------------------------------------------------------------
    // Scopes
    // ----------------------------------------------------------------
    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archive(): void
    {
        $this->update(['archived_at' => now()]);
    }

    public function unarchive(): void
    {
        $this->update(['archived_at' => null]);
    }
}
