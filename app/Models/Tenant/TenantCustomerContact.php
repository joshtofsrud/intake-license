<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MARKER-BIZ-CUSTOMER — a person at a business customer. Exactly one contact
 * per customer is primary; the primary is what the app uses wherever it needs
 * a single email or phone for a business.
 */
class TenantCustomerContact extends Model
{
    use HasUuids;

    protected $table = 'tenant_customer_contacts';

    protected $fillable = [
        'tenant_id', 'customer_id',
        'name', 'role', 'email', 'phone',
        'is_primary', 'notes',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    /**
     * Make this contact the only primary for its customer. Done as a pair of
     * scoped updates rather than a loop so two people saving at once cannot
     * leave a customer with two primaries or none.
     */
    public function makePrimary(): void
    {
        static::where('customer_id', $this->customer_id)
            ->where('id', '!=', $this->id)
            ->update(['is_primary' => false]);

        $this->forceFill(['is_primary' => true])->save();
    }
}
