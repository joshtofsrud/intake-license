<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CatalogChangeBatch;
use App\Models\Tenant\TenantPosConnection;
use App\Services\Pos\PosImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * MARKER-CONNECTED-POS-SECTION: Inventory › Import with the shop's Connected POS as the
 * source. Same screen and rules as importing from a distributor: filter, preview what
 * Import would do with every product, then bring in one, the ticked rows, or everything.
 * A run shows its progress live, can be stopped and resumed, and lands on one batch in
 * catalog history that can be undone.
 */
class PosImportController extends Controller
{
    private function guard(): void
    {
        abort_unless(tenant()->connected_pos_enabled, 404);
        $me = Auth::guard('tenant')->user();
        abort_unless($me && $me->canAccessSection('inventory'), 403);
    }

    private function conn(): ?TenantPosConnection
    {
        return TenantPosConnection::where('tenant_id', tenant()->id)->first();
    }

    private function planKey(array $filters): string
    {
        return 'pos_import_plan:' . tenant()->id . ':' . sha1(json_encode($filters));
    }

    private function mine(string $key): bool
    {
        return str_starts_with($key, 'pos_import_plan:' . tenant()->id . ':');
    }

    /** GET — the screen. With ?key= it shows that preview. */
    public function page(Request $request, PosImportService $svc)
    {
        $this->guard();
        $tenant = tenant();
        $conn = $this->conn();

        $view = [
            'conn'        => $conn,
            'label'       => $conn ? \App\Models\PlatformPosAdapter::forCode($conn->adapter_code)->displayLabel() : null,
            'distCodes'   => $tenant->distributor_sync_enabled ? app(DistributorController::class)->importableCodes() : [],
            'counts'      => $conn ? $svc->counts($conn) : null,
            'options'     => $conn ? $svc->options($conn) : ['brands' => [], 'categories' => []],
            'filters'     => [],
            'planKey'     => null,
            'plan'        => null,
            'state'       => (array) ($conn->import_state ?? []),
            'batches'     => CatalogChangeBatch::where('tenant_id', $tenant->id)->where('action', 'pos_import')->orderByDesc('created_at')->limit(5)->get(),
        ];

        $key = (string) $request->query('key', '');
        if ($conn && $key !== '' && $this->mine($key) && is_array($cached = Cache::get($key))) {
            $view['planKey'] = $key;
            $view['plan'] = $cached['counts'];
            $view['filters'] = $cached['filters'];
        } elseif ($key !== '') {
            $view['expired'] = true;
        } elseif ($request->boolean('new')) {
            $view['filters'] = ['new_only' => true]; // from "N new" on the Connected POS pages
        }

        return view('tenant.connected-pos.import', $view);
    }

    /** POST — work out what Import would do with every product the filters match. */
    public function preview(Request $request, PosImportService $svc): RedirectResponse
    {
        $this->guard();
        $conn = $this->conn();
        if (! $conn) {
            return redirect()->route('tenant.inventory.cpos');
        }
        $f = PosImportService::filters($request->only(['brand', 'category', 'in_stock', 'new_only', 'include_removed']));
        $plan = $svc->plan($conn, $f);
        if (! $plan['rows']) {
            return redirect()->route('tenant.inventory.cpos')->with('error', 'Nothing in your Connected POS matches those filters. Widen the brand or category, or untick a box.')->withInput();
        }
        $key = $this->planKey($f);
        Cache::put($key, ['rows' => $plan['rows'], 'counts' => $plan['counts'], 'filters' => $f], now()->addMinutes(30));

        return redirect()->route('tenant.inventory.cpos', ['key' => $key]);
    }

