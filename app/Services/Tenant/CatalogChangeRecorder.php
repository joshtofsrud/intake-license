<?php

namespace App\Services\Tenant;

use App\Models\Tenant\CatalogChangeBatch;
use App\Models\Tenant\CatalogChangeItem;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-CATALOG-HISTORY — records what a bulk action changed, so it can be
 * put back.
 *
 * Deliberately forgiving: every method swallows its own failure. A broken
 * history table must not stop a shop adopting a title — the change is the
 * point, the record is the safety net, and refusing the first because the
 * second is unavailable would be the wrong trade.
 */
class CatalogChangeRecorder
{
    private ?CatalogChangeBatch $batch = null;
    private array $pending = [];

    /** Fields each action touches — the only ones worth storing. */
    public const FIELDS = [
        'adopt_title'   => ['name'],
        'adopt_details' => ['color', 'size', 'description'],
        'raise_map'     => ['shop_sell_price_cents'],
        'match_msrp'    => ['shop_sell_price_cents'],
    ];

    public function __construct(
        private string $tenantId,
        private string $action,
        private array $filter = [],
        private ?string $runBy = null,
    ) {}

    public function tracks(): bool
    {
        return array_key_exists($this->action, self::FIELDS);
    }

    /** Call BEFORE the item is saved. */
    public function capture($item): void
    {
        if (! $this->tracks() || ! $item) {
            return;
        }

        $fields = self::FIELDS[$this->action];
        $before = [];
        foreach ($fields as $f) {
            $before[$f] = $item->{$f} ?? null;
        }

        $this->pending[$item->id] = ['before' => $before, 'after' => null];
    }

    /** Call AFTER the item is saved, so we know what we wrote. */
    public function captured($item): void
    {
        if (! isset($this->pending[$item->id])) {
            return;
        }

        $after = [];
        foreach (self::FIELDS[$this->action] as $f) {
            $after[$f] = $item->{$f} ?? null;
        }
        $this->pending[$item->id]['after'] = $after;
    }

    /** Write the batch. Returns the batch id, or null if nothing was tracked. */
    public function finish(): ?string
    {
        if (! $this->tracks() || ! $this->pending) {
            return null;
        }

        try {
            if (! Schema::hasTable('catalog_change_batches')) {
                return null;
            }

            $this->batch = CatalogChangeBatch::create([
                'tenant_id'  => $this->tenantId,
                'action'     => $this->action,
                'filter'     => $this->filter,
                'item_count' => count($this->pending),
                'run_by'     => $this->runBy,
            ]);

            $rows = [];
            foreach ($this->pending as $itemId => $vals) {
                $rows[] = [
                    'batch_id'   => $this->batch->id,
                    'tenant_id'  => $this->tenantId,
                    'item_id'    => $itemId,
                    'before'     => json_encode($vals['before']),
                    'after'      => json_encode($vals['after']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                CatalogChangeItem::insert($chunk);
            }

            return $this->batch->id;
        } catch (\Throwable $e) {
            logger()->warning('MARKER-CATALOG-HISTORY could not record a batch', [
                'action' => $this->action, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
