<?php
// MARKER-PATCH-HLC16

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogTitleSetting extends Model
{
    // MARKER-TITLE-CATEGORY-SCOPE — category_key '' means "any category".
    protected $fillable = [
        'distributor_code', 'category_key', 'title_template', 'subtitle_template',
        'search_template', 'color_attribute_priority', 'size_attribute_priority',
        'is_active', 'notes',
    ];

    protected $casts = [
        'color_attribute_priority' => 'array',
        'size_attribute_priority'  => 'array',
        'is_active' => 'boolean',
    ];
}
