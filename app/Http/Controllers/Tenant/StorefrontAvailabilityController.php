<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryCategory;
use App\Models\Tenant\TenantInventoryItem;
use App\Services\Distributors\WarehouseRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * MARKER-ECOM-AVAILABILITY
 *
 * The Availability tool in E-commerce settings: what is for sale online and
 * what may be oversold, one item, one category or a whole rule at a time.
 *
 * Two switches per item, both online-store-only (the register never looks):
 *   show_online    the item appears in the store
 *   allow_oversell at 0 stock it stays orderable and each order becomes a special order
 *
 * Every bulk change is counted first (apply=false), applied only after the
 * person confirms, and can be undone: the previous values are held for ten
 * minutes under a token the page keeps.
 */
class StorefrontAvailabilityController extends Controller
{
    private const UNDO_TTL = 600;

    private const RULES = [
        // rule => [field, value]
        'instock-online' => ['show_online', true],
        'catalog-online' => ['show_online', true],
        'catalog-over'   => ['allow_oversell', true],
        'over-off'       => ['allow_oversell', false],
        'all-off'        => ['show_online', false],
    ];

    private function guard(): void
    {
        abort_unless(tenant()->online_store_enabled, 404);
        $me = Auth::guard('tenant')->user();
        abort_unless($me && $me->canAccessSection('storefront'), 403);
    }

    private function base()
    {
        return TenantInventoryItem::query()
            ->where('tenant_id', tenant()->id)
            ->where('is_active', true);
    }

