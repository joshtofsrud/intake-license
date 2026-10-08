<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** MARKER-OPTION-SPLIT / MARKER-OPTION-FIELDS: one option dropdown in the register picker. */
class VariantSplitRule extends Model
{
    protected $table = 'variant_split_rules';

    protected $fillable = ['attribute', 'applies_to', 'fields', 'mixed_fields', 'words', 'aliases', 'sort', 'is_active'];

    protected $casts = [
        'fields' => 'array', 'mixed_fields' => 'array', 'words' => 'array', 'aliases' => 'array', 'is_active' => 'boolean',
    ];
}
