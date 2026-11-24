<?php
namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon; // base class accepts Illuminate's subclass too

// a route window: "8–10 am, Mon–Sat, 3 stops".
// Pickups and deliveries share the window's stop count (locked spec).
// Times are tenant-local naive wall clock, per the patch-188 standard.
class TenantRouteWindow extends Model
{
    use HasUuids;

    protected $table = 'tenant_route_windows';
    protected $fillable = [
        'tenant_id', 'label', 'name', 'starts_at', 'ends_at',
        'days', 'on_date', 'max_stops', 'customers_can_book', 'sort_order', 'is_active',
    ];
    protected $casts = [
        'days'               => 'array',
        'max_stops'          => 'integer',
        'is_active'          => 'boolean',
        'customers_can_book' => 'boolean',
    ];

    /** closed dates per tenant, memoised for the request: [tenant|date => [window ids, '*' = whole day]] */
    protected static array $closedMemo = [];

    public static function forgetClosures(): void { static::$closedMemo = []; }

    /** "8–10 am", "11 am–1 pm", "8:30–10 am" */
    public static function autoLabel(string $start, string $end): string
    {
        $f = function (string $t): array {
            [$h, $m] = array_map('intval', array_pad(explode(':', $t), 2, 0));
            $ap = $h < 12 ? 'am' : 'pm';
            $h12 = $h % 12 ?: 12;
            return [$m ? sprintf('%d:%02d', $h12, $m) : (string) $h12, $ap];
        };
        [$a, $ap1] = $f($start);
        [$b, $ap2] = $f($end);
        return $ap1 === $ap2 ? $a . '–' . $b . ' ' . $ap2 : $a . ' ' . $ap1 . '–' . $b . ' ' . $ap2;
    }

    public function isOneDate(): bool { return ! empty($this->attributes['on_date'] ?? null); }

    public function onDateString(): ?string
    {
        $v = $this->attributes['on_date'] ?? null;
        return $v ? substr((string) $v, 0, 10) : null;
    }

    /** ids closed on a date ('*' when the whole day is closed) */
    public static function closedOn(string $tenantId, string $date): array
    {
        $key = $tenantId . '|' . $date;
        if (! array_key_exists($key, static::$closedMemo)) {
            static::$closedMemo[$key] = TenantRouteClosure::where('tenant_id', $tenantId)
                ->whereDate('date', $date)->get()
                ->map(fn ($c) => $c->window_id ?: '*')->all();
        }
        return static::$closedMemo[$key];
    }

    public static function dayClosed(string $tenantId, string $date): bool
    {
        return in_array('*', static::closedOn($tenantId, $date), true);
    }

    /**
     * Active windows that run on a date, in time order: the weekly ones for
     * that weekday plus that date's extras, less anything closed.
     */
    public static function forDate(string $tenantId, Carbon $date, bool $customersOnly = false)
    {
        $d = $date->toDateString();
        return static::where('tenant_id', $tenantId)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('on_date')->orWhereDate('on_date', $d))
            ->when($customersOnly, fn ($q) => $q->where('customers_can_book', true))
            ->orderBy('starts_at')->get()
            ->filter(fn ($w) => $w->runsOn($date))->values();
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort_order')->orderBy('starts_at');
    }

    /** Does this window run on the given (tenant-local) date? */
    public function runsOn(Carbon $date): bool
    {
        $d = $date->toDateString();
        $runs = $this->isOneDate()
            ? $this->onDateString() === $d
            : in_array($date->isoWeekday(), array_map('intval', $this->days ?? []), true);
        if (! $runs) return false;
        $closed = static::closedOn((string) $this->tenant_id, $d);
        return ! in_array('*', $closed, true) && ! in_array((string) $this->id, $closed, true);
    }

    /** does a tenant-local "H:i:s" time fall inside this window? */
    public function covers(string $time): bool
    {
        $s = substr((string) $this->starts_at, 0, 8);
        $e = substr((string) $this->ends_at, 0, 8);
        if (strlen($s) === 5) $s .= ':00';
        if (strlen($e) === 5) $e .= ':00';
        return $time >= $s && $time < $e;
    }

    /** scheduled stops inside this window on a date, with customers */
    public function stopsOn(Carbon $date)
    {
        $tz    = $this->tenant?->timezone() ?? config('app.timezone');
        $start = Carbon::parse($date->toDateString() . ' ' . (string) $this->starts_at, $tz)->utc();
        $end   = Carbon::parse($date->toDateString() . ' ' . (string) $this->ends_at, $tz)->utc();
        return TenantDelivery::with('customer')
            ->where('tenant_id', $this->tenant_id)
            ->where('status', 'scheduled')
            ->whereBetween('scheduled_at', [$start, $end])
            ->orderBy('scheduled_at')->get();
    }

    /**
     * Stops already booked against this window on a date. Counts scheduled
     * tenant_deliveries whose scheduled_at falls inside the window's span —
     * pickups and deliveries share the pool (locked spec).
     */
    public function bookedStops(Carbon $date): int
    {
        // scheduled_at values are UTC; build the window
        // bounds as tenant-local wall clock and convert before comparing.
        $tz    = $this->tenant?->timezone() ?? config('app.timezone');
        $start = Carbon::parse($date->toDateString() . ' ' . (string) $this->starts_at, $tz)->utc();
        $end   = Carbon::parse($date->toDateString() . ' ' . (string) $this->ends_at, $tz)->utc();

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
