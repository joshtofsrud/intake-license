<?php
// MARKER-PATCH-221

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single message/event in a thread. Offer cards and system events render
 * from meta — copy is never duplicated into the body of feature rows.
 */
class TenantMessage extends Model
{
    use HasUuids;

    protected $table = 'tenant_messages';

    protected $fillable = [
        'thread_id', 'direction', 'kind', 'body', 'meta', 'channel',
        'sent_by_user_id', 'external_id', 'delivered_at', 'read_at',
    ];

    protected $casts = [
        'meta'         => 'array',
        'delivered_at' => 'datetime',
        'read_at'      => 'datetime',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(TenantThread::class, 'thread_id');
    }
}
