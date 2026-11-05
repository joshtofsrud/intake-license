<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantLocation;
use App\Models\Tenant\TenantUser;
use App\Models\Tenant\TenantVendor;
use App\Services\Tenant\BuyingService;
use App\Services\Tenant\PurchaseOrderException;
use App\Services\Tenant\StaffAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MARKER-BUYING: the Buying page. The page itself is reachable by anyone in a
 * retail shop so it can say why it's locked (no add-on, or a role without it);
 * every action under tenant.buying.* is enforced by section access as usual,
 * and each also checks the add-on.
 */
class BuyingController extends Controller
{
    public function __construct(private BuyingService $buying) {}

    private function guard(): void
    {
        $t = tenant();
        abort_unless($t && $t->retail_enabled, 404);
        abort_unless($t->buying_enabled, 403, 'Buying isn\'t on for this shop.');
    }

    private function scope(Request $request): array
    {
        $locs = BuyingService::locations(tenant());
        $loc = (string) $request->query('loc', '');
        if ($locs->count() === 1) {
            $loc = (string) $locs->first()->id;
        }
        if ($loc !== '' && ! $locs->contains('id', $loc)) {
            $loc = '';
        }
        $w = (int) $request->query('w', 13);
        if (! isset(BuyingService::WINDOWS[$w])) {
            $w = 13;
        }

        return [$locs, $loc ?: null, $w];
    }

    public function index(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant && $tenant->retail_enabled, 404);
        $user = auth('tenant')->user();

        if (! $tenant->buying_enabled) {
            $addon = DB::table('addons')->where('code', 'buying')->first();

            return view('tenant.buying.index', ['lock' => 'addon', 'addon' => $addon, 'isOwner' => $user?->isOwner()]);
        }
        if ($user && ! $user->canAccessSection('buying')) {
            return view('tenant.buying.index', ['lock' => 'role']);
        }

