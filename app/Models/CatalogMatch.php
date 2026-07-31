<?php

// MARKER-CATALOG-MATCHES

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogMatch extends Model
{
    protected $fillable = [
        'row_a_id', 'row_b_id', 'code_a', 'code_b',
        'status', 'matched_on', 'evidence',
        'msrp_spread_pct', 'hold_reason', 'decided_at',
    ];

    protected $casts = [
        'evidence'   => 'array',
        'decided_at' => 'datetime',
    ];

    /** A human said yes or no; the matcher must not touch these again. */
    public function isDecided(): bool
    {
        return in_array($this->status, ['confirmed', 'rejected'], true);
    }

    public function rowA() { return $this->belongsTo(PlatformDistributorCatalog::class, 'row_a_id'); }
    public function rowB() { return $this->belongsTo(PlatformDistributorCatalog::class, 'row_b_id'); }
}
