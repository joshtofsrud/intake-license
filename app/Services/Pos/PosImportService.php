<?php

namespace App\Services\Pos;

use App\Models\Tenant\CatalogChangeBatch;
use App\Models\Tenant\CatalogChangeItem;
use App\Models\Tenant\TenantInventoryCategory;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemVendor;
use App\Models\Tenant\TenantPosConnection;
use App\Models\Tenant\TenantVendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-CONNECTED-POS-SECTION: Inventory › Import, with the shop's Connected POS as the source.
 *
 * Nothing comes into Intake from a Connected POS unless someone asks for it here.
 * The rules are the distributor importer's, in the same order:
 *
 *  - a product already linked is left alone
 *  - a product that is an item the shop already carries (same barcode, or the same part
 *    number) is LINKED to that item, never duplicated
 *  - a product that only shares a SKU with an item is a suggestion: it is linked when
 *    the shop picks it (one row, or ticked rows), never by "import everything"
 *  - anything else becomes a new item, off the online store, with the Connected POS's
 *    name, identifiers, brand, category (when the shop has one by that name), supplier,
 *    cost, price and stock
 *
 * A run works through the list in steps, keeps its place, and can be stopped and
 * resumed. Everything a run does is written to one batch in catalog history, where it
 * can be undone: links are removed, and items it made are removed unless they have
 * been sold or adjusted since.
 */
class PosImportService
{
    public const STEP = 150;

    private ?array $catMap = null;
    /** @var array<string, string> */
    private array $vendors = [];

    public function __construct(private PosCatalogSync $sync) {}

    // ── filters, counts, options ────────────────────────────────

