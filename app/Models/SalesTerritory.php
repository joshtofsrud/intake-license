<?php
// MARKER-SALES-FIND
// MARKER-SALES-TERRITORY2

namespace App\Models;

use App\Services\Sales\LoopLocator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Which agency/rep owns prospects. A shop matches when ANY of these is true:
 *   - its loop is one of the territory's loops (Washington, L1–L9)
 *   - its state is listed, and it sits inside the optional latitude band
 *     (the Modus contract covers only northern California and Nevada)
 *   - it lies within radius_miles of the territory's circle center
 * Lower priority wins on overlap.
 */
class SalesTerritory extends Model
{
    use HasUuids;

    protected $table = 'sales_territories';

    protected $fillable = ['name', 'agency_id', 'sales_rep_id', 'states', 'lat_min', 'lat_max', 'loops',
        'center_label', 'center_lat', 'center_lng', 'radius_miles', 'priority', 'is_active', 'notes'];

    protected $casts = [
        'states'       => 'array',
        'loops'        => 'array',
        'lat_min'      => 'decimal:6',
        'lat_max'      => 'decimal:6',
        'center_lat'   => 'decimal:6',
        'center_lng'   => 'decimal:6',
        'radius_miles' => 'integer',
        'priority'     => 'integer',
        'is_active'    => 'boolean',
    ];

    public function agency(): BelongsTo { return $this->belongsTo(SalesAgency::class, 'agency_id'); }
    public function rep(): BelongsTo    { return $this->belongsTo(SalesRep::class, 'sales_rep_id'); }
    public function prospects(): HasMany { return $this->hasMany(SalesProspect::class, 'territory_id'); }

    public function matches(?string $state, ?float $lat, ?float $lng = null, ?int $loop = null): bool
    {
        if ($loop && in_array($loop, array_map('intval', (array) $this->loops), true)) {
            return true;
        }

        $states = array_map('strtoupper', (array) $this->states);
        if ($state && in_array(strtoupper($state), $states, true)) {
            $inBand = ! ($this->lat_min !== null && ($lat === null || $lat < (float) $this->lat_min))
                   && ! ($this->lat_max !== null && ($lat === null || $lat > (float) $this->lat_max));
            if ($inBand) return true;
        }

        if ($this->hasCircle() && $lat !== null && $lng !== null) {
            return LoopLocator::miles($lat, $lng, (float) $this->center_lat, (float) $this->center_lng) <= (int) $this->radius_miles;
        }
        return false;
    }

    public function hasCircle(): bool
    {
        return $this->radius_miles && $this->center_lat !== null && $this->center_lng !== null;
    }

    public function ownerLabel(): string
    {
        $rep = $this->rep?->name;
        $ag  = $this->agency?->name;
        return $rep && $ag ? "$rep · $ag" : ($rep ?: ($ag ?: 'House'));
    }

    /** "Loops L1, L2 · WA, ID north of 45° · within 25 mi of Spokane, WA" */
    public function statesLabel(): string
    {
        $parts = [];
        $loops = array_map('intval', (array) $this->loops);
        if ($loops) {
            sort($loops);
            $parts[] = 'Loops ' . implode(', ', array_map(fn ($l) => "L$l", $loops));
        }
        if ($this->states) {
            $s = implode(', ', array_map('strtoupper', (array) $this->states));
            if ($this->lat_min !== null) $s .= ' north of ' . rtrim(rtrim((string) $this->lat_min, '0'), '.') . '°';
            if ($this->lat_max !== null) $s .= ' south of ' . rtrim(rtrim((string) $this->lat_max, '0'), '.') . '°';
            $parts[] = $s;
        }
        if ($this->hasCircle()) {
            $parts[] = "within {$this->radius_miles} mi of " . ($this->center_label ?: 'a point');
        }
        return $parts ? implode(' · ', $parts) : 'Nothing yet';
    }
}
