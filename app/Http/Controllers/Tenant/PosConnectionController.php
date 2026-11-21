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
 * MARKER-CONNECTED-POS-SECTION: the Connected POS section — the shop's own outside POS.
 * Connect, check and disconnect; what is read from it and what is sent to it; its two
 * modes (2 Way Data, Reading Only) and switching over to Intake.
 *
 * Its items come into Intake through Inventory › Import (PosImportController). Sending
 * online orders is an E-commerce setting (PosOrdersController).
 */
class PosConnectionController extends Controller
{
    private function guard(): void
    {
        abort_unless(tenant()->connected_pos_enabled, 404);
        $me = Auth::guard('tenant')->user();
        abort_unless($me && $me->canAccessSection('connected_pos'), 403);
    }

    private function back(string $tab = 'overview'): RedirectResponse
    {
        // an action taken from the Move to Intake checklist returns to the checklist
        if (str_contains((string) url()->previous(), '/connected-pos/move')) {
            return redirect()->route('tenant.cpos.move');
        }

        return redirect()->route('tenant.cpos.home', ['tab' => $tab]);
    }

    private const TABS = ['overview', 'items', 'customers', 'history', 'sending', 'settings'];

    /** GET /connected-pos — the section. ?part=sending returns just the sends list, for its live refresh. */
    public function home(Request $request)
    {
        $this->guard();
        $tenant = tenant();
        $conn = \App\Support\ConnectedPosView::connection($tenant->id);
        $pos = \App\Support\ConnectedPosView::data($tenant, $conn);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overview';

        if ($request->query('part') === 'sending') {
            return $conn ? view('tenant.connected-pos._sending', ['pos' => $pos]) : response('', 204);
        }

        $new = collect();
        if ($conn && $tab === 'items') {
            $svc = app(\App\Services\Pos\PosImportService::class);
            $rows = $svc->newQuery($conn)->orderByDesc('p.first_seen_at')->limit(10)->get();
            $stock = \Illuminate\Support\Facades\DB::table('tenant_pos_stock')->where('tenant_id', $tenant->id)
                ->whereIn('external_item_id', $rows->pluck('external_id'))->selectRaw('external_item_id, SUM(qty) q')->groupBy('external_item_id')->pluck('q', 'external_item_id');
            $matcher = $rows->isEmpty() ? null : \App\Services\Pos\PosMatcher::forTenant($tenant->id);
            $twins = $rows->isEmpty() ? [] : $svc->twinMap($conn)[0];
            $new = $rows->map(function ($p) use ($stock, $matcher, $twins) {
                $p->twin = ! empty($twins[$p->id]); // MARKER-POS-TWINS: in the Connected POS more than once
                $hit = $p->twin ? null : $matcher->find((array) $p);
                $p->hit = $hit ? ['how' => $hit['how'], 'name' => $matcher->name($hit['id']), 'id' => $hit['id']] : null;
                $p->stock = $p->track_stock ? (int) ($stock[$p->external_id] ?? 0) : null;
                return $p;
            });
        }

        return view('tenant.connected-pos.index', [
            'pos'   => $pos,
            'tab'   => $tab,
            'new'   => $new,
            'move'  => \App\Support\ConnectedPosView::moveSteps($conn, $pos['items']),
        ]);
    }

    /** GET /connected-pos/move — the checklist for a shop moving to Intake. */
    public function move()
    {
        $this->guard();
        $tenant = tenant();
        $conn = \App\Support\ConnectedPosView::connection($tenant->id);
        $pos = \App\Support\ConnectedPosView::data($tenant, $conn);

        return view('tenant.connected-pos.move', [
            'pos'  => $pos,
            'move' => \App\Support\ConnectedPosView::moveSteps($conn, $pos['items']),
        ]);
    }

    /** How the shop uses its Connected POS: staying on it, or moving to Intake. */
    public function setUse(Request $request): RedirectResponse
    {
        $this->guard();
        $data = $request->validate(['use' => ['required', 'in:stay,move']]);
        $tenant = tenant();
        $settings = (array) ($tenant->settings ?? []);
        $settings['connected_pos'] = array_merge((array) ($settings['connected_pos'] ?? []), ['use' => $data['use']]);
        $tenant->forceFill(['settings' => $settings])->save();

        return redirect()->route('tenant.cpos.home', ['tab' => 'overview'])->with('success', $data['use'] === 'move'
            ? 'Set to Moving to Intake. The steps are below, and Move to Intake is at the top of the menu.'
            : 'Set to Staying on your Connected POS.');
    }