    /** The filters the Import screen sends, tidied. */
    public static function filters(array $in): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['ids'] ?? [])))));

        return array_filter([
            'brand'           => filled($in['brand'] ?? null) ? (string) $in['brand'] : null,
            'category'        => filled($in['category'] ?? null) ? (string) $in['category'] : null,
            'in_stock'        => ! empty($in['in_stock']),
            'new_only'        => ! empty($in['new_only']),
            'include_removed' => ! empty($in['include_removed']),
            'ids'             => $ids ?: null,
        ], fn ($v) => $v !== null && $v !== false);
    }

    /** Connected POS products matching the filters (linked ones included; callers narrow further). */
    public function base(TenantPosConnection $conn, array $f)
    {
        $q = DB::table('tenant_pos_items as p')->where('p.tenant_id', $conn->tenant_id);
        if (empty($f['include_removed'])) {
            $q->whereNull('p.removed_at');
        }
        if (! empty($f['brand'])) {
            $q->where('p.brand', $f['brand']);
        }
        if (! empty($f['category'])) {
            $q->where('p.category', $f['category']);
        }
        if (! empty($f['new_only'])) {
            $q->whereNull('p.inventory_item_id');
            if ($conn->items_marked_at) {
                $q->where('p.first_seen_at', '>', $conn->items_marked_at);
            }
        }
        if (! empty($f['in_stock'])) {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('tenant_pos_stock as s')
                ->whereColumn('s.tenant_id', 'p.tenant_id')->whereColumn('s.external_item_id', 'p.external_id')->where('s.qty', '>', 0));
        }
        if (! empty($f['ids'])) {
            $q->whereIn('p.id', $f['ids']);
        }

        return $q;
    }

    /** The numbers the Connected POS pages show. */
    public function counts(TenantPosConnection $conn): array
    {
        $t = DB::table('tenant_pos_items')->where('tenant_id', $conn->tenant_id);
        $live = fn () => (clone $t)->whereNull('removed_at');

        return [
            'total'   => $live()->count(),
            'linked'  => $live()->whereNotNull('inventory_item_id')->count(),
            'waiting' => $live()->whereNull('inventory_item_id')->count(),
            'new'     => $this->newQuery($conn)->count(),
            'removed' => (clone $t)->whereNotNull('removed_at')->count(),
        ];
    }

    /** Products first seen since the shop's last import (or since it last marked them seen), still waiting. */
    public function newQuery(TenantPosConnection $conn)
    {
        $q = DB::table('tenant_pos_items as p')->where('p.tenant_id', $conn->tenant_id)->whereNull('p.removed_at')->whereNull('p.inventory_item_id');

        return $conn->items_marked_at ? $q->where('p.first_seen_at', '>', $conn->items_marked_at) : $q->whereRaw('1 = 0');
    }

    /** Brands and categories the waiting products carry, for the two pickers. */
    public function options(TenantPosConnection $conn): array
    {
        $col = fn (string $c) => DB::table('tenant_pos_items')->where('tenant_id', $conn->tenant_id)->whereNull('removed_at')
            ->whereNotNull($c)->where($c, '!=', '')->distinct()->orderBy($c)->limit(3000)->pluck($c)->all();

        return ['brands' => $col('brand'), 'categories' => $col('category')];
    }

    // ── preview ─────────────────────────────────────────────────

    /**
     * What Import would do with each product, in the order it would do it.
     *
     * @return array{counts: array<string,int>, rows: list<array<string,mixed>>}
     */
    public function plan(TenantPosConnection $conn, array $f): array
    {
        if ((int) ini_get('memory_limit') > 0 && (int) ini_get('memory_limit') < 512) {
            @ini_set('memory_limit', '512M');
        }
        $tenantId = $conn->tenant_id;
        $matcher = PosMatcher::forTenant($tenantId);
        $stock = DB::table('tenant_pos_stock')->where('tenant_id', $tenantId)->selectRaw('external_item_id, SUM(qty) q')->groupBy('external_item_id')->pluck('q', 'external_item_id');
        $marked = $conn->items_marked_at;

        $rows = [];
        $counts = ['new' => 0, 'merge' => 0, 'linked' => 0, 'check' => 0, 'skip' => 0];
        foreach ($this->base($conn, $f)->orderBy('p.id')->cursor() as $p) {
            $row = [
                'id'       => (int) $p->id,
                'name'     => (string) ($p->name ?: $p->sku ?: 'Unnamed product'),
                'brand'    => (string) ($p->brand ?? ''),
                'part'     => (string) ($p->sku ?? ''),
                'mpn'      => (string) ($p->mpn ?? ''),
                'code'     => (string) ($p->upc ?: $p->ean ?: ''),
                'price'    => $p->price_cents !== null ? (int) $p->price_cents : null,
                'stock'    => $p->track_stock ? (int) ($stock[$p->external_id] ?? 0) : null,
                'is_new'   => ! $p->inventory_item_id && $marked && $p->first_seen_at && $p->first_seen_at > $marked->toDateTimeString(),
                'removed'  => $p->removed_at !== null,
                'category' => (string) ($p->category ?? ''),
                'item'     => null, 'item_name' => null, 'kind' => null, 'cat_to' => null,
            ];

            if ($p->inventory_item_id) {
                $row['action'] = 'linked';
                $row['item'] = (string) $p->inventory_item_id;
                $row['item_name'] = $matcher->name((string) $p->inventory_item_id);
            } elseif ($hit = $matcher->find((array) $p)) {
                $row['action'] = 'merge';
                $row['item'] = $hit['id'];
                $row['item_name'] = $matcher->name($hit['id']);
                $row['kind'] = $hit['how'];
                if (in_array($hit['how'], PosMatcher::STRONG, true)) {
                    $matcher->take($hit['id']);
                } else {
                    $counts['check']++;
                }
            } elseif ($dupe = $matcher->findTaken((array) $p)) {
                // the same product twice in the Connected POS: one Intake item can't be both
                $row['action'] = 'skip';
                $row['kind'] = 'same';
                $row['item'] = str_starts_with($dupe, 'dry:') ? null : $dupe;
                $row['item_name'] = $matcher->name($dupe);
            } else {
                $row['action'] = 'new';
                $cat = $this->categoryFor($tenantId, $p->category);
                $row['cat_to'] = $cat['label'] ?? null;
                $matcher->learn('dry:' . $p->id, $row['name'], $p->sku, $p->upc, $p->ean, $p->mpn, $p->brand);
                $matcher->take('dry:' . $p->id);
            }
            $counts[$row['action']]++;
            $rows[] = $row;
        }

        return ['counts' => $counts, 'rows' => $rows];
    }

    // ── a run ───────────────────────────────────────────────────

    /** Begin a run over every waiting product the filters match. */
    public function start(TenantPosConnection $conn, array $f, ?string $runBy): void
    {
        $batch = CatalogChangeBatch::create([
            'tenant_id'  => $conn->tenant_id,
            'action'     => 'pos_import',
            'filter'     => $f,
            'item_count' => 0,
            'run_by'     => $runBy,
        ]);

        $conn->forceFill(['import_state' => [
            'running' => true, 'stopped' => false, 'error' => null,
            'batch_id' => $batch->id, 'filters' => $f, 'explicit' => ! empty($f['ids']),
            'total' => $this->base($conn, $f)->whereNull('p.inventory_item_id')->count(),
            'done' => 0, 'last_id' => 0,
            'created' => 0, 'linked' => 0, 'uncategorized' => 0, 'check' => 0, 'skipped' => 0, 'errors' => 0,
            'started_at' => now()->toIso8601String(), 'finished_at' => null,
        ]])->save();
    }

    /** Do one step from where the run stands. Returns true when there is more to do. */
    public function step(TenantPosConnection $conn): bool
    {
        $st = (array) $conn->import_state;
        $f = (array) ($st['filters'] ?? []);
        $rows = $this->base($conn, $f)->whereNull('p.inventory_item_id')->where('p.id', '>', (int) ($st['last_id'] ?? 0))
            ->orderBy('p.id')->limit(self::STEP)->get();

        $matcher = $rows->isEmpty() ? null : PosMatcher::forTenant($conn->tenant_id);
        foreach ($rows as $p) {
            // a Stop pressed mid-step takes effect at the next product, and the place is kept
            if ($this->stopped($conn)) {
                return false;
            }
            try {
                $did = $this->importRow($conn, $p, $matcher, (string) $st['batch_id'], ! empty($st['explicit']));
            } catch (\Throwable $e) {
                $did = 'error';
                if (($st['errors'] ?? 0) === 0) {
                    \App\Support\JobFailureReporter::report(self::class, 'Connected POS import is skipping products it cannot bring in', $e, ['pos_item' => $p->id], $conn->tenant_id);
                }
            }
            $key = ['created' => 'created', 'created_uncat' => 'created', 'linked' => 'linked', 'check' => 'check', 'skip' => 'skipped', 'error' => 'errors'][$did] ?? null;
            if ($key) {
                $st[$key] = ($st[$key] ?? 0) + 1;
            }
            if ($did === 'created_uncat') {
                $st['uncategorized'] = ($st['uncategorized'] ?? 0) + 1;
            }
            $st['done'] = ($st['done'] ?? 0) + 1;
            $st['last_id'] = (int) $p->id;
            if ($this->stopped($conn)) {
                // Stop arrived while this product was being brought in: keep the stop, and the place
                $st['running'] = false;
                $st['stopped'] = true;
                $st['finished_at'] = now()->toIso8601String();
                $conn->forceFill(['import_state' => $st])->save();
                CatalogChangeBatch::where('id', $st['batch_id'])->update(['item_count' => (int) $st['created'] + (int) $st['linked']]);

                return false;
            }
            $conn->forceFill(['import_state' => $st])->save();
        }

        if ($rows->count() < self::STEP) {
            $st['running'] = false;
            $st['finished_at'] = now()->toIso8601String();
            $st['done'] = max((int) $st['done'], (int) ($st['total'] ?? 0));
            $fill = ['import_state' => $st];
            if (empty($st['explicit'])) {
                $fill['items_marked_at'] = now(); // what was new has now been looked at
            }
            $conn->forceFill($fill)->save();
            CatalogChangeBatch::where('id', $st['batch_id'])->update(['item_count' => (int) $st['created'] + (int) $st['linked']]);
            if ((int) $st['created'] > 0) {
                \App\Jobs\FindDuplicateItemsJob::dispatch($conn->tenant_id);
            }

            return false;
        }

        return true;
    }

    /** Has the shop pressed Stop since this step read its state? */
    private function stopped(TenantPosConnection $conn): bool
    {
        $raw = DB::table('tenant_pos_connections')->where('id', $conn->id)->value('import_state');

        return empty(((array) json_decode((string) $raw, true))['running']);
    }

    public function fail(TenantPosConnection $conn, string $message): void
    {
        $st = (array) ($conn->import_state ?? []);
        $st['running'] = false;
        $st['error'] = Str::limit($message, 400);
        $st['finished_at'] = now()->toIso8601String();
        $conn->forceFill(['import_state' => $st])->save();
        if (! empty($st['batch_id'])) {
            CatalogChangeBatch::where('id', $st['batch_id'])->update(['item_count' => (int) ($st['created'] ?? 0) + (int) ($st['linked'] ?? 0)]);
        }
    }

    /**
     * Bring in one product the shop picked from the list.
     *
     * @return array{did: string, item: ?string}
     */
    public function importOne(TenantPosConnection $conn, int $posItemId, ?string $runBy): array
    {
        $p = DB::table('tenant_pos_items as p')->where('p.tenant_id', $conn->tenant_id)->where('p.id', $posItemId)->first();
        if (! $p) {
            return ['did' => 'missing', 'item' => null];
        }
        if ($p->inventory_item_id) {
            return ['did' => 'already', 'item' => (string) $p->inventory_item_id];
        }

        // picks made one after another share a batch, so they can be undone together
        $batch = CatalogChangeBatch::where('tenant_id', $conn->tenant_id)->where('action', 'pos_import')->whereNull('undone_at')
            ->where('run_by', $runBy)->where('created_at', '>', now()->subMinutes(30))->orderByDesc('created_at')->get()
            ->first(fn ($b) => ! empty(($b->filter ?? [])['picked']));
        $batch ??= CatalogChangeBatch::create([
            'tenant_id' => $conn->tenant_id, 'action' => 'pos_import', 'filter' => ['picked' => true], 'item_count' => 0, 'run_by' => $runBy,
        ]);

        $did = $this->importRow($conn, $p, PosMatcher::forTenant($conn->tenant_id), (string) $batch->id, true);
        if (in_array($did, ['created', 'created_uncat', 'linked'], true)) {
            $batch->increment('item_count');
        }

        return [
            'did'  => $did === 'created_uncat' ? 'created' : $did,
            'item' => DB::table('tenant_pos_items')->where('id', $p->id)->value('inventory_item_id'),
        ];
    }

    /**
     * One product: link it to the item it is, or make the item.
     *
     * @return string created | created_uncat | linked | check | skip
     */
    private function importRow(TenantPosConnection $conn, object $p, PosMatcher $matcher, string $batchId, bool $explicit): string
    {
        $tenantId = $conn->tenant_id;
        $hit = $matcher->find((array) $p);

        if ($hit && ! $explicit && ! in_array($hit['how'], PosMatcher::STRONG, true)) {
            return 'check'; // shares only a SKU with an item: left for the shop to decide
        }
        if (! $hit && $matcher->findTaken((array) $p)) {
            return 'skip'; // the same product twice in the Connected POS; its twin already has the item
        }

        return DB::transaction(function () use ($conn, $p, $matcher, $batchId, $hit, $tenantId) {
            $was = PosCatalogSync::$applying;
            PosCatalogSync::$applying = true; // what comes from the Connected POS is never sent back to it
            try {
                if ($hit) {
                    $item = TenantInventoryItem::where('tenant_id', $tenantId)->find($hit['id']);
                    if (! $item) {
                        return 'skip';
                    }
                    $this->fillBlanks($item, $p);
                    $kind = 'linked';
                } else {
                    $cat = $this->categoryFor($tenantId, $p->category);
                    $item = $this->create($tenantId, $p, $cat['id'] ?? null);
                    $matcher->learn((string) $item->id, (string) $item->name, $item->sku, $item->catalog_upc, $item->catalog_ean, $item->catalog_mpn, $item->shop_brand);
                    $kind = ($cat['id'] ?? null) ? 'created' : 'created_uncat';
                }

                DB::table('tenant_pos_items')->where('id', $p->id)->update(['inventory_item_id' => $item->id]);
                $matcher->take((string) $item->id);
                $this->supplier($tenantId, $item, $p);
                $this->sync->levelWith($conn, $item, $p, $kind !== 'linked');

                CatalogChangeItem::create([
                    'batch_id'  => $batchId,
                    'tenant_id' => $tenantId,
                    'item_id'   => $item->id,
                    'before'    => ['_pos' => $kind === 'linked' ? 'linked' : 'created', 'product' => (string) ($p->name ?: $p->sku), 'ext' => (string) $p->external_id],
                    'after'     => null,
                ]);

                return $kind;
            } finally {
                PosCatalogSync::$applying = $was;
            }
        });
    }

    private function create(string $tenantId, object $p, ?string $categoryId): TenantInventoryItem
    {
        $sku = $this->freeSku($tenantId, $p->sku ?: 'POS-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $p->external_id), 0, 12)));
        $code = $p->upc ?: $p->ean;
        // the shared catalog's content (photos, specs) when the barcode is one it knows
        $catalogId = $code ? DB::table('platform_distributor_catalogs')->where('upc', $code)->where('is_active', true)->value('id') : null;

        return TenantInventoryItem::create([
            'tenant_id'              => $tenantId,
            'category_id'            => $categoryId,
            'sku'                    => $sku,
            'name'                   => \App\Services\Distributors\CatalogTitleComposer::collapseRepeats((string) ($p->name ?: $sku)),
            'catalog_upc'            => $p->upc ? substr((string) $p->upc, 0, 20) : null,
            'catalog_ean'            => $p->ean ?: null,
            'catalog_mpn'            => $p->mpn ?: null,
            'shop_brand'             => $p->brand ?: null,
            'description'            => $p->description ?: null,
            'shop_sell_price_cents'  => $p->price_cents,
            'shop_cost_cents'        => $p->cost_cents,
            'distributor_catalog_id' => $catalogId,
            'is_active'              => true,
            'show_online'            => false,
            'allow_oversell'         => false,
            'is_stock_tracked'       => (bool) $p->track_stock,
            'computed_stock_count'   => 0,
        ]);
    }

    /** An item the shop already had: only what it left blank is filled. */
    private function fillBlanks(TenantInventoryItem $item, object $p): void
    {
        $fill = [];
        if (blank($item->catalog_upc) && filled($p->upc)) {
            $fill['catalog_upc'] = substr((string) $p->upc, 0, 20);
        }
        if (blank($item->catalog_ean) && filled($p->ean)) {
            $fill['catalog_ean'] = (string) $p->ean;
        }
        if (blank($item->catalog_mpn) && filled($p->mpn)) {
            $fill['catalog_mpn'] = (string) $p->mpn;
        }
        if (blank($item->shop_brand) && filled($p->brand)) {
            $fill['shop_brand'] = (string) $p->brand;
        }
        if ($item->shop_cost_cents === null && $p->cost_cents) {
            $fill['shop_cost_cents'] = (int) $p->cost_cents;
        }
        if ($fill) {
            $item->forceFill($fill)->save();
        }
    }

    /** The Connected POS's supplier for this product, as one of the shop's vendors. */
    private function supplier(string $tenantId, TenantInventoryItem $item, object $p): void
    {
        $name = trim((string) ($p->supplier ?? ''));
        if ($name === '') {
            return;
        }
        $key = mb_strtolower($name);
        if (! isset($this->vendors[$key])) {
            $v = TenantVendor::where('tenant_id', $tenantId)->whereRaw('LOWER(name) = ?', [$key])->first()
                ?? TenantVendor::create(['tenant_id' => $tenantId, 'name' => Str::limit($name, 190, ''), 'is_active' => true]);
            $this->vendors[$key] = (string) $v->id;
        }
        TenantInventoryItemVendor::firstOrCreate(
            ['inventory_item_id' => $item->id, 'vendor_id' => $this->vendors[$key]],
            ['vendor_sku' => $p->supplier_code ?: null, 'unit_cost_cents' => $p->cost_cents ?: null],
        );
        if (! $item->default_vendor_id) {
            $item->forceFill(['default_vendor_id' => $this->vendors[$key]])->save();
        }
    }

    /**
     * The shop's own category with the same name (or the same path) as the Connected POS's.
     * No match means Uncategorized, where the shop's usual tools sort it.
     *
     * @return array{id: string, label: string}|null
     */
    public function categoryFor(string $tenantId, ?string $posCategory): ?array
    {
        $want = self::catKey($posCategory);
        if ($want === '') {
            return null;
        }
        if ($this->catMap === null) {
            $this->catMap = [];
            $cats = TenantInventoryCategory::where('tenant_id', $tenantId)->get(['id', 'parent_id', 'name'])->keyBy('id');
            $leaf = [];
            foreach ($cats as $c) {
                $path = [$c->name];
                for ($up = $c, $i = 0; $up->parent_id && isset($cats[$up->parent_id]) && $i < 6; $i++) {
                    $up = $cats[$up->parent_id];
                    array_unshift($path, $up->name);
                }
                $hit = ['id' => (string) $c->id, 'label' => implode(' › ', $path)];
                $this->catMap[self::catKey(implode('/', $path))] ??= $hit;
                $leaf[self::catKey($c->name)][] = $hit;
            }
            // a bare name counts only when exactly one of the shop's categories has it
            foreach ($leaf as $k => $hits) {
                if (count($hits) === 1) {
                    $this->catMap[$k] ??= $hits[0];
                }
            }
        }
        if (isset($this->catMap[$want])) {
            return $this->catMap[$want];
        }
        // "Parts / Tyres" in the Connected POS, "Tyres" here
        $last = self::catKey(Str::afterLast(str_replace(['>', '›', '\\'], '/', (string) $posCategory), '/'));

        return $last !== '' && $last !== $want ? ($this->catMap[$last] ?? null) : null;
    }

    private static function catKey(?string $v): string
    {
        $parts = array_filter(array_map(fn ($s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', $s))), preg_split('#[/>›\\\\]+#u', (string) $v) ?: []), fn ($s) => $s !== '');

        return implode('/', $parts);
    }

    /** SKUs are unique per shop, archived items included. */
    private function freeSku(string $tenantId, string $want): string
    {
        $want = Str::limit($want, 60, '');
        $sku = $want;
        for ($n = 2; TenantInventoryItem::withTrashed()->where('tenant_id', $tenantId)->where('sku', $sku)->exists(); $n++) {
            $sku = Str::limit($want, 56, '') . '-' . $n;
        }

        return $sku;
    }

    // ── undo ────────────────────────────────────────────────────

    /**
     * Put a Connected POS import back. A link is removed. An item the import made is
     * removed, with the stock the Connected POS gave it, unless it has been sold or had
     * its stock changed in Intake since — those are kept and counted.
     *
     * @return array{restored: int, kept: int, keptItems: list<string>}
     */
    public function undo(CatalogChangeBatch $batch, ?string $onlyItemId = null, ?string $by = null): array
    {
        $restored = 0;
        $kept = 0;
        $keptItems = [];

        $rows = CatalogChangeItem::where('batch_id', $batch->id)->whereNull('restored_at')
            ->when($onlyItemId, fn ($q) => $q->where('item_id', $onlyItemId))->cursor();

        foreach ($rows as $row) {
            $made = (($row->before ?? [])['_pos'] ?? '') === 'created';
            $item = TenantInventoryItem::withTrashed()->where('tenant_id', $batch->tenant_id)->find($row->item_id);

            if ($made && $item && $this->usedSince($item)) {
                $kept++;
                if (count($keptItems) < 20) {
                    $keptItems[] = $item->name;
                }
                continue;
            }

            try {
              DB::transaction(function () use ($batch, $row, $item, $made) {
                DB::table('tenant_pos_items')->where('tenant_id', $batch->tenant_id)->where('inventory_item_id', $row->item_id)
                    ->update(['inventory_item_id' => null, 'snapshot' => null, 'conflict' => null]);
                if ($made && $item) {
                    DB::table('tenant_inventory_movements')->where('tenant_id', $batch->tenant_id)->where('inventory_item_id', $item->id)->delete();
                    DB::table('tenant_inventory_item_locations')->where('inventory_item_id', $item->id)->delete();
                    TenantInventoryItemVendor::where('inventory_item_id', $item->id)->delete();
                    DB::table('tenant_pos_pushes')->where('tenant_id', $batch->tenant_id)->whereIn('kind', ['item_create', 'item_update', 'item_details'])->where('ref_id', $item->id)->delete();
                    $item->forceDelete();
                }
                $row->restored_at = now();
                $row->save();
              });
            } catch (\Throwable $e) {
                // something else in Intake now depends on this item: it stays, and is counted as kept
                \App\Support\JobFailureReporter::report(self::class, 'Connected POS import undo kept an item it could not remove', $e, ['item' => $row->item_id], $batch->tenant_id);
                $kept++;
                if ($item && count($keptItems) < 20) {
                    $keptItems[] = $item->name;
                }
                continue;
            }
            $restored++;
        }

        if ($onlyItemId === null) {
            $batch->undone_at = now();
            $batch->undone_by = $by;
            $batch->restored_count = $restored;
            $batch->kept_count = $kept;
            $batch->save();
        }

        return ['restored' => $restored, 'kept' => $kept, 'keptItems' => $keptItems];
    }

    /** Sold, or its stock changed by anything other than the Connected POS's own counts. */
    public function usedSince(TenantInventoryItem $item): bool
    {
        return DB::table('tenant_sale_items')->where('inventory_item_id', $item->id)->exists()
            || DB::table('tenant_inventory_movements')->where('inventory_item_id', $item->id)->where('movement_type', '!=', 'pos_sync')->exists();
    }
}
