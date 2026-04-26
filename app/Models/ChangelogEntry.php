<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ChangelogEntry extends Model
{
    use HasUuids;

    protected $table = 'changelog_entries';

    protected $fillable = [
        'shipped_on', 'title', 'category', 'body',
        'is_published', 'is_highlighted',
    ];

    protected $casts = [
        'shipped_on'     => 'date',
        'is_published'   => 'boolean',
        'is_highlighted' => 'boolean',
    ];

    public function scopePublished($q) { return $q->where('is_published', true); }
}