    /** Filters shared by the list and by "select everything that matches". */
    private function filtered(Request $r)
    {
        $q = $this->base();

        if (($term = trim((string) $r->query('q', ''))) !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)
                ->orWhere('sku', 'like', $like)
                ->orWhere('shop_brand', 'like', $like));
        }

        if (($cat = (string) $r->query('cat', '')) !== '') {
            $cat === 'none' ? $q->whereNull('category_id') : $q->where('category_id', $cat);
        }

        match ((string) $r->query('stock', '')) {
            'in'   => $q->where('computed_stock_count', '>', 0),
            'zero' => $q->where('computed_stock_count', '<=', 0),
            default => null,
        };

        // MARKER-DISTRIBUTION-WAREHOUSES: filter by where an order would ship from
        $src = (string) $r->query('src', '');
        if (in_array($src, ['shop', 'supplier', 'special', 'out'], true) && WarehouseRegistry::access(tenant()) !== 'hidden') {
            $ids = $this->idsForSource(clone $q, $src);
            $q->whereIn('id', $ids ?: ['none']);
        }

        match ((string) $r->query('state', '')) {
            'forsale' => $q->where('show_online', true)->where('computed_stock_count', '>', 0),
            'special' => $q->where('show_online', true)->where('computed_stock_count', '<=', 0)->where('allow_oversell', true),
            'out'     => $q->where('show_online', true)->where('computed_stock_count', '<=', 0)->where('allow_oversell', false),
            'offline' => $q->where('show_online', false),
            default   => null,
        };

        return $q;
    }

    private static function state(TenantInventoryItem $i): string
    {
        if (! $i->show_online) return 'offline';
        if ((int) $i->computed_stock_count > 0) return 'forsale';
        return $i->allow_oversell ? 'special' : 'out';
    }

    /** Ids of the filtered items whose first source right now is $src. */
    private function idsForSource($q, string $src): array
    {
        $tenant = tenant();
        $out = [];
        $q->select('id', 'computed_stock_count', 'allow_oversell')->orderBy('id')->chunk(1000, function ($chunk) use ($tenant, $src, &$out) {
            $map = WarehouseRegistry::forItems($tenant, $chunk->pluck('id')->all());
            foreach ($chunk as $i) {
                $h = WarehouseRegistry::headline($tenant, max(0, (int) $i->computed_stock_count), $map[$i->id]['offers'] ?? [], (bool) $i->allow_oversell);
                if ($h['kind'] === $src) $out[] = $i->id;
            }
        });
        return $out;
    }

    /** "Where it's in stock" chips and the ships-from badge for a page of items. */
    private function whereFor($rows): array
    {
        $tenant = tenant();
        $access = WarehouseRegistry::access($tenant);
        if ($access === 'hidden') return [$access, []];
        $map = WarehouseRegistry::forItems($tenant, $rows->pluck('id')->all());
        $cfg = WarehouseRegistry::shopConfig($tenant);
        $out = [];
        foreach ($rows as $i) {
            $stock = max(0, (int) $i->computed_stock_count);
            $m = $map[$i->id] ?? ['chips' => [], 'offers' => []];
            $h = WarehouseRegistry::headline($tenant, $stock, $m['offers'], (bool) $i->allow_oversell);
            $chips = [['n' => 'Shop', 'q' => $stock, 'k' => 'shop', 'on' => true]];
            foreach ($m['chips'] as $c) {
                $chips[] = ['n' => $c['name'], 'q' => $c['qty'], 'k' => 'sup', 'on' => (bool) $c['on']];
            }
            $note = null;
            if ($access === 'on' && $cfg['dropship_on'] && $h['kind'] === 'shop' && $stock > 0 && ! empty($m['offers'])) {
                $note = $cfg['own_first'] ? 'more than ' . $stock . ': rest from supplier' : null;
            }
            $out[$i->id] = ['chips' => $chips, 'ship' => ['kind' => $h['kind'], 'wh' => $h['warehouse'], 'note' => $note]];
        }
        return [$access, $out];
    }

    private function summary(): array
    {
        $b = $this->base();
        return [
            'online'  => (clone $b)->where('show_online', true)->count(),
            'oversell' => (clone $b)->where('show_online', true)->where('allow_oversell', true)->count(),
            'out'     => (clone $b)->where('show_online', true)->where('computed_stock_count', '<=', 0)->where('allow_oversell', false)->count(),
            'offline' => (clone $b)->where('show_online', false)->count(),
            'total'   => (clone $b)->count(),
        ];
    }

    /** GET /storefront/availability/items */
    public function items(Request $r): JsonResponse
    {
        $this->guard();
        $per  = 50;
        $page = max(1, (int) $r->query('page', 1));

        $q     = $this->filtered($r);
        $total = (clone $q)->count();
        $rows  = $q->with('category:id,name')
            ->orderBy('name')
            ->skip(($page - 1) * $per)->take($per)
            ->get(['id', 'name', 'sku', 'shop_brand', 'category_id', 'computed_stock_count',
                   'shop_sell_price_cents', 'catalog_msrp_cents', 'show_online', 'allow_oversell']);

        [$dist, $where] = $this->whereFor($rows);

        return response()->json([
            'ok'      => true,
            'dist'    => $dist,
            'summary' => $this->summary(),
            'total'   => $total,
            'page'    => $page,
            'more'    => $page * $per < $total,
            'items'   => $rows->map(fn ($i) => [
                'id'       => $i->id,
                'name'     => $i->name,
                'sku'      => $i->sku,
                'brand'    => $i->shop_brand,
                'category' => $i->category?->name ?? 'Uncategorized',
                'stock'    => (int) $i->computed_stock_count,
                'price'    => $i->effectiveSellPriceCents(),
                'online'   => (bool) $i->show_online,
                'oversell' => (bool) $i->allow_oversell,
                'state'    => self::state($i),
                'where'    => $where[$i->id]['chips'] ?? null,
                'ship'     => $where[$i->id]['ship'] ?? null,
            ])->values(),
        ]);
    }

    /** GET /storefront/availability/categories */
    public function categories(): JsonResponse
    {
        $this->guard();
        $names = TenantInventoryCategory::query()
            ->where('tenant_id', tenant()->id)->pluck('name', 'id');

        $rows = $this->base()
            ->selectRaw('category_id, COUNT(*) AS n, SUM(show_online) AS online, SUM(allow_oversell) AS oversell')
            ->groupBy('category_id')->get();

        $cats = $rows->map(fn ($r) => [
            'id'       => $r->category_id ?: 'none',
            'name'     => $r->category_id ? ($names[$r->category_id] ?? 'Unnamed category') : 'Uncategorized',
            'total'    => (int) $r->n,
            'online'   => (int) $r->online,
            'oversell' => (int) $r->oversell,
        ])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return response()->json(['ok' => true, 'categories' => $cats, 'summary' => $this->summary()]);
    }

    /** PATCH /storefront/availability/item/{id} — one switch, saved at once. */
    public function updateItem(Request $r, string $id): JsonResponse
    {
        $this->guard();
        $data = $r->validate([
            'online'   => ['nullable', 'boolean'],
            'oversell' => ['nullable', 'boolean'],
        ]);
        $item = $this->base()->findOrFail($id);

        if (array_key_exists('online', $data) && $data['online'] !== null) {
            $item->show_online = (bool) $data['online'];
        }
        if (array_key_exists('oversell', $data) && $data['oversell'] !== null) {
            $item->allow_oversell = (bool) $data['oversell'];
        }
        $item->save();

        return response()->json([
            'ok'      => true,
            'state'   => self::state($item),
            'online'  => (bool) $item->show_online,
            'oversell' => (bool) $item->allow_oversell,
            'summary' => $this->summary(),
        ]);
    }

    /**
     * POST /storefront/availability/bulk
     *   kind     ids | rule | category | filtered
     *   ids[]    for kind=ids
     *   rule     for kind=rule (see RULES)
     *   category_id + field(online|oversell) + value for kind=category
     *   field + value for kind=ids|filtered (filters ride along as q/cat/stock/state)
     *   apply    false = count only, true = do it
     */
    public function bulk(Request $r): JsonResponse
    {
        $this->guard();
        $data = $r->validate([
            'kind'        => ['required', 'in:ids,rule,category,filtered'],
            'ids'         => ['nullable', 'array', 'max:2000'],
            'ids.*'       => ['string'],
            'rule'        => ['nullable', 'string'],
            'category_id' => ['nullable', 'string'],
            'field'       => ['nullable', 'in:online,oversell'],
            'value'       => ['nullable', 'boolean'],
            'apply'       => ['nullable', 'boolean'],
        ]);

        $column = null;
        $value  = (bool) ($data['value'] ?? false);
        $q      = $this->base();

        switch ($data['kind']) {
            case 'rule':
                $rule = $data['rule'] ?? '';
                abort_unless(isset(self::RULES[$rule]), 422, 'Unknown rule.');
                [$column, $value] = self::RULES[$rule];
                if ($rule === 'instock-online') {
                    $q->where('computed_stock_count', '>', 0);
                } elseif ($rule === 'catalog-online' || $rule === 'catalog-over') {
                    $q->whereNotNull('distributor_catalog_id');
                }
                if ($rule === 'all-off') {
                    // "Everything" includes items that were since deactivated.
                    $q = TenantInventoryItem::query()->where('tenant_id', tenant()->id);
                }
                break;

            case 'category':
                abort_unless(! empty($data['field']), 422, 'Pick a switch.');
                $column = $data['field'] === 'online' ? 'show_online' : 'allow_oversell';
                $cid = (string) ($data['category_id'] ?? '');
                $cid === 'none' ? $q->whereNull('category_id') : $q->where('category_id', $cid);
                break;

            case 'ids':
                abort_unless(! empty($data['field']) && ! empty($data['ids']), 422, 'Nothing selected.');
                $column = $data['field'] === 'online' ? 'show_online' : 'allow_oversell';
                $q->whereIn('id', $data['ids']);
                break;

            case 'filtered':
                abort_unless(! empty($data['field']), 422, 'Pick a switch.');
                $column = $data['field'] === 'online' ? 'show_online' : 'allow_oversell';
                $q = $this->filtered($r);
                break;
        }

        // Only rows that would actually change are counted, changed and undoable.
        $q->where($column, ! $value);
        $count = (clone $q)->count();

        if (! $r->boolean('apply')) {
            return response()->json(['ok' => true, 'count' => $count]);
        }

        $token = null;
        if ($count > 0) {
            $prev = (clone $q)->get(['id', 'show_online', 'allow_oversell'])
                ->map(fn ($i) => [$i->id, (bool) $i->show_online, (bool) $i->allow_oversell])->all();

            (clone $q)->update([$column => $value]);

            $token = (string) Str::uuid();
            Cache::put($this->undoKey($token), ['rows' => $prev], self::UNDO_TTL);
        }

        return response()->json([
            'ok'      => true,
            'count'   => $count,
            'undo'    => $token,
            'summary' => $this->summary(),
        ]);
    }

    /** POST /storefront/availability/undo — put the last bulk change back. */
    public function undo(Request $r): JsonResponse
    {
        $this->guard();
        $token = (string) $r->validate(['token' => ['required', 'string', 'max:64']])['token'];
        $held  = Cache::pull($this->undoKey($token));
        if (! $held) {
            return response()->json(['ok' => false, 'message' => 'That change can no longer be undone.'], 410);
        }

        $n = 0;
        foreach (array_chunk($held['rows'], 500) as $chunk) {
            foreach ($chunk as [$id, $online, $oversell]) {
                $n += TenantInventoryItem::query()
                    ->where('tenant_id', tenant()->id)->where('id', $id)
                    ->update(['show_online' => $online, 'allow_oversell' => $oversell]);
            }
        }

        return response()->json(['ok' => true, 'count' => $n, 'summary' => $this->summary()]);
    }

    private function undoKey(string $token): string
    {
        return 'ecom_avail_undo:' . tenant()->id . ':' . $token;
    }
}
