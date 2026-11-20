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
            // MARKER-CONNECTED-POS-PUSH
            'sends'          => $conn ? \Illuminate\Support\Facades\DB::table('tenant_pos_pushes')->where('tenant_id', $conn->tenant_id)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status') : [],
        ]);
    }

    /** MARKER-CONNECTED-POS-PUSH: orders out + what sales are recorded with, items out rules. */
    public function pushSettings(Request $request): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->firstOrFail();
        $data = $request->validate([
            'orders_out'      => ['nullable', 'boolean'],
            'register_id'     => ['nullable', 'string', 'max:100'],
            'user_id'         => ['nullable', 'string', 'max:100'],
            'payment_type_id' => ['nullable', 'string', 'max:100'],
            'tax_id'          => ['nullable', 'string', 'max:100'],
            'items_create'    => ['required', 'in:orders,always'],
            'items_edits'     => ['nullable', 'boolean'],
        ]);
        $opts = (array) ($conn->setup_options ?? []);
        $valid = fn (string $list, ?string $id) => $id === null || $id === '' || collect($opts[$list] ?? ($list === 'payment_types' ? $conn->payment_types : []) ?? [])->pluck('id')->map(fn ($v) => (string) $v)->contains($id);
        if (! $valid('registers', $data['register_id'] ?? null) || ! $valid('users', $data['user_id'] ?? null)
            || ! $valid('payment_types', $data['payment_type_id'] ?? null) || ! $valid('taxes', $data['tax_id'] ?? null)) {
            return $this->back()->with('error', 'One of those choices is no longer in your Connected POS. Click Check connection to refresh the lists.');
        }
        $setup = array_filter([
            'register_id' => $data['register_id'] ?? null, 'user_id' => $data['user_id'] ?? null,
            'payment_type_id' => $data['payment_type_id'] ?? null, 'tax_id' => $data['tax_id'] ?? null,
        ]);
        $out = (bool) ($data['orders_out'] ?? false);
        if ($out) {
            $missing = [];
            foreach (['register_id' => 'register', 'user_id' => 'staff member', 'payment_type_id' => 'payment type'] as $k => $label) {
                if (empty($setup[$k])) { $missing[] = $label; }
            }
            if (! empty($opts['taxes']) && empty($setup['tax_id'])) { $missing[] = 'tax'; }
            if ($missing) {
                return $this->back()->with('error', 'To send online orders, choose the ' . implode(', ', $missing) . ' they are recorded with.');
            }
            if (empty($conn->location_map)) {
                return $this->back()->with('error', 'Map at least one outlet to an Intake location under Sync before sending orders.');
            }
        }
        $conn->forceFill([
            'orders_out' => $out, 'order_setup' => $setup,
            'items_create' => $data['items_create'], 'items_edits' => (bool) ($data['items_edits'] ?? false),
        ])->save();

        return $this->back()->with('success', 'Orders and items settings saved.');
    }

    /** Settle a Needs review field. */
    public function resolve(Request $request, \App\Services\Pos\PosPushService $push): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->where('status', 'connected')->firstOrFail();
        $data = $request->validate(['pos_item' => ['required', 'integer'], 'field' => ['required', 'in:name,sku,upc,description'], 'keep' => ['required', 'in:intake,pos']]);
        try {
            $push->resolve($conn, (int) $data['pos_item'], $data['field'], $data['keep']);
        } catch (Throwable $e) {
            return $this->back()->with('error', $e->getMessage());
        }

        return $this->back()->with('success', $data['keep'] === 'intake' ? 'Sent Intake\'s value to your Connected POS.' : 'Took your Connected POS\'s value into Intake.');
    }

    /** Try a failed send again. */
    public function retry(int $push): RedirectResponse
    {
        $this->guard();
        $row = \Illuminate\Support\Facades\DB::table('tenant_pos_pushes')->where('tenant_id', tenant()->id)->where('id', $push)->first();
        abort_unless($row, 404);
        \Illuminate\Support\Facades\DB::table('tenant_pos_pushes')->where('id', $row->id)->update(['status' => 'pending', 'last_error' => null, 'updated_at' => now()]);
        \App\Jobs\PosPushJob::dispatch((int) $row->id);

        return $this->back()->with('success', 'Sending again — the list below updates as it goes.');
    }

    /** Create every active Intake item that isn't in the Connected POS yet. */
    public function sendItems(\App\Services\Pos\PosPushService $push): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->where('status', 'connected')->firstOrFail();
        $linked = \Illuminate\Support\Facades\DB::table('tenant_pos_items')->where('tenant_id', $conn->tenant_id)->whereNotNull('inventory_item_id')->whereNull('removed_at')->select('inventory_item_id');
        $n = 0;
        \App\Models\Tenant\TenantInventoryItem::where('tenant_id', $conn->tenant_id)->where('is_active', true)->whereNotIn('id', $linked)
            ->select(['id', 'name'])->orderBy('name')->chunk(200, function ($items) use ($push, $conn, &$n) {
                foreach ($items as $i) {
                    $push->queue($conn->tenant_id, 'item_create', (string) $i->id, 'New item ' . $i->name);
                    $n++;
                }
            });

        return $this->back()->with('success', $n ? "Sending {$n} items — progress is shown under Recent sends." : 'Every active item is already in your Connected POS.');
    }

    public function disconnect(PosConnectionService $svc): RedirectResponse
    {
        $this->guard();
        $svc->disconnect(tenant());

        return $this->back()->with('success', 'POS disconnected. Intake no longer has access.');
    }
}
