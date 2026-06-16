<?php
// MARKER-PATCH-HLC16

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogTitleSetting extends Model
{
    protected $fillable = [
        'distributor_code', 'title_template', 'subtitle_template',
        'color_attribute_priority', 'is_active', 'notes',
    ];

    protected $casts = [
        'color_attribute_priority' => 'array',
        'is_active' => 'boolean',
    ];
}
