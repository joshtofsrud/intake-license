<?php
// MARKER-PATCH-217

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versioned liability/rental terms. The rental row snapshots the version +
 * a rendered PDF at signature — editing a template NEVER changes
 * already-signed agreements.
 */
class TenantRentalAgreementTemplate extends Model
{
    use HasUuids;

    protected $table = 'tenant_rental_agreement_templates';

    protected $fillable = [
        'tenant_id', 'version', 'title', 'body', 'created_by_user_id',
    ];

    protected $casts = ['version' => 'integer'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function nextVersion(string $tenantId): int
    {
        return (int) static::where('tenant_id', $tenantId)->max('version') + 1;
    }
}
