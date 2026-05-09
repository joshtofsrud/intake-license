<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RoadmapEntry extends Model
{
    use HasUuids;

    protected $table = 'roadmap_entries';

    protected $fillable = [
        'status', 'tier', 'title', 'category', 'body',
        'rough_timeframe', 'shipped_on', 'target_month',
        'display_order', 'is_published',
    ];

    protected $casts = [
        'tier'          => 'integer',
        'shipped_on'    => 'date',
        'target_month'  => 'date',
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

    public const TIERS = [
        1 => 'T1 — Launch blockers',
        2 => 'T2 — Engagement',
        3 => 'T3 — Onboarding',
        4 => 'T4 — Growth',
    ];

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucwords(str_replace('_', ' ', $this->status));
    }

    public function tierLabel(): ?string
    {
        return $this->tier ? (self::TIERS[$this->tier] ?? null) : null;
    }

    /**
     * Public-friendly timeframe string. Prefers shipped date, then target month,
     * then rough_timeframe. Returns null if nothing is set.
     */
    public function displayTimeframe(): ?string
    {
        if ($this->status === 'shipped' && $this->shipped_on) {
            return $this->shipped_on->format('M j, Y');
        }
        if ($this->target_month) {
            return 'Targeting ' . $this->target_month->format('F Y');
        }
        return $this->rough_timeframe;
    }
}