    public function connect(Request $request, string $adapter, PosConnectionService $svc)
    {
        $this->guard();
        try {
            $url = $svc->begin(
                tenant(),
                Auth::guard('tenant')->id(),
                strtolower($adapter),
                route('tenant.cpos.home', ['tab' => 'overview'])
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

    /** Outlets → locations, keeping linked items in sync, linking matches, and the removed-item rule. */
    public function settings(Request $request): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->firstOrFail();
        $data = $request->validate([
            'map'          => ['array'],
            'map.*'        => ['nullable', 'string', 'max:36'],
            'removal_mode' => ['required', 'in:keep,hide,deactivate'],
            'sync_enabled' => ['nullable', 'boolean'],
            'auto_link'    => ['nullable', 'boolean'],
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
            return $this->back('settings')->with('error', 'Two Connected POS outlets can\'t feed the same Intake location — each location keeps one count.');
        }
        $wantsSync = (bool) ($data['sync_enabled'] ?? false);
        if ($wantsSync && $conn->isTwoWay() && ! $map) {
            return $this->back('settings')->with('error', 'Map at least one Connected POS outlet to an Intake location before turning this on.');
        }

        $was = (bool) $conn->sync_enabled;
        $conn->forceFill([
            'location_map' => $map, 'removal_mode' => $data['removal_mode'],
            'sync_enabled' => $wantsSync, 'auto_link' => (bool) ($data['auto_link'] ?? false),
        ])->save();

        return $this->back('settings')->with('success', 'Connected POS settings saved.' . ($wantsSync && ! $was ? ' Reading starts within a minute.' : ''));
    }

    /**
     * Read the whole catalog now. Reading records every product and links the ones that
     * are plainly items the shop already carries. It never creates an item.
     */
    public function read(\App\Services\Pos\PosCatalogSync $sync): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->where('status', 'connected')->first();
        if (! $conn) {
            return $this->back('items')->with('error', 'Reconnect your Connected POS first.');
        }
        if (($conn->sync_state['running'] ?? false) && $conn->updated_at?->gt(now()->subMinutes(15))) {
            return $this->back('items')->with('success', 'The catalog is already being read — progress is shown below.');
        }
        $sync->start($conn, true);
        \App\Jobs\PosCatalogSyncJob::dispatch($conn->id);

        return $this->back('items')->with('success', 'Reading your catalog — progress is shown below.');
    }

    /** Live progress for the Connected POS tab. */
    public function status(): \Illuminate\Http\JsonResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->first();

        return response()->json([
            'state'          => $conn?->sync_state ?? null,
            'customers'      => $conn?->customer_sync ?? null, // MARKER-POS-CUSTOMERS-IN
            'import'         => $conn?->import_state ?? null,
            'sales'          => $conn && $conn->sales_state ? $this->salesLive($conn) : null, // MARKER-POS-SALES-HISTORY
            'items'          => $conn ? app(\App\Services\Pos\PosImportService::class)->counts($conn) : null,
            'mode'           => $conn?->mode,
            'last_synced_at' => $conn?->last_synced_at?->diffForHumans(),
            'linked'         => $conn ? \Illuminate\Support\Facades\DB::table('tenant_pos_items')->where('tenant_id', $conn->tenant_id)->whereNotNull('inventory_item_id')->whereNull('removed_at')->count() : 0,
            // MARKER-CONNECTED-POS-PUSH
            'sends'          => $conn ? \Illuminate\Support\Facades\DB::table('tenant_pos_pushes')->where('tenant_id', $conn->tenant_id)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status') : [],
        ]);
    }

    /** What Intake sends about items: creating them there, and the shop's edits. (Orders: E-commerce.) */
    public function itemSettings(Request $request): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->firstOrFail();
        $data = $request->validate([
            'items_create' => ['required', 'in:orders,always'],
            'items_edits'  => ['nullable', 'boolean'],
        ]);
        $conn->forceFill(['items_create' => $data['items_create'], 'items_edits' => (bool) ($data['items_edits'] ?? false)])->save();

        return $this->back('settings')->with('success', 'Saved.');
    }

