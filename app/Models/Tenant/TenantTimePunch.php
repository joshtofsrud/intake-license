<?php
// MARKER-PATCH-610 — time clock punch model.

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantTimePunch extends Model
{
    use HasUuids;

    protected $table = 'tenant_time_punches';

    protected $fillable = [
        'tenant_id', 'tenant_user_id', 'location_id',
        'clock_in_at', 'clock_out_at', 'source', 'note',
        'created_by', 'auto_closed',
    ];

    protected $casts = [
        'clock_in_at'  => 'datetime',
        'clock_out_at' => 'datetime',
        'auto_closed'  => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'tenant_user_id');
    }

    /** Open punch for a user, if any. */
    public static function openFor(string $tenantId, string $userId): ?self
    {
        return static::where('tenant_id', $tenantId)
            ->where('tenant_user_id', $userId)
            ->whereNull('clock_out_at')
            ->latest('clock_in_at')
            ->first();
    }

    /** Duration in minutes (open punches measure to now). */
    public function minutes(): int
    {
        $end = $this->clock_out_at ?? now();
        return max(0, (int) $this->clock_in_at->diffInMinutes($end));
    }
}

