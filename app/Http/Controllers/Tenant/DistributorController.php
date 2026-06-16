<?php
// MARKER-PATCH-HLC7A

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\TenantDistributorSyncJob;
use App\Models\Tenant\TenantDistributorCatalogSubscription;
use App\Models\Tenant\TenantInventoryItemVendor;
use App\Models\Tenant\TenantPricingAttentionFlag;
use App\Services\Distributors\DistributorRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tenant distributor surface. Phase 1: Connection & Sync — the shop's own key,
 * test, status, and on-demand refresh. Import + Pricing Attention follow.
 */
class DistributorController extends Controller
{
    private const CODE = 'HLC';

    private function guard(): void
    {
        abort_unless(tenant()->distributor_sync_enabled, 403);
    }

    private function subscription(): TenantDistributorCatalogSubscription
    {
        return TenantDistributorCatalogSubscription::firstOrCreate(
            ['tenant_id' => tenant()->id, 'distributor_code' => self::CODE],
            ['is_active' => true],
        );
    }

    public function connection(): View
    {
        $this->guard();
        $tenant = tenant();
        $sub = $this->subscription();
        $creds = (array) ($sub->credentials_encrypted ?? []);

        return view('tenant.distributors.connection', [
            'sub'          => $sub,
            'hasKey'       => filled($creds['api_key'] ?? null),
            'maskedKey'    => $this->mask($creds['api_key'] ?? null),
            'accountNo'    => $sub->account_number,
            'linkedCount'  => TenantInventoryItemVendor::query()
                ->where('distributor_code', self::CODE)
                ->whereNotNull('distributor_catalog_id')
                ->whereHas('item', fn ($q) => $q->where('tenant_id', $tenant->id))
                ->count(),
            'openFlags'    => TenantPricingAttentionFlag::query()
                ->where('tenant_id', $tenant->id)->where('status', 'open')->count(),
        ]);
    }

    public function saveKey(Request $request): RedirectResponse
    {
        $this->guard();
        $data = $request->validate([
            'api_key'        => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:64'],
        ]);

        $sub = $this->subscription();
        $creds = (array) ($sub->credentials_encrypted ?? []);
        if (filled($data['api_key'] ?? null)) {
            $creds['api_key'] = trim($data['api_key']);
            $creds['region'] = $creds['region'] ?? 'us';
        }
        $sub->credentials_encrypted = $creds;
        $sub->account_number = $data['account_number'] ?? $sub->account_number;
        $sub->save();

        return back()->with('success', 'HLC key saved. Test it to confirm access.');
    }

    public function testConnection(): RedirectResponse
    {
        $this->guard();
        $sub = $this->subscription();
        $creds = (array) ($sub->credentials_encrypted ?? []);
        if (blank($creds['api_key'] ?? null)) {
            return back()->with('error', 'Enter your HLC key first.');
        }

        try {
            $adapter = app(DistributorRegistry::class)->make(self::CODE, [
                'api_key' => $creds['api_key'],
                'region'  => $creds['region'] ?? 'us',
            ]);
            $res = $adapter->testConnection();
            $ok = (bool) ($res['ok'] ?? false);

            $sub->last_sync_status = $ok ? 'connected' : 'auth_failed';
            $sub->save();

            return back()->with($ok ? 'success' : 'error',
                $ok ? 'Connected to HLC.' : ('HLC rejected the key (HTTP ' . ($res['status'] ?? '?') . ').'));
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not reach HLC: ' . $e->getMessage());
        }
    }

    public function refreshSync(): RedirectResponse
    {
        $this->guard();
        $sub = $this->subscription();
        $creds = (array) ($sub->credentials_encrypted ?? []);
        if (blank($creds['api_key'] ?? null)) {
            return back()->with('error', 'Connect your HLC key before refreshing.');
        }

        TenantDistributorSyncJob::dispatch($sub->id);
        return back()->with('success', 'Refreshing your cost & availability in the background.');
    }

    public function import(): \Illuminate\Contracts\View\View
    {
        $this->guard();

        return view('tenant.distributors.import', array_merge(
            $this->importFilterOptions(),
            ['filters' => []],
        ));
    }

