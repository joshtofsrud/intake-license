<?php
// MARKER-PATCH-225

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantStaffAlert extends Model
{
    use HasUuids;

    protected $table = 'tenant_staff_alerts';

    protected $fillable = [
        'tenant_id', 'user_id', 'event', 'title', 'body', 'link',
        'meta', 'is_critical', 'read_at',
    ];

    protected $casts = [
        'meta'        => 'array',
        'is_critical' => 'boolean',
        'read_at'     => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id');
    }
}