    /** GET — one page of the preview's row-by-row list, searchable. */
    public function plan(Request $request): JsonResponse
    {
        $this->guard();
        $key = (string) $request->query('key', '');
        abort_unless($this->mine($key), 404);
        $cached = Cache::get($key);
        if (! is_array($cached)) {
            return response()->json(['ok' => false, 'error' => 'This preview has expired. Press Preview again.'], 410);
        }

        $tab = (string) $request->query('tab', 'todo');
        $q   = mb_strtolower(trim((string) $request->query('q', '')));
        $qd  = preg_replace('/[^a-z0-9]/', '', $q);

        $rows = array_values(array_filter($cached['rows'], function ($r) use ($tab, $q, $qd) {
            if ($tab === 'todo' && $r['action'] === 'linked') {
                return false;
            }
            if (in_array($tab, ['new', 'merge', 'linked', 'twin'], true) && $r['action'] !== $tab) {
                return false;
            }
            if ($q === '') {
                return true;
            }
            if (str_contains(mb_strtolower($r['name'] . ' ' . $r['brand'] . ' ' . ($r['item_name'] ?? '') . ' ' . implode(' ', array_column($r['twins'] ?? [], 'name'))), $q)) {
                return true;
            }
            foreach ([$r['part'], $r['mpn'], $r['code']] as $id) {
                $idd = preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $id));
                if ($qd !== '' && $idd !== '' && str_contains($idd, $qd)) {
                    return true;
                }
            }
            return false;
        }));

        $per   = 50;
        $total = count($rows);
        $pages = max(1, (int) ceil($total / $per));
        $page  = max(1, min($pages, (int) $request->query('page', 1)));
        $slice = array_slice($rows, ($page - 1) * $per, $per);
        foreach ($slice as &$r) {
            $r['item_url'] = $r['item'] ? route('tenant.inventory.show', $r['item']) : null;
        }
        unset($r);

        return response()->json(['ok' => true, 'rows' => $slice, 'total' => $total, 'page' => $page, 'pages' => $pages]);
    }

    /** POST — bring in one product now, from the list. */
    public function one(Request $request, PosImportService $svc): JsonResponse
    {
        $this->guard();
        $data = $request->validate(['id' => ['required', 'integer'], 'key' => ['nullable', 'string', 'max:200'], 'as' => ['nullable', 'in:follow,own']]);
        $conn = $this->conn();
        if (! $conn) {
            return response()->json(['ok' => false, 'error' => 'There is no Connected POS.'], 422);
        }
        if ((($conn->import_state ?? [])['running'] ?? false)) {
            return response()->json(['ok' => false, 'error' => 'An import is running. This one will be reached, or stop the run first.'], 422);
        }

        try {
            $res = $svc->importOne($conn, (int) $data['id'], Auth::guard('tenant')->user()?->email, $data['as'] ?? null);
        } catch (\Throwable $e) {
            \App\Support\JobFailureReporter::report(self::class, 'A Connected POS product could not be brought in', $e, ['pos_item' => $data['id']], tenant()->id);

            return response()->json(['ok' => false, 'error' => 'That product could not be brought in. It has been reported.'], 500);
        }
        if ($res['did'] === 'missing') {
            return response()->json(['ok' => false, 'error' => 'That product is no longer in your Connected POS list. Read the catalog again.'], 422);
        }
        if ($res['did'] === 'choose') {
            // MARKER-POS-TWINS
            return response()->json(['ok' => false, 'error' => 'Your Connected POS has this product more than once. Preview in Import, open "There more than once", and choose what to do with this copy.'], 422);
        }
        if ($res['did'] === 'skip') {
            return response()->json(['ok' => false, 'error' => 'The item this would link to is no longer in Intake. Preview again.'], 422);
        }

        $key = (string) ($data['key'] ?? '');
        $replan = false;
        if ($res['item'] && $this->mine($key) && is_array($cached = Cache::get($key))) {
            if (! empty($data['as'])) {
                // a choice about one copy changes what its twins' rows say, so work the preview out again
                $plan = $svc->plan($conn, (array) $cached['filters']);
                $cached = ['rows' => $plan['rows'], 'counts' => $plan['counts'], 'filters' => $cached['filters']];
                $replan = true;
            } else {
                foreach ($cached['rows'] as &$r) {
                    if ((int) $r['id'] === (int) $data['id']) {
                        $r['action'] = 'linked';
                        $r['item'] = (string) $res['item'];
                        $r['is_new'] = false;
                    }
                }
                unset($r);
            }
            Cache::put($key, $cached, now()->addMinutes(30));
        }

        return response()->json(['ok' => true, 'did' => $res['did'], 'reload' => $replan, 'url' => $res['item'] ? route('tenant.inventory.show', $res['item']) : null]);
    }

    /** POST — start a run: everything the preview matched, or just the ticked rows. */
    public function run(Request $request, PosImportService $svc): RedirectResponse
    {
        $this->guard();
        $conn = $this->conn();
        $key = (string) $request->input('key', '');
        if (! $conn || ! $this->mine($key) || ! is_array($cached = Cache::get($key))) {
            return redirect()->route('tenant.inventory.cpos')->with('error', 'That preview has expired. Press Preview again, then import.');
        }
        $back = redirect()->route('tenant.inventory.cpos', ['key' => $key]);
        if ((($conn->import_state ?? [])['running'] ?? false) && $conn->updated_at?->gt(now()->subMinutes(15))) {
            return $back->with('success', 'An import is already running — progress is shown below.');
        }

        $f = (array) $cached['filters'];
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('ids', []))));
        if ($ids) {
            $f = ['ids' => array_slice($ids, 0, 2000)] + (! empty($f['include_removed']) ? ['include_removed' => true] : []);
        }
        $svc->start($conn, $f, Auth::guard('tenant')->user()?->email);
        if ((int) ($conn->import_state['total'] ?? 0) === 0) {
            $svc->step($conn); // closes the empty run cleanly
            CatalogChangeBatch::where('id', $conn->import_state['batch_id'] ?? '')->delete(); // nothing to undo
            return $back->with('success', 'Nothing left to bring in: every product in this preview is already linked.');
        }
        \App\Jobs\PosImportJob::dispatch($conn->id);

        return $back->with('success', 'Importing ' . number_format((int) $conn->import_state['total']) . ' ' . ((int) $conn->import_state['total'] === 1 ? 'product' : 'products') . ' — progress is shown below.');
    }

    public function stop(): RedirectResponse
    {
        $this->guard();
        $conn = $this->conn();
        abort_unless($conn, 404);
        $st = (array) ($conn->import_state ?? []);
        if (! ($st['running'] ?? false)) {
            return back()->with('success', 'Nothing is running.');
        }
        $st['running'] = false;
        $st['stopped'] = true;
        $st['finished_at'] = now()->toIso8601String();
        $conn->forceFill(['import_state' => $st])->save();

        return back()->with('success', 'Stopped after ' . number_format((int) ($st['done'] ?? 0)) . ' of ' . number_format((int) ($st['total'] ?? 0)) . '. What is in stays in. Resume carries on from there.');
    }

    public function resume(): RedirectResponse
    {
        $this->guard();
        $conn = $this->conn();
        abort_unless($conn, 404);
        $st = (array) ($conn->import_state ?? []);
        // a run whose worker died mid-way still says "running"; after 15 quiet minutes it can be resumed
        $stalled = ($st['running'] ?? false) && $conn->updated_at?->lt(now()->subMinutes(15));
        if (empty($st['stopped']) && empty($st['error']) && ! $stalled) {
            return back()->with('success', 'Nothing to resume.');
        }
        if (empty($st['batch_id']) || ! CatalogChangeBatch::where('id', $st['batch_id'])->whereNull('undone_at')->exists()) {
            return back()->with('error', 'That import was undone, so it can\'t be resumed. Preview and import again.');
        }
        $st['running'] = true;
        $st['stopped'] = false;
        $st['error'] = null;
        $st['finished_at'] = null;
        $conn->forceFill(['import_state' => $st])->save();
        \App\Jobs\PosImportJob::dispatch($conn->id);

        return back()->with('success', 'Resumed from ' . number_format((int) ($st['done'] ?? 0)) . ' of ' . number_format((int) ($st['total'] ?? 0)) . '.');
    }

    /** GET — live progress. */
    public function status(PosImportService $svc): JsonResponse
    {
        $this->guard();
        $conn = $this->conn();

        return response()->json([
            'import' => $conn?->import_state,
            'counts' => $conn ? $svc->counts($conn) : null,
        ]);
    }
}
