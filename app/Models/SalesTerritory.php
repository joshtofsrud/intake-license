<?php
// MARKER-SALES-FIND

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rule that says which agency/rep owns prospects in a set of states, with an
 * optional latitude band for partial states (the Modus contract covers only
 * northern California and northern Nevada). Lower priority wins on overlap.
 */
class SalesTerritory extends Model
{
    use HasUuids;

    protected $table = 'sales_territories';

    protected $fillable = ['name', 'agency_id', 'sales_rep_id', 'states', 'lat_min', 'lat_max', 'priority', 'is_active', 'notes'];

    protected $casts = [
        'states'    => 'array',
        'lat_min'   => 'decimal:6',
        'lat_max'   => 'decimal:6',
        'priority'  => 'integer',
        'is_active' => 'boolean',
    ];

    public function agency(): BelongsTo { return $this->belongsTo(SalesAgency::class, 'agency_id'); }
    public function rep(): BelongsTo    { return $this->belongsTo(SalesRep::class, 'sales_rep_id'); }
    public function prospects(): HasMany { return $this->hasMany(SalesProspect::class, 'territory_id'); }

    public function matches(?string $state, ?float $lat): bool
    {
        if (! $state) return false;
        $states = array_map('strtoupper', (array) $this->states);
        if (! in_array(strtoupper($state), $states, true)) return false;
        if ($this->lat_min !== null && ($lat === null || $lat < (float) $this->lat_min)) return false;
        if ($this->lat_max !== null && ($lat === null || $lat > (float) $this->lat_max)) return false;
        return true;
    }

    public function ownerLabel(): string
    {
        $rep = $this->rep?->name;
        $ag  = $this->agency?->name;
        return $rep && $ag ? "$rep · $ag" : ($rep ?: ($ag ?: 'Unassigned'));
    }

    public function statesLabel(): string
    {
        $s = implode(', ', array_map('strtoupper', (array) $this->states));
        if ($this->lat_min !== null) $s .= ' · north of ' . rtrim(rtrim((string) $this->lat_min, '0'), '.') . '°';
        if ($this->lat_max !== null) $s .= ' · south of ' . rtrim(rtrim((string) $this->lat_max, '0'), '.') . '°';
        return $s;
    }
}