    public function importRun(\Illuminate\Http\Request $request): \Illuminate\Contracts\View\View
    {
        $this->guard();

        $data = $request->validate([
            'mode'               => ['required', 'in:preview,commit'],
            'brand'              => ['nullable', 'string', 'max:128'],
            'category'           => ['nullable', 'string', 'max:64'],
            'include_unsellable' => ['nullable'],
        ]);

        $filters = array_filter([
            'brand'              => $data['brand'] ?? null,
            'category'           => $data['category'] ?? null,
            'include_unsellable' => ! empty($data['include_unsellable']),
        ], fn ($v) => $v !== null && $v !== '' && $v !== false);

        $view = $this->importFilterOptions();
        $view['filters'] = $filters;
        $view['mode'] = $data['mode'];

        if (blank($filters['brand'] ?? null) && blank($filters['category'] ?? null)) {
            return view('tenant.distributors.import', $view)
                ->with('error', 'Choose at least a brand or a category.');
        }

        $view['result'] = app(\App\Services\Distributors\DistributorCatalogImportService::class)
            ->import(tenant()->id, self::CODE, $filters, $data['mode'] !== 'commit', 2000);

        return view('tenant.distributors.import', $view);
    }

    /** Brand / category options + catalog size for the import filter. */
    private function importFilterOptions(): array
    {
        $base = \App\Models\PlatformDistributorCatalog::query()
            ->where('distributor_code', self::CODE)->where('is_active', true);

        return [
            'brands' => (clone $base)->whereNotNull('manufacturer')
                ->distinct()->orderBy('manufacturer')->pluck('manufacturer'),
            'categories' => (clone $base)->whereNotNull('category')
                ->distinct()->orderBy('category')->pluck('category'),
            'catalogTotal' => (clone $base)->count(),
        ];
    }

    public function attention(\Illuminate\Http\Request $request): \Illuminate\Contracts\View\View
    {
        $this->guard();
        $tenant = tenant();
        $stock = in_array($request->query('stock'), ['in', 'out'], true) ? $request->query('stock') : 'all';

        $fBrand    = trim((string) $request->query('brand', '')) ?: null;
        $fCategory = trim((string) $request->query('category', '')) ?: null;
        $fReason   = trim((string) $request->query('reason', '')) ?: null;

        $q = \App\Models\Tenant\TenantPricingAttentionFlag::query()
            ->with('item.distributorCatalog')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'open');

        if ($stock === 'in') {
            $q->whereHas('item', fn ($i) => $i->where('computed_stock_count', '>', 0));
        } elseif ($stock === 'out') {
            $q->whereHas('item', fn ($i) => $i->where('computed_stock_count', '<=', 0));
        }
        if ($fBrand) {
            $q->whereHas('item.distributorCatalog', fn ($w) => $w->where('manufacturer', $fBrand));
        }
        if ($fCategory) {
            $q->whereHas('item.distributorCatalog', fn ($w) => $w->where('category', $fCategory));
        }
        if ($fReason) {
            $q->where('reason', $fReason);
        }

        $rank = ['title_changed' => 0, 'below_map' => 1, 'off_msrp' => 2, 'map_vanished' => 3, 'msrp_vanished' => 4, 'cost_vanished' => 5];
        $flags = $q->orderByDesc('created_at')->get()
            ->sortBy(fn ($f) => $rank[$f->reason] ?? 9)->values();

        // Chip counts reflect ALL open flags (not the current filter).
        $allOpen = \App\Models\Tenant\TenantPricingAttentionFlag::query()
            ->where('tenant_id', $tenant->id)->where('status', 'open')
            ->with('item:id,computed_stock_count')->get();
        $allBy = $allOpen->countBy('reason');
        $inCount = $allOpen->filter(fn ($f) => (int) ($f->item->computed_stock_count ?? 0) > 0)->count();
        $counts = [
            'total'     => $allOpen->count(),
            'in'        => $inCount,
            'out'       => $allOpen->count() - $inCount,
            'title'     => $allBy['title_changed'] ?? 0,
            'below_map' => $allBy['below_map'] ?? 0,
            'off_msrp'  => $allBy['off_msrp'] ?? 0,
            'vanished'  => ($allBy['cost_vanished'] ?? 0) + ($allBy['map_vanished'] ?? 0) + ($allBy['msrp_vanished'] ?? 0),
        ];

        // Brand / category options from the catalog rows behind the open flags.
        $catIds = \App\Models\Tenant\TenantPricingAttentionFlag::query()
            ->where('tenant_id', $tenant->id)->where('status', 'open')
            ->with('item:id,distributor_catalog_id')->get()
            ->pluck('item.distributor_catalog_id')->filter()->unique()->values();
        $brandOptions = \App\Models\PlatformDistributorCatalog::query()
            ->whereIn('id', $catIds)->whereNotNull('manufacturer')
            ->distinct()->orderBy('manufacturer')->pluck('manufacturer');
        $categoryOptions = \App\Models\PlatformDistributorCatalog::query()
            ->whereIn('id', $catIds)->whereNotNull('category')
            ->distinct()->orderBy('category')->pluck('category');

        $filters = ['brand' => $fBrand, 'category' => $fCategory, 'reason' => $fReason];