    /** Products first seen since the last import are no longer flagged as new. */
    public function markSeen(): RedirectResponse
    {
        $this->guard();
        TenantPosConnection::where('tenant_id', tenant()->id)->update(['items_marked_at' => now()]);

        return $this->back('items')->with('success', 'Marked as seen. They are still waiting in Inventory › Import.');
    }

    /**
     * Switch over to Intake. Reads the Connected POS one last time (final prices and counts
     * onto linked items), then the connection becomes Reading Only: nothing more is sent
     * to it, and Intake owns price and stock from then on.
     */
    public function switchOver(\App\Services\Pos\PosCatalogSync $sync): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->where('status', 'connected')->first();
        if (! $conn) {
            return $this->back()->with('error', 'Reconnect your Connected POS first, so its last prices and counts can be read.');
        }
        if (! $conn->isTwoWay()) {
            return $this->back()->with('success', 'Already switched over. Your Connected POS is Reading Only.');
        }
        $waiting = \Illuminate\Support\Facades\DB::table('tenant_pos_pushes')->where('tenant_id', $conn->tenant_id)->where('status', 'pending')->count();
        if ($waiting) {
            return redirect()->route('tenant.cpos.home', ['tab' => 'sending'])->with('error', number_format($waiting) . ' ' . ($waiting === 1 ? 'send is' : 'sends are')
                . ' still waiting to go to your Connected POS. Let them finish or stop them here, then switch over.');
        }
        if ((($conn->import_state ?? [])['running'] ?? false)) {
            return $this->back()->with('error', 'An import from your Connected POS is still running. Let it finish or stop it, then switch over.');
        }
        if (! $conn->sync_enabled || empty($conn->location_map)) {
            // nothing to level: no outlet is mapped, or sync was never on
            $conn->forceFill(['mode' => TenantPosConnection::READ_ONLY, 'switched_at' => now()])->save();
            app(\App\Services\Pos\PosSalesSync::class)->lastLook($conn); // MARKER-POS-SALES-HISTORY

            return $this->back()->with('success', 'Switched over. Your Connected POS is now Reading Only, and Intake owns price and stock.');
        }
        $sync->start($conn, true, true);
        \App\Jobs\PosCatalogSyncJob::dispatch($conn->id);

