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
            return $this->back()->with('error', 'There is no Connected POS.');
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
            return $this->back()->with('error', 'Two Connected POS outlets can\'t feed the same Intake location — each location keeps one count.');
        }
        $wantsSync = (bool) ($data['sync_enabled'] ?? false);
        if ($wantsSync && ! $map) {
            return $this->back()->with('error', 'Map at least one Connected POS outlet to an Intake location before turning sync on.');
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
            'registers'       => ['nullable', 'array'],
            'registers.*'     => ['nullable', 'string', 'max:100'],
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
        // MARKER-POS-REGISTER-PER-OUTLET: a register for each mapped outlet, when there is more than one
        $regIds = collect($opts['registers'] ?? [])->pluck('id')->map(fn ($v) => (string) $v);
        $perOutlet = [];
        foreach ((array) ($data['registers'] ?? []) as $outlet => $reg) {
            if (filled($reg) && isset(((array) $conn->location_map)[$outlet]) && $regIds->contains((string) $reg)) {
                $perOutlet[(string) $outlet] = (string) $reg;
            }
        }
        if ($perOutlet) {
            $setup['registers'] = $perOutlet;
        }
        // kept across saves: the item online shipping is sold as
        if (! empty(((array) $conn->order_setup)['charge_item_id'])) {
            $setup['charge_item_id'] = ((array) $conn->order_setup)['charge_item_id'];
        }
        $out = (bool) ($data['orders_out'] ?? false);
        $wasOut = (bool) $conn->orders_out;
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

        $catch = $out && ! $wasOut ? \App\Services\Pos\PosPushService::catchupOrders($conn->tenant_id)->count() : 0;
        return $this->back()->with('success', 'Orders and items settings saved.' . ($catch ? ' ' . $catch . ' paid ' . ($catch === 1 ? 'order' : 'orders') . ' from the last 7 days went out before this was on — send them from Sending below.' : ''));
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

    /** MARKER-POS-CATCHUP: send the paid orders from the last 7 days that went out before orders were switched on. */
    public function sendRecentOrders(\App\Services\Pos\PosPushService $push): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->where('status', 'connected')->firstOrFail();
        if (! $conn->orders_out) {
            return $this->back()->with('error', 'Turn on Send paid online orders and save first.');
        }
        $n = 0;
        foreach (\App\Services\Pos\PosPushService::catchupOrders($conn->tenant_id)->orderBy('created_at')->get(['id', 'order_number']) as $o) {
            $push->queue($conn->tenant_id, 'sale', (string) $o->id, 'Online order ' . $o->order_number, false);
            $n++;
        }
        if ($n) {
            \App\Services\Pos\PosPushService::kick($conn->tenant_id);
        }
        return $this->back()->with('success', $n ? 'Sending ' . $n . ' paid ' . ($n === 1 ? 'order' : 'orders') . ' to your Connected POS. Watch them under Sending.' : 'Every paid order from the last 7 days is already sent.');
    }

    /**
     * MARKER-POS-ITEM-DETAILS: queue brand, category, supplier, cost and weight for every
     * linked item that doesn't have them yet (or whose last try failed). Uses the same paced
     * sending as everything else, with Stop.
     */
    public function fillDetails(\App\Services\Pos\PosPushService $push): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->where('status', 'connected')->firstOrFail();
        $n = 0;
        \Illuminate\Support\Facades\DB::table('tenant_pos_items')
            ->join('tenant_inventory_items as ii', 'ii.id', '=', 'tenant_pos_items.inventory_item_id')
            ->where('tenant_pos_items.tenant_id', $conn->tenant_id)->whereNull('tenant_pos_items.removed_at')->whereNull('tenant_pos_items.details_sent_at')
            ->select(['tenant_pos_items.id', 'ii.id as item_id', 'ii.name'])
            ->chunkById(500, function ($rows) use ($push, $conn, &$n) {
                foreach ($rows as $r) {
                    $push->queue($conn->tenant_id, 'item_details', (string) $r->item_id, 'Details for ' . $r->name, false);
                    $n++;
                }
            }, 'tenant_pos_items.id', 'id');
        if ($n) {
            \App\Services\Pos\PosPushService::kick($conn->tenant_id);
        }
        return $this->back()->with('success', $n ? 'Filling in details for ' . number_format($n) . ' items. Watch them under Sending — Stop works here too.' : 'Every item in your Connected POS already has its details.');
    }

    /** MARKER-POS-STOP-SENDS: stop everything still waiting to send. Nothing already sent is touched. */
    public function stopSends(): RedirectResponse
    {
        $this->guard();
        $tid = tenant()->id;
        $n = \Illuminate\Support\Facades\DB::table('tenant_pos_pushes')->where('tenant_id', $tid)->where('status', 'pending')
            ->update(['status' => 'skipped', 'last_error' => 'Stopped by you before it was sent.', 'updated_at' => now()]);
        return $this->back()->with('success', $n ? 'Stopped ' . number_format($n) . ' sends. Resume picks them up from where they stopped.' : 'Nothing was waiting to send.');
    }

    /** MARKER-POS-STOP-RESUME: stop an import where it is; it keeps its place. */
    public function importStop(): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->firstOrFail();
        $st = (array) ($conn->sync_state ?? []);
        $st['running'] = false;
        $st['stopped'] = true;
        $st['finished_at'] = now()->toIso8601String();
        $conn->forceFill(['sync_state' => $st])->save();

        return $this->back()->with('success', 'Import stopped after page ' . number_format($st['pages'] ?? 0) . '. Automatic sync is paused too. Resume carries on from there.');
    }

    /** Carry on a stopped import from its saved place (and switch automatic sync back on). */
    public function importResume(): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->firstOrFail();
        if (! $conn->sync_enabled) {
            return $this->back()->with('error', 'Turn on Bring in products and save first.');
        }
        $st = (array) ($conn->sync_state ?? []);
        $st['running'] = true;
        $st['stopped'] = false;
        $st['error'] = null;
        $st['finished_at'] = null;
        $st['phase'] = ($st['phase'] ?? 'done') === 'done' ? 'items' : $st['phase'];
        $conn->forceFill(['sync_state' => $st])->save();
        \App\Jobs\PosCatalogSyncJob::dispatch($conn->id);

        return $this->back()->with('success', 'Import resumed from page ' . number_format($st['pages'] ?? 0) . '.');
    }

    /** Put stopped sends back in line — only those; nothing already sent goes again. */
    public function sendsResume(): RedirectResponse
    {
        $this->guard();
        $tid = tenant()->id;
        $n = \Illuminate\Support\Facades\DB::table('tenant_pos_pushes')->where('tenant_id', $tid)->where('status', 'skipped')
            ->update(['status' => 'pending', 'attempts' => 0, 'last_error' => null, 'updated_at' => now()]);
        if ($n) {
            \App\Services\Pos\PosPushService::kick($tid);
        }

        return $this->back()->with('success', $n ? 'Resumed ' . number_format($n) . ' sends from where they stopped.' : 'Nothing was stopped.');
    }

    /** Try a failed send again. */
    public function retry(int $push): RedirectResponse
    {
        $this->guard();
        $row = \Illuminate\Support\Facades\DB::table('tenant_pos_pushes')->where('tenant_id', tenant()->id)->where('id', $push)->first();
        abort_unless($row, 404);
        \Illuminate\Support\Facades\DB::table('tenant_pos_pushes')->where('id', $row->id)->update(['status' => 'pending', 'attempts' => 0, 'last_error' => null, 'updated_at' => now()]);
        \App\Services\Pos\PosPushService::kick($row->tenant_id); // MARKER-POS-DRAIN

        return $this->back()->with('success', 'Sending again — the list below updates as it goes.');
    }

    /** Remove stopped and skipped sends from the list (nothing in the Connected POS changes). */
    public function clearStopped(): RedirectResponse
    {
        $this->guard();
        $n = \Illuminate\Support\Facades\DB::table('tenant_pos_pushes')->where('tenant_id', tenant()->id)->where('status', 'skipped')->delete();

        return $this->back()->with('success', $n ? 'Cleared ' . number_format($n) . ' stopped sends from the list.' : 'Nothing to clear.');
    }

    /** Remove failed sends from the list after you've looked at them (nothing in the Connected POS changes). */
    public function clearFailed(): RedirectResponse
    {
        $this->guard();
        $n = \Illuminate\Support\Facades\DB::table('tenant_pos_pushes')->where('tenant_id', tenant()->id)->where('status', 'failed')->whereIn('kind', ['item_create', 'item_update', 'item_details'])->delete();

        return $this->back()->with('success', $n ? 'Cleared ' . number_format($n) . ' failed item sends. Orders and refunds that failed stay until they are sent.' : 'Nothing to clear.');
    }

    public function disconnect(PosConnectionService $svc): RedirectResponse
    {
        $this->guard();
        $svc->disconnect(tenant());

        return $this->back()->with('success', 'Connected POS disconnected. Intake no longer has access.');
    }
}
