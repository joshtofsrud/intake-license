<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// MARKER-CATALOG-HISTORY — one bulk catalog action, and the way back from it.
class CatalogChangeBatch extends Model
{
    use HasUuids;

    protected $table = 'catalog_change_batches';

    protected $fillable = [
        'tenant_id', 'action', 'filter', 'item_count', 'run_by',
        'undone_at', 'undone_by', 'restored_count', 'kept_count',
    ];

    protected $casts = [
        'filter'    => 'array',
        'undone_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(CatalogChangeItem::class, 'batch_id');
    }

    public function isUndone(): bool
    {
        return $this->undone_at !== null;
    }

    /** Only actions that changed something can be put back. */
    public function isReversible(): bool
    {
        return ! $this->isUndone()
            && in_array($this->action, ['adopt_title', 'adopt_details', 'raise_map', 'match_msrp'], true);
    }

    public function label(): string
    {
        return [
            'adopt_title'   => 'Adopted new titles',
            'keep_title'    => 'Kept your titles',
            'adopt_details' => 'Adopted new details',
            'keep_details'  => 'Kept your details',
            'raise_map'     => 'Raised to MAP',
            'match_msrp'    => 'Matched MSRP',
            'acknowledge'   => 'Dismissed',
        ][$this->action] ?? $this->action;
    }
}
