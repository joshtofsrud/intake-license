<?php
namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon; // MARKER-PATCH-511B — base class accepts Illuminate's subclass too

// MARKER-PATCH-509 — a route window: "8–10 am, Mon–Sat, 3 stops".
// Pickups and deliveries share the window's stop count (locked spec).
// Times are tenant-local naive wall clock, per the patch-188 standard.
class TenantRouteWindow extends Model
{
    use HasUuids;

    protected $table = 'tenant_route_windows';
    protected $fillable = [
        'tenant_id', 'label', 'starts_at', 'ends_at',
        'days', 'max_stops', 'sort_order', 'is_active',
    ];
    protected $casts = [
        'days'      => 'array',
        'max_stops' => 'integer',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort_order')->orderBy('starts_at');
    }

    /** Does this window run on the given (tenant-local) date? */
    public function runsOn(Carbon $date): bool
    {
        return in_array($date->isoWeekday(), $this->days ?? [], true);
    }

    /**
     * Stops already booked against this window on a date. Counts scheduled
     * tenant_deliveries whose scheduled_at falls inside the window's span —
     * pickups and deliveries share the pool (locked spec).
     */
    public function bookedStops(Carbon $date): int
    {
        $start = $date->copy()->setTimeFromTimeString($this->starts_at);
        $end   = $date->copy()->setTimeFromTimeString($this->ends_at);

        return TenantDelivery::query()
            ->where('tenant_id', $this->tenant_id)
            ->where('status', 'scheduled')
            ->whereBetween('scheduled_at', [$start, $end])
            ->count();
    }

    public function remainingStops(Carbon $date): int
    {
        return max(0, (int) $this->max_stops - $this->bookedStops($date));
    }
}