        return redirect()->route('tenant.cpos.home', ['tab' => 'items'])->with('success', 'Switching over: reading your Connected POS one last time. It becomes Reading Only as soon as that finishes — progress is below.');
    }

    /** Back to 2 Way Data: the Connected POS sets price and stock on linked items again. */
    public function switchBack(): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->firstOrFail();
        if ($conn->isTwoWay()) {
            return $this->back('settings')->with('success', 'Your Connected POS is already 2 Way Data.');
        }
        $conn->forceFill(['mode' => TenantPosConnection::TWO_WAY, 'switched_at' => null])->save();

        return $this->back('settings')->with('success', 'Back to 2 Way Data. Linked items take your Connected POS\'s prices and counts again from the next read.');
    }

    /** Settle a Needs review field. */
    public function resolve(Request $request, \App\Services\Pos\PosPushService $push): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->where('status', 'connected')->firstOrFail();
        $data = $request->validate(['pos_item' => ['required', 'integer'], 'field' => ['required', 'in:name,sku,upc,description'], 'keep' => ['required', 'in:intake,pos']]);
        if ($data['keep'] === 'intake' && ! $conn->isTwoWay()) {
            return $this->back('sending')->with('error', 'Your Connected POS is Reading Only, so Intake\'s value can\'t be sent to it. Choose the Connected POS\'s value, or leave this as it is.');
        }
        try {
            $push->resolve($conn, (int) $data['pos_item'], $data['field'], $data['keep']);
        } catch (Throwable $e) {
            return $this->back('sending')->with('error', $e->getMessage());
        }

        return $this->back('sending')->with('success', $data['keep'] === 'intake' ? 'Sent Intake\'s value to your Connected POS.' : 'Took your Connected POS\'s value into Intake.');
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
        if (! $conn->isTwoWay()) {
            return $this->back('sending')->with('error', 'Your Connected POS is Reading Only, so nothing is sent to it.');
        }
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
        return $this->back('sending')->with('success', $n ? 'Filling in details for ' . number_format($n) . ' items. Watch them under Sending — Stop works here too.' : 'Every item in your Connected POS already has its details.');
    }

    /** MARKER-POS-CUSTOMERS-IN: turn bringing in customers on or off. Turning it on starts the first import. */
    public function customersSettings(Request $request, \App\Services\Pos\PosCustomerSync $sync): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->where('status', 'connected')->firstOrFail();
        $on = $request->boolean('customers_in');
        $was = (bool) $conn->customers_in;
        $conn->forceFill(['customers_in' => $on])->save();
        if ($on && ! $was && empty(($conn->customer_sync ?? [])['last_done'])) {
            $sync->start($conn, true);
            \App\Jobs\PosCustomerSyncJob::dispatch($conn->id);
            return $this->back('customers')->with('success', 'Bringing in customers — progress is shown below.');
        }
        return $this->back('customers')->with('success', $on ? 'Customers keep coming in about every minute.' : 'Customers no longer come in. Those already brought in stay.');
    }

    /** Re-read every customer (fills blanks on matches, adds anyone new). */
    public function customersImport(\App\Services\Pos\PosCustomerSync $sync): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->where('status', 'connected')->firstOrFail();
        if (! $conn->customers_in) {
            return $this->back('customers')->with('error', 'Turn on Bring in customers first.');
        }
        if (($conn->customer_sync['running'] ?? false) && $conn->updated_at?->gt(now()->subMinutes(15))) {
            return $this->back('customers')->with('success', 'Customers are already coming in — progress is shown below.');
        }
        $sync->start($conn, true);
        \App\Jobs\PosCustomerSyncJob::dispatch($conn->id);
        return $this->back('customers')->with('success', 'Reading every customer again — progress is shown below.');
    }

    public function customersStop(): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->firstOrFail();
        $st = (array) ($conn->customer_sync ?? []);
        $st['running'] = false; $st['stopped'] = true; $st['finished_at'] = now()->toIso8601String();
        $conn->forceFill(['customer_sync' => $st])->save();
        return $this->back('customers')->with('success', 'Stopped after page ' . number_format($st['pages'] ?? 0) . '. Resume carries on from there.');
    }

    public function customersResume(): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->firstOrFail();
        if (! $conn->customers_in) {
            return $this->back('customers')->with('error', 'Turn on Bring in customers first.');
        }
        $st = (array) ($conn->customer_sync ?? []);
        $st['running'] = true; $st['stopped'] = false; $st['error'] = null; $st['finished_at'] = null;
        $conn->forceFill(['customer_sync' => $st])->save();
        \App\Jobs\PosCustomerSyncJob::dispatch($conn->id);
        return $this->back('customers')->with('success', 'Resumed from page ' . number_format($st['pages'] ?? 0) . '.');
    }

    // ── MARKER-POS-SALES-HISTORY ────────────────────────────────

    /** The run's numbers for the live poll, with how far it has got worked out. */
    private function salesLive(TenantPosConnection $conn): array
    {
        $st = (array) $conn->sales_state;
        $skipped = (int) ($st['ours'] ?? 0) + (int) ($st['older'] ?? 0) + (int) ($st['no_customer'] ?? 0) + (int) ($st['voided'] ?? 0) + (int) ($st['open'] ?? 0);

        return $st + [
            'percent'  => \App\Services\Pos\PosSalesSync::percent($st),
            'reached'  => \App\Services\Pos\PosSalesSync::reached($st, tenant()->timezone()) ?? 'starting',
            'left_out' => $skipped,
            'visible'  => (bool) (($st['running'] ?? false) && ($st['full'] ?? false)),
        ];
    }

    /**
     * Save the Sales history choices, and (go=start) read the history. The first read and
     * "Read again" both start from the beginning: a sale already in is updated, not doubled.
     */
    public function sales(Request $request, \App\Services\Pos\PosSalesSync $sync): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->where('status', 'connected')->first();
        if (! $conn) {
            return $this->back('history')->with('error', 'Reconnect your Connected POS first.');
        }
        $data = $request->validate([
            'range'       => ['nullable', 'in:12m,24m,all'],
            'no_customer' => ['nullable', 'in:in,skip'],
            'sales_in'    => ['nullable', 'boolean'],
            'go'          => ['nullable', 'in:save,start'],
        ]);
        $st = (array) ($conn->sales_state ?? []);
        $running = ($st['running'] ?? false) && ($st['full'] ?? false) && $conn->updated_at?->gt(now()->subMinutes(15));
        if ($running) {
            return $this->back('history')->with('success', 'Sales history is already coming in — progress is shown below. Stop it to change these.');
        }
        $was = \App\Services\Pos\PosSalesSync::options($conn);
        $opt = ['range' => $data['range'] ?? $was['range'], 'no_customer' => $data['no_customer'] ?? $was['no_customer']];
        // from the checklist's Start there is no switch on the form: new sales keep coming unless the shop said otherwise
        $keep = $request->has('sales_in') ? $request->boolean('sales_in') : ($conn->sales_options === null ? true : (bool) $conn->sales_in);
        $conn->forceFill(['sales_options' => $opt, 'sales_in' => $keep])->save();

        if (($data['go'] ?? 'save') === 'start') {
            if (($st['running'] ?? false) && $conn->updated_at?->gt(now()->subMinutes(2))) {
                // the every-minute check for new sales is mid-run; starting over underneath it would lose one of the two
                return $this->back('history')->with('error', 'Intake is checking for new sales right now. Give it a few seconds, then press it again. Your choices were saved.');
            }
            $sync->start($conn, true);
            \App\Jobs\PosSalesSyncJob::dispatch($conn->id);

            return $this->back('history')->with('success', 'Reading your sales history — progress is shown below.');
        }
        $changed = $opt != $was && ! empty($st['last_done']);

        return $this->back('history')->with('success', $changed
            ? 'Saved. Read again to apply it to the sales already brought in.'
            : 'Saved.' . ($conn->isTwoWay() ? ($keep ? ' New sales keep coming in until you switch over.' : ' New sales no longer come in.') : ''));
    }

    public function salesStop(): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->firstOrFail();
        $st = (array) ($conn->sales_state ?? []);
        if (! $st) {
            return $this->back('history')->with('error', 'Nothing is being read.');
        }
        $st['running'] = false; $st['stopped'] = true; $st['finished_at'] = now()->toIso8601String();
        $conn->forceFill(['sales_state' => $st])->save();
        $at = \App\Services\Pos\PosSalesSync::reached($st, tenant()->timezone());

        return $this->back('history')->with('success', 'Stopped. Everything read so far is kept; Resume carries on from ' . ($at ?: 'where it stopped') . '.');
    }

    public function salesResume(): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->where('status', 'connected')->first();
        $st = (array) ($conn?->sales_state ?? []);
        if (! $conn || ! $st) {
            return $this->back('history')->with('error', $conn ? 'There is nothing to resume.' : 'Reconnect your Connected POS first.');
        }
        $st['running'] = true; $st['stopped'] = false; $st['error'] = null; $st['finished_at'] = null;
        $conn->forceFill(['sales_state' => $st])->save();
        \App\Jobs\PosSalesSyncJob::dispatch($conn->id);
        $at = \App\Services\Pos\PosSalesSync::reached($st, tenant()->timezone());

        return $this->back('history')->with('success', 'Resumed' . ($at ? ' from ' . $at : '') . '.');
    }

    /** Take every Connected POS sale back out of Intake. Nothing else is touched. */
    public function salesClear(\App\Services\Pos\PosSalesSync $sync): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->firstOrFail();
        if ((($conn->sales_state ?? [])['running'] ?? false) && $conn->updated_at?->gt(now()->subMinutes(15))) {
            return $this->back('history')->with('error', 'Sales are being read right now. Stop that first, then remove them.');
        }
        $n = $sync->clear($conn);

        return $this->back('history')->with('success', $n ? number_format($n) . ' Connected POS ' . ($n === 1 ? 'sale' : 'sales') . ' removed from Intake. Your Connected POS, your stock and your own sales were not touched.' : 'There was no sales history to remove.');
    }

    /** MARKER-POS-STOP-SENDS: stop everything still waiting to send. Nothing already sent is touched. */
    public function stopSends(): RedirectResponse
    {
        $this->guard();
        $tid = tenant()->id;
        $n = \Illuminate\Support\Facades\DB::table('tenant_pos_pushes')->where('tenant_id', $tid)->where('status', 'pending')
            ->update(['status' => 'skipped', 'last_error' => 'Stopped by you before it was sent.', 'updated_at' => now()]);
        return $this->back('sending')->with('success', $n ? 'Stopped ' . number_format($n) . ' sends. Resume picks them up from where they stopped.' : 'Nothing was waiting to send.');
    }

    /** MARKER-POS-STOP-RESUME: stop an import where it is; it keeps its place. */
    public function readStop(): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->firstOrFail();
        $st = (array) ($conn->sync_state ?? []);
        $st['running'] = false;
        $st['stopped'] = true;
        $st['switch_after'] = false; // a stopped last read doesn't switch over
        $st['finished_at'] = now()->toIso8601String();
        $conn->forceFill(['sync_state' => $st])->save();

        return $this->back('items')->with('success', 'Stopped after page ' . number_format($st['pages'] ?? 0) . '. Automatic reading is paused too. Resume carries on from there.');
    }

    /** Carry on a stopped import from its saved place (and switch automatic sync back on). */
    public function readResume(): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->firstOrFail();
        $st = (array) ($conn->sync_state ?? []);
        $st['running'] = true;
        $st['stopped'] = false;
        $st['error'] = null;
        $st['finished_at'] = null;
        $st['phase'] = ($st['phase'] ?? 'done') === 'done' ? 'items' : $st['phase'];
        $conn->forceFill(['sync_state' => $st])->save();
        \App\Jobs\PosCatalogSyncJob::dispatch($conn->id);

        return $this->back('items')->with('success', 'Resumed from page ' . number_format($st['pages'] ?? 0) . '.');
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

        return $this->back('sending')->with('success', $n ? 'Resumed ' . number_format($n) . ' sends from where they stopped.' : 'Nothing was stopped.');
    }

    /** Try a failed send again. */
    public function retry(int $push): RedirectResponse
    {
        $this->guard();
        $row = \Illuminate\Support\Facades\DB::table('tenant_pos_pushes')->where('tenant_id', tenant()->id)->where('id', $push)->first();
        abort_unless($row, 404);
        \Illuminate\Support\Facades\DB::table('tenant_pos_pushes')->where('id', $row->id)->update(['status' => 'pending', 'attempts' => 0, 'last_error' => null, 'updated_at' => now()]);
        \App\Services\Pos\PosPushService::kick($row->tenant_id); // MARKER-POS-DRAIN

        return $this->back('sending')->with('success', 'Sending again — the list below updates as it goes.');
    }

    /** Remove stopped and skipped sends from the list (nothing in the Connected POS changes). */
    public function clearStopped(): RedirectResponse
    {
        $this->guard();
        $n = \Illuminate\Support\Facades\DB::table('tenant_pos_pushes')->where('tenant_id', tenant()->id)->where('status', 'skipped')->delete();

        return $this->back('sending')->with('success', $n ? 'Cleared ' . number_format($n) . ' stopped sends from the list.' : 'Nothing to clear.');
    }

    /** Remove failed sends from the list after you've looked at them (nothing in the Connected POS changes). */
    public function clearFailed(): RedirectResponse
    {
        $this->guard();
        $n = \Illuminate\Support\Facades\DB::table('tenant_pos_pushes')->where('tenant_id', tenant()->id)->where('status', 'failed')->whereIn('kind', ['item_create', 'item_update', 'item_details'])->delete();

        return $this->back('sending')->with('success', $n ? 'Cleared ' . number_format($n) . ' failed item sends. Orders and refunds that failed stay until they are sent.' : 'Nothing to clear.');
    }

    public function disconnect(PosConnectionService $svc): RedirectResponse
    {
        $this->guard();
        $svc->disconnect(tenant());

        return $this->back()->with('success', 'Connected POS disconnected. Intake no longer has access.');
    }
}
