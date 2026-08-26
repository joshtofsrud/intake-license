<?php
// MARKER-EMAIL-CONSENT

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * A shop's on-record claim that it has permission to email a set of
 * imported contacts. This is what lets the platform show Postmark the
 * assertion was made — and cut off one shop instead of defending everyone.
 */
class TenantConsentAttestation extends Model
{
    protected $table = 'tenant_consent_attestations';

    protected $fillable = [
        'tenant_id', 'contact_count', 'wording',
        'confirmed_by_user_id', 'confirmed_by_name', 'confirmed_by_role',
        'ip', 'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];
}
