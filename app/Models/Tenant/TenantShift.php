<?php
// MARKER-PATCH-612 — scheduled shift. starts_at/ends_at are UTC instants
// resolved from the tenant-local wall time at write; display via tlocal().

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantShift extends Model
{
    use HasUuids;

    protected $table = 'tenant_shifts';

    protected $fillable = [
        'tenant_id', 'tenant_user_id', 'location_id',
        'starts_at', 'ends_at', 'label', 'color', 'note',
        'published_at', 'created_by',
    ];

    protected $casts = [
        'starts_at'    => 'datetime',
        'ends_at'      => 'datetime',
        'published_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'tenant_user_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function minutes(): int
    {
        return max(0, (int) $this->starts_at->diffInMinutes($this->ends_at));
    }
}

