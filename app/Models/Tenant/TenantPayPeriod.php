<?php
// MARKER-PATCH-612 — pay period. Boundaries are tenant-local, stored UTC.

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantPayPeriod extends Model
{
    use HasUuids;

    protected $table = 'tenant_pay_periods';

    protected $fillable = [
        'tenant_id', 'starts_at', 'ends_at', 'status',
        'locked_at', 'locked_by', 'reopen_reason',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
        'locked_at' => 'datetime',
    ];

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }

    /**
     * The period covering a given tenant-local moment (default: now).
     * Computes/returns the open period; creation of boundaries is the
     * settings layer's job (cycle-aware) — this just finds the match.
     */
    public static function covering($tenant, ?\Carbon\Carbon $atUtc = null): ?self
    {
        $atUtc = $atUtc ?? now();
        return static::where('tenant_id', $tenant->id)
            ->where('starts_at', '<=', $atUtc)
            ->where('ends_at', '>=', $atUtc)
            ->first();
    }
}

