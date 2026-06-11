<?php
// MARKER-PATCH-221

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One conversation per customer per channel. The inbox is the
 * conversational record; the customer timeline carries a summary event.
 */
class TenantThread extends Model
{
    use HasUuids;

    protected $table = 'tenant_threads';

    protected $fillable = [
        'tenant_id', 'customer_id', 'channel', 'status', 'subject',
        'assigned_user_id', 'last_message_at', 'last_inbound_at', 'unread_count',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'unread_count'    => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TenantMessage::class, 'thread_id')->orderBy('created_at');
    }

    public function latestMessage(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TenantMessage::class, 'thread_id')->latestOfMany('created_at');
    }
}
