<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RoadmapEntry extends Model
{
    use HasUuids;

    protected $table = 'roadmap_entries';

    protected $fillable = [
        'status', 'title', 'category', 'body',
        'rough_timeframe', 'display_order', 'is_published',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'is_published'  => 'boolean',
    ];

    public function scopePublished($q) { return $q->where('is_published', true); }

    public const STATUSES = [
        'shipped'      => 'Shipped',
        'in_progress'  => 'In progress',
        'next_up'      => 'Next up',
        'considering'  => 'Considering',
    ];

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucwords(str_replace('_', ' ', $this->status));
    }
}
