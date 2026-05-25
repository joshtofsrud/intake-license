<?php
// MARKER-PATCH-146

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenantEmailSuppression — addresses that should never receive mail.
 *
 * Scope:
 *   - tenant_id != null  → suppressed for that tenant only
 *   - tenant_id == null  → suppressed platform-wide
 *
 * EmailService checks BOTH scopes before sending. If either matches,
 * the send is skipped.
 */
class TenantEmailSuppression extends Model
{
    protected $table = 'tenant_email_suppressions';

    protected $fillable = [
        'tenant_id',
        'email',
        'reason',
        'subtype',
        'source_message_id',
        'diagnostic',
        'notes',
        'suppressed_by_user_id',
        'suppressed_at',
    ];

    protected $casts = [
        'suppressed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Is the given email suppressed for the given tenant?
     * Returns true if EITHER a tenant-scoped or platform-wide suppression matches.
     */
    public static function isSuppressed(?string $tenantId, string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') return false;

        return self::where('email', $email)
            ->where(function ($q) use ($tenantId) {
                $q->whereNull('tenant_id')
                  ->orWhere('tenant_id', $tenantId);
            })
            ->exists();
    }
}
