<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** MARKER-HELP-ADMIN — a named, orderable group of help articles. */
class HelpCategory extends Model
{
    use HasUuids;

    protected $table    = 'help_categories';
    protected $fillable = ['name', 'sort'];
    protected $casts    = ['sort' => 'integer'];
}
