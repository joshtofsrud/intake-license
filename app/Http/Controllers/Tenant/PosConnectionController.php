<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantPosConnection;
use App\Services\Pos\PosConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * MARKER-POS-CONNECT: the E-commerce page's Connected POS tab — connect, check, disconnect.
 */
class PosConnectionController extends Controller
{
    private function guard(): void
    {
        abort_unless(tenant()->online_store_enabled, 404);
        $me = Auth::guard('tenant')->user();
        abort_unless($me && $me->canAccessSection('storefront'), 403);
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('tenant.storefront.settings', ['tab' => 'pos']);
    }

    public function connect(Request $request, string $adapter, PosConnectionService $svc)
    {
        $this->guard();
        try {
            $url = $svc->begin(
                tenant(),
                Auth::guard('tenant')->id(),
                strtolower($adapter),
                $request->getSchemeAndHttpHost() . '/admin/storefront?tab=pos'
            );
        } catch (Throwable $e) {
            return $this->back()->with('error', $e->getMessage());
        }

        return redirect()->away($url);
    }

    public function check(PosConnectionService $svc): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->first();
        if (! $conn) {
            return $this->back()->with('error', 'There is no POS connected.');
        }
        $ok = $svc->discover($conn);

        return $this->back()->with($ok ? 'success' : 'error', $ok ? 'Connection checked — everything below is current.' : 'The check failed: ' . $conn->last_error);
    }

    /** MARKER-CONNECTED-POS-CATALOG: outlet → location map, removal rule, sync on/off. */
    public function settings(Request $request): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->firstOrFail();
        $data = $request->validate([
            'map'          => ['array'],
            'map.*'        => ['nullable', 'string', 'max:36'],
            'removal_mode' => ['required', 'in:keep,hide,deactivate'],
            'sync_enabled' => ['nullable', 'boolean'],
        ]);

        $outlets = collect($conn->locations ?? [])->pluck('id')->map(fn ($v) => (string) $v)->all();
        $mine = tenant()->locations()->pluck('id')->map(fn ($v) => (string) $v)->all();
        $map = [];
        foreach ((array) ($data['map'] ?? []) as $outlet => $loc) {
            if (in_array((string) $outlet, $outlets, true) && $loc && in_array((string) $loc, $mine, true)) {
                $map[(string) $outlet] = (string) $loc;
            }
        }
        if (count($map) !== count(array_unique($map))) {
            return $this->back()->with('error', 'Two POS outlets can\'t feed the same Intake location — each location keeps one count.');
        }
        $wantsSync = (bool) ($data['sync_enabled'] ?? false);
        if ($wantsSync && ! $map) {
            return $this->back()->with('error', 'Map at least one POS outlet to an Intake location before turning sync on.');
        }

        $conn->forceFill(['location_map' => $map, 'removal_mode' => $data['removal_mode'], 'sync_enabled' => $wantsSync])->save();

        return $this->back()->with('success', 'Connected POS settings saved.');
    }

    /** Start a full import now (also how a shop re-reads everything). */
    public function import(\App\Services\Pos\PosCatalogSync $sync): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->firstOrFail();
        if (! $conn->sync_enabled) {
            return $this->back()->with('error', 'Turn sync on and save before importing.');
        }
        if (($conn->sync_state['running'] ?? false) && $conn->updated_at?->gt(now()->subMinutes(15))) {
            return $this->back()->with('success', 'An import is already running — progress is shown below.');
        }
        $sync->start($conn, true);
        \App\Jobs\PosCatalogSyncJob::dispatch($conn->id);

        return $this->back()->with('success', 'Import started — progress is shown below.');
    }

    /** Live progress for the Connected POS tab. */
    public function status(): \Illuminate\Http\JsonResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->first();

        return response()->json([
            'state'          => $conn?->sync_state ?? null,
            'last_synced_at' => $conn?->last_synced_at?->diffForHumans(),
            'linked'         => $conn ? \Illuminate\Support\Facades\DB::table('tenant_pos_items')->where('tenant_id', $conn->tenant_id)->whereNotNull('inventory_item_id')->whereNull('removed_at')->count() : 0,
        ]);
    }

    public function disconnect(PosConnectionService $svc): RedirectResponse
    {
        $this->guard();
        $svc->disconnect(tenant());

        return $this->back()->with('success', 'POS disconnected. Intake no longer has access.');
    }
}
