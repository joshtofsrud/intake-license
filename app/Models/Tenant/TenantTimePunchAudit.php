<?php
// MARKER-PATCH-614 — immutable audit row for time punch changes.

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantTimePunchAudit extends Model
{
    use HasUuids;

    public $timestamps = false; // created_at only, set explicitly

    protected $table = 'tenant_time_punch_audits';

    protected $fillable = [
        'tenant_id', 'punch_id', 'subject_user_id', 'actor_id',
        'action', 'detail', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'actor_id');
    }

    /** Record an audit entry. Actor null = system action. */
    public static function log(string $tenantId, ?string $punchId, ?string $subjectUserId, ?string $actorId, string $action, string $detail): void
    {
        static::create([
            'tenant_id'       => $tenantId,
            'punch_id'        => $punchId,
            'subject_user_id' => $subjectUserId,
            'actor_id'        => $actorId,
            'action'          => $action,
            'detail'          => mb_substr($detail, 0, 800),
            'created_at'      => now(),
        ]);
    }
}

