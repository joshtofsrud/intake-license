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

    private function mask(?string $key): ?string
    {
        if (blank($key)) {
            return null;
        }
        return substr($key, 0, 8) . str_repeat('•', max(0, strlen($key) - 13)) . substr($key, -5);
    }
}
