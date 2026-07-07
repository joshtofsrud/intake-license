<?php
// MARKER-CAMPAIGNS-CORE

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A sales campaign/channel: one vertical's targeting + playbook definition.
 * Prospects keep their stage when a channel's criteria change — the channel
 * describes how to sell, the prospect records where the deal is.
 */
class SalesChannel extends Model
{
    use HasUuids;

    protected $table = 'sales_channels';

    protected $fillable = [
        'name', 'slug', 'status', 'categories', 'business_types',
        'criteria', 'playbook', 'best_ask', 'generated_by', 'notes',
    ];

    protected $casts = [
        'categories'     => 'array',
        'business_types' => 'array',
        'criteria'       => 'array',
        'playbook'       => 'array',
    ];

    public const STATUSES = [
        'active' => 'Active',
        'draft'  => 'Draft',
        'stub'   => 'Stub',
    ];

    /** Default playbook labels for a fresh channel — display guidance only. */
    public const DEFAULT_PLAYBOOK = ['Prospect', 'Verify', 'Contact', 'Demo', 'Trial', 'Won'];

    public function prospects(): HasMany
    {
        return $this->hasMany(SalesProspect::class, 'channel_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            if (blank($c->slug)) {
                $c->slug = Str::slug($c->name);
            }
        });
    }
}
