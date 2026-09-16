<?php
// MARKER-SALES-FIND — one row per Find-shops search, so spend is visible and auditable.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesPlacesSearch extends Model
{
    protected $table = 'sales_places_searches';
    protected $fillable = ['user_id', 'industry', 'place', 'radius_miles', 'requests', 'found', 'new_count', 'cost_cents'];

    public static function monthToDateCents(): int
    {
        return (int) static::where('created_at', '>=', now()->startOfMonth())->sum('cost_cents');
    }
}
