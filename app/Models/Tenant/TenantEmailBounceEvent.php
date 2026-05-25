<?php
// MARKER-PATCH-146

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenantEmailBounceEvent — raw log of every bounce/complaint we receive.
 *
 * Suppression decisions are derived from these events. Keeps a full
 * audit trail separate from the active suppression list — so we can
 * see "this address bounced 4 times from 3 different tenants" even
 * after the suppression row is cleaned up.
 */
class TenantEmailBounceEvent extends Model
{
    protected $table = 'tenant_email_bounce_events';

    protected $fillable = [
        'tenant_id',
        'email',
        'event_type',
        'bounce_type',
        'bounce_subtype',
        'source_message_id',
        'payload',
        'received_at',
    ];

    protected $casts = [
        'payload'     => 'array',
        'received_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
