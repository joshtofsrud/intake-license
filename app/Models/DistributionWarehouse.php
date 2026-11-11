<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** MARKER-DISTRIBUTION-WAREHOUSES: one supplier warehouse, as found in a distributor's feed. */
class DistributionWarehouse extends Model
{
    use HasUuids;

    protected $table = 'platform_distribution_warehouses';

    protected $fillable = [
        'distributor_code', 'code', 'name', 'ship_from_zip', 'ship_from_city', 'ship_from_state',
        'handling_days', 'shown_to_shops', 'dropship_enabled', 'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'shown_to_shops'   => 'boolean',
        'dropship_enabled' => 'boolean',
        'handling_days'    => 'integer',
        'first_seen_at'    => 'datetime',
        'last_seen_at'     => 'datetime',
    ];
}
