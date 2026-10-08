<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** MARKER-OPTION-SPLIT: one option dropdown carved out of a variant's Version text. */
class VariantSplitRule extends Model
{
    protected $table = 'variant_split_rules';

    protected $fillable = ['attribute', 'applies_to', 'words', 'sort', 'is_active'];

    protected $casts = ['words' => 'array', 'is_active' => 'boolean'];
}