        return view('tenant.distributors.attention', compact(
            'flags', 'counts', 'stock', 'filters', 'brandOptions', 'categoryOptions'
        ));
    }

    public function attentionResolve(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->guard();
        $data = $request->validate([
            'action'     => ['required', 'in:raise_map,match_msrp,acknowledge,adopt_title,keep_title'],
            'flag_ids'   => ['nullable', 'array'],
            'flag_ids.*' => ['string'],
            'select_all' => ['nullable', 'boolean'],
            'f_brand'    => ['nullable', 'string', 'max:128'],
            'f_category' => ['nullable', 'string', 'max:64'],
            'f_reason'   => ['nullable', 'string', 'max:32'],
            'f_stock'    => ['nullable', 'string', 'in:all,in,out'],
        ]);

        $action = $data['action'];

        $q = \App\Models\Tenant\TenantPricingAttentionFlag::query()
            ->with('item.distributorCatalog')
            ->where('tenant_id', tenant()->id)
            ->where('status', 'open');

        if ($request->boolean('select_all')) {
            // "Apply to all matching the filter" — re-query server-side, ignore ids.
            if (filled($data['f_brand'] ?? null)) {
                $q->whereHas('item.distributorCatalog', fn ($w) => $w->where('manufacturer', $data['f_brand']));
            }
            if (filled($data['f_category'] ?? null)) {
                $q->whereHas('item.distributorCatalog', fn ($w) => $w->where('category', $data['f_category']));
            }
            if (filled($data['f_reason'] ?? null)) {
                $q->where('reason', $data['f_reason']);
            }
            if (($data['f_stock'] ?? 'all') === 'in') {
                $q->whereHas('item', fn ($w) => $w->where('computed_stock_count', '>', 0));
            } elseif (($data['f_stock'] ?? 'all') === 'out') {
                $q->whereHas('item', fn ($w) => $w->where('computed_stock_count', '<=', 0));
            }
        } else {
            $q->whereIn('id', $data['flag_ids'] ?? []);
        }

        $flags = $q->get();
        if ($flags->isEmpty()) {
            return back()->with('error', 'Nothing selected.');
        }

        $applied = 0;
        $skipped = 0;
        $userId = optional($request->user())->id;
        $titleReason = \App\Models\Tenant\TenantPricingAttentionFlag::REASON_TITLE_CHANGED;

        foreach ($flags as $flag) {
            $isTitle = $flag->reason === $titleReason;
            $item = $flag->item;

            // Type guards — an action only applies to the matching flag kind.
            if (in_array($action, ['adopt_title', 'keep_title'], true) && ! $isTitle) {
                $skipped++;
                continue;
            }
            if (in_array($action, ['raise_map', 'match_msrp'], true) && $isTitle) {
                $skipped++;
                continue;
            }

            if ($action === 'raise_map' || $action === 'match_msrp') {
                $target = $action === 'raise_map'
                    ? ($item?->catalog_map_cents ?? ($flag->detail['prev_map_cents'] ?? null))
                    : ($item?->catalog_msrp_cents ?? ($flag->detail['prev_msrp_cents'] ?? null));
                if (! $item || ! $target) {
                    $skipped++;
                    continue;
                }
                $item->shop_sell_price_cents = (int) $target;
                $item->save();
            } elseif ($action === 'adopt_title') {
                $cat = $item?->distributorCatalog;
                if (! $item || ! $cat || blank($cat->display_name)) {
                    $skipped++;
                    continue;
                }
                $item->name = $cat->display_name;
                $item->catalog_title_seen = $cat->display_name; // snapshot so it won't re-flag
                $item->save();
            } elseif ($action === 'keep_title') {
                // Keep the tenant's name; just acknowledge the catalog's new title
                // so the watch stops flagging it.
                $cat = $item?->distributorCatalog;
                if ($item && $cat) {
                    $item->catalog_title_seen = $cat->display_name;
                    $item->save();
                }
            }
            // 'acknowledge' falls through to resolve with no item change.

            $flag->status = 'resolved';
            $flag->resolved_at = now();
            $flag->resolved_by = $userId;
            $flag->save();
            $applied++;
        }

        $verb = [
            'raise_map'   => 'Raised to MAP',
            'match_msrp'  => 'Matched to MSRP',
            'acknowledge' => 'Dismissed',
            'adopt_title' => 'Adopted new title',
            'keep_title'  => 'Kept your title',
        ][$action];
        $msg = "{$verb}: {$applied} item(s)." . ($skipped ? " {$skipped} skipped." : '');

        return back()->with('success', $msg);
    }

    private function mask(?string $key): ?string
    {
        if (blank($key)) {
            return null;
        }
        return substr($key, 0, 8) . str_repeat('•', max(0, strlen($key) - 13)) . substr($key, -5);
    }
}
