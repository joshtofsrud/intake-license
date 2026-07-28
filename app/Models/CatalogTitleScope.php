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
        'sample_title', 'reviewed', 'reviewed_at', 'scanned_at',
    ];

    protected $casts = [
        'flags'        => 'array',
        'sample_ids'   => 'array',
        'has_own_rule' => 'boolean',
        'reviewed'     => 'boolean',
        'reviewed_at'  => 'datetime',
        'scanned_at'   => 'datetime',
    ];

    public function isHealthy(): bool
    {
        return empty($this->flags);
    }

    /** Worst severity present: 'bad' > 'warn' > null. */
    public function severity(): ?string
    {
        $codes = array_column($this->flags ?? [], 'severity');
        if (in_array('bad', $codes, true))  return 'bad';
        if (in_array('warn', $codes, true)) return 'warn';
        return null;
    }
}
