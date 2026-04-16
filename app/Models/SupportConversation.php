<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportConversation extends Model
{
    use HasUuids;
    protected $table    = 'support_conversations';
    protected $fillable = [
        'tenant_id','customer_id','initiated_by','channel','status',
        'customer_email','customer_name','messages','context_snapshot',
        'needs_staff','assigned_to','total_tokens_used','last_message_at','resolved_at',
    ];
    protected $casts = [
        'messages'         => 'array',
        'context_snapshot' => 'array',
        'needs_staff'      => 'boolean',
        'last_message_at'  => 'datetime',
        'resolved_at'      => 'datetime',
    ];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Tenant\TenantCustomer::class, 'customer_id'); }
}
