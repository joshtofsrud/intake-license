<?php
// MARKER-PATCH-HLC16

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogTitlePattern extends Model
{
    protected $fillable = [
        'distributor_code', 'label', 'pattern', 'sort_order', 'is_active', 'notes',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
