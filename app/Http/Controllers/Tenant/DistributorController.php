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

    private function mask(?string $key): ?string
    {
        if (blank($key)) {
            return null;
        }
        return substr($key, 0, 8) . str_repeat('•', max(0, strlen($key) - 13)) . substr($key, -5);
    }
}
