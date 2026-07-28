<?php

// MARKER-TITLE-SCOPES

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per (distributor, distributor category path).
 *
 * category_key is the distributor's own path from the feed. It is NOT a
 * tenant category and must never be populated from tenant data — tenants
 * build and rename their own category trees, and title rules have to stay
 * independent of that.
 */
class CatalogTitleScope extends Model
{
    protected $fillable = [
        'distributor_code', 'category_key', 'item_count',
        'resolved_rule_scope', 'has_own_rule', 'flags', 'sample_ids',
        'sample_title', 'severity', 'reviewed', 'reviewed_at', 'scanned_at',
    ];

    protected $casts = [
        'flags'        => 'array',
        'sample_ids'   => 'array',
        'has_own_rule' => 'boolean',
        'reviewed'     => 'boolean',
        'reviewed_at'  => 'datetime',
        'scanned_at'   => 'datetime',
    ];

    /**
     * MARKER-FLAG-TUNING — 'healthy' means nothing needs a human, so a
     * scope carrying only info findings counts as healthy even though its
     * flags array isn't empty.
     */
    public function isHealthy(): bool
    {
        return ! $this->needsReview();
    }

    public function needsReview(): bool
    {
        return in_array($this->severity, ['warn', 'bad'], true);
    }

    /** Only the findings worth showing as problems — info excluded. */
    public function problems(): array
    {
        return array_values(array_filter(
            $this->flags ?? [],
            fn ($f) => in_array($f['severity'] ?? null, ['warn', 'bad'], true)
        ));
    }

    /** Context findings for the editor: empty tokens and the like. */
    public function notes(): array
    {
        return array_values(array_filter(
            $this->flags ?? [],
            fn ($f) => ($f['severity'] ?? null) === 'info'
        ));
    }
}