        [$locs, $loc, $w] = $this->scope($request);
        $set = BuyingService::settings($tenant);
        $canCost = $user ? $user->canAccessSection('cost_margins') : false;
        $rows = $this->buying->moving($tenant, $loc, $w);
        if (! $canCost) {
            foreach ($rows as &$r) {
                $r['cost'] = null; $r['price'] = null;
                foreach ($r['vendors'] as &$v) { $v['cost'] = null; } unset($v);
                foreach ($r['lines'] as &$l) { $l['cost'] = null; } unset($l);
            }
            unset($r);
        }
        $vendorsAll = TenantVendor::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'lead_time_days', 'lead_time_mode', 'learned_lead_days', 'learned_po_count'])
            ->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'lead' => BuyingService::leadDays($v)])->values();

        return view('tenant.buying.index', [
            'lock'      => null,
            'rows'      => $rows,
            'searches'  => $canCost ? $this->buying->searches($tenant, $loc) : array_map(function ($x) { $x['cost'] = null; foreach ($x['vendors'] as &$v) { $v['cost'] = null; } return $x; }, $this->buying->searches($tenant, $loc)),
            'otb'       => $canCost ? $this->buying->openToBuy($tenant, $loc) : null,
            'drafts'    => $this->buying->drafts($tenant, null),
            'locs'      => $locs,
            'loc'       => $loc,
            'w'         => $w,
            'set'       => $set,
            'canCost'   => $canCost,
            'canPo'     => $user ? $user->canAccessSection('purchase_orders') : false,
            'canSettings' => $user ? $user->canAccessSection('settings') : false,
            'vendorsAll'=> $vendorsAll,
            'velocity'  => \App\Services\Tenant\ReportBuilder\ReportCatalog::velocity(),
            'tab'       => in_array($request->query('tab'), ['moving', 'searched', 'otb'], true) ? $request->query('tab') : 'moving',
        ]);
    }

    public function drafts(Request $request): JsonResponse
    {
        $this->guard();

        return response()->json(['drafts' => $this->buying->drafts(tenant(), null)]);
    }

    public function addToPo(Request $request): JsonResponse
    {
        $this->guard();
        $tenant = tenant();
        abort_unless(auth('tenant')->user()?->canAccessSection('purchase_orders'), 403, 'Your role can\'t add to purchase orders.');
        $data = $request->validate([
            'item_id'     => ['required', 'uuid'],
            'vendor_id'   => ['required', 'uuid'],
            'location_id' => ['required', 'uuid'],
            'qty'         => ['required', 'integer', 'min:1', 'max:100000'],
        ]);
        if (! TenantLocation::where('tenant_id', $tenant->id)->where('is_active', true)->whereKey($data['location_id'])->exists()) {
            return response()->json(['ok' => false, 'error' => 'Pick one of your active locations to ship to.'], 422);
        }
        try {
            $r = $this->buying->addToPo($tenant, $data['item_id'], $data['vendor_id'], $data['location_id'], (int) $data['qty']);
        } catch (PurchaseOrderException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        $po = $r['po'];

        return response()->json([
            'ok' => true, 'po' => $po->po_number, 'poId' => $po->id, 'made' => $r['made'], 'raised' => $r['raised'],
            'url' => route('tenant.purchase-orders.show', ['id' => $po->id]),
            'drafts' => $this->buying->drafts($tenant, null),
        ]);
    }

    public function dismiss(Request $request): JsonResponse
    {
        $this->guard();
        $data = $request->validate(['key' => ['required', 'string', 'max:120']]);
        $tid = tenant()->id;
        $row = DB::table('tenant_buying_dismissals')->where('tenant_id', $tid)->where('query_key', $data['key'])->first(['id']);
        $vals = ['dismissed_at' => now(), 'dismissed_by_user_id' => auth('tenant')->id(), 'updated_at' => now()];
        if ($row) {
            DB::table('tenant_buying_dismissals')->where('id', $row->id)->update($vals);
        } else {
            DB::table('tenant_buying_dismissals')->insert($vals + ['id' => (string) Str::uuid(), 'tenant_id' => $tid, 'query_key' => $data['key'], 'created_at' => now()]);
        }

        // what was counted stops counting; a new search starts it again from zero
        DB::table('tenant_buying_searches')->where('tenant_id', $tid)->where('query_key', $data['key'])->delete();

        return response()->json(['ok' => true]);
    }

    public function saveGoals(Request $request)
    {
        $this->guard();
        abort_unless(auth('tenant')->user()?->canAccessSection('cost_margins'), 403, 'Your role doesn\'t include costs.');
        $tenant = tenant();
        $data = $request->validate([
            'location_id' => ['required', 'uuid'],
            'goals'       => ['array'],
            'goals.*'     => ['nullable', 'string', 'max:16'],
        ]);
        if (! TenantLocation::where('tenant_id', $tenant->id)->where('is_active', true)->whereKey($data['location_id'])->exists()) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Pick a location to set its goals.']);
        }
        $goals = [];
        foreach ((array) ($data['goals'] ?? []) as $cat => $v) {
            $clean = preg_replace('/[^0-9.]/', '', (string) $v);
            $goals[$cat === '_' ? '' : $cat] = $clean === '' ? 0 : (int) round(((float) $clean) * 100);
        }
        $n = $this->buying->saveGoals($tenant, $data['location_id'], $goals, auth('tenant')->id());

        return redirect()->route('tenant.buying', ['tab' => 'otb', 'loc' => $data['location_id'], 'w' => $request->input('w', 13)])
            ->with('flash', ['type' => 'success', 'message' => $n ? 'Goals saved.' : 'Nothing to save.']);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->guard();
        [$locs, $loc, $w] = $this->scope($request);
        $canCost = auth('tenant')->user()?->canAccessSection('cost_margins');
        $rows = $this->buying->moving(tenant(), $loc, $w);
        $st = ['out' => 'Out of stock', 'reorder' => 'Order now', 'soon' => 'Order soon', 'ok' => 'Healthy', 'over' => 'Overstocked'];
        $name = 'buying-moving-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($rows, $st, $canCost, $w) {
            $f = fopen('php://output', 'w');
            $head = ['Item', 'SKU', 'Brand', 'Category', 'Sold (' . BuyingService::WINDOWS[$w] . ')', 'Per week', 'On hand', 'Days left', 'On order', 'Held for customers', 'Next due', 'Status', 'Vendor', 'Distributor stock'];
            if ($canCost) { $head[] = 'Cost'; }
            fputcsv($f, $head);
            foreach ($rows as $r) {
                $line = [$r['name'], $r['sku'], $r['brand'], $r['cat'], $r['sold'], $r['pace'], $r['onHand'],
                    $r['onHand'] <= 0 ? 0 : ($r['days'] === null ? '' : round($r['days'])), $r['owed'], $r['cust'],
                    $r['next']['date'] ?? '', $st[$r['st']], $r['vendors'][0]['name'] ?? '', $r['dist']['avail'] ?? ''];
                if ($canCost) { $line[] = $r['cost'] !== null ? number_format($r['cost'] / 100, 2, '.', '') : ''; }
                fputcsv($f, $line);
            }
            fclose($f);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /** A teammate asks the owners for Buying. Reachable without the section, on purpose. */
    public function requestAccess(Request $request): JsonResponse
    {
        $tenant = tenant();
        abort_unless($tenant && $tenant->retail_enabled, 404);
        $me = auth('tenant')->user();
        abort_unless($me, 403);
        $owners = TenantUser::where('tenant_id', $tenant->id)->where('is_active', true)->where('role', 'owner')->get();
        foreach ($owners as $o) {
            app(StaffAlertService::class)->emit($tenant, 'access.request', [
                'title' => trim(($me->name ?? 'A teammate')) . ' asks for access to Buying',
                'body'  => 'Their role doesn\'t include Buying. You can add it to the role under Team & access.',
                'link'  => \Illuminate\Support\Facades\Route::has('tenant.team.roles') ? route('tenant.team.roles') : null,
                'only_user_id' => $o->id,
            ]);
        }

        return response()->json(['ok' => true, 'sent' => $owners->count()]);
    }
}
