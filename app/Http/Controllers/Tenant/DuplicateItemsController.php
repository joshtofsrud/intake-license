<?php
// MARKER-DUP-MERGE

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Concerns\GuardsRetailAccess;
use App\Jobs\FindDuplicateItemsJob;
use App\Jobs\MergeDuplicateGroupsJob;
use App\Models\Tenant\TenantDuplicateGroup;
use App\Services\Inventory\DuplicateItemMerger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Inventory › Duplicates — products that are in a shop's inventory more than
 * once. Reached from Catalog attention. Needs inventory.items.merge, the same
 * permission as the Merge button, because this is that merge in bulk.
 */
class DuplicateItemsController extends Controller
{
    use GuardsRetailAccess;

    private function guard()
    {
        $tenant = tenant();
        $this->assertRetailEnabled($tenant);
        $user = Auth::guard('tenant')->user();
        abort_unless($user && $user->can('inventory.items.merge'), 403);

        return [$tenant, $user];
    }

    public function index(): View
    {
        [$tenant] = $this->guard();

        $base = TenantDuplicateGroup::where('tenant_id', $tenant->id);
        $readyCount = (clone $base)->where('status', 'ready')->count();
        $ready = (clone $base)->where('status', 'ready')->orderBy('label')->limit(100)->get();
        $review = (clone $base)->whereIn('status', ['review', 'failed'])->orderBy('status')->orderBy('label')->limit(200)->get();
        $reviewCount = (clone $base)->whereIn('status', ['review', 'failed'])->count();
        $lastFound = (clone $base)->max('found_at');
        $merging = cache()->has(MergeDuplicateGroupsJob::runningKey($tenant->id));

        return view('tenant.inventory.duplicates', compact('ready', 'readyCount', 'review', 'reviewCount', 'lastFound', 'merging'));
    }

    public function mergeAll(): RedirectResponse
    {
        [$tenant, $user] = $this->guard();
        $n = TenantDuplicateGroup::where('tenant_id', $tenant->id)->where('status', 'ready')->count();
        if ($n === 0) {
            return back()->with('flash', ['type' => 'info', 'message' => 'Nothing ready to merge.']);
        }
        cache()->put(MergeDuplicateGroupsJob::runningKey($tenant->id), true, now()->addHour());
        MergeDuplicateGroupsJob::dispatch($tenant->id, $user->id);

        return back()->with('flash', ['type' => 'success',
            'message' => 'Merging ' . number_format($n) . ' in the background. This list empties as they finish — refresh to see progress.']);
    }

    public function merge(Request $request, string $id, DuplicateItemMerger $merger): RedirectResponse
    {
        [$tenant, $user] = $this->guard();
        $group = TenantDuplicateGroup::where('tenant_id', $tenant->id)->whereIn('status', TenantDuplicateGroup::OPEN)->findOrFail($id);

        $data = $request->validate([
            'stock'      => ['nullable', 'string', 'max:12'],
            'price_from' => ['nullable', 'uuid'],
        ]);
        if (isset($data['stock']) && $data['stock'] !== 'add' && ! ctype_digit($data['stock'])) {
            return back()->with('flash', ['type' => 'error', 'message' => 'Choose how much stock to keep.']);
        }

        try {
            $merger->merge($group, $data, $user);
        } catch (\RuntimeException $e) {
            FindDuplicateItemsJob::dispatch($tenant->id);
            return back()->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with('flash', ['type' => 'success', 'message' => 'Merged into "' . ($group->preview['copies'][0]['name'] ?? 'the kept item') . '".']);
    }

    public function dismiss(string $id): RedirectResponse
    {
        [$tenant, $user] = $this->guard();
        $group = TenantDuplicateGroup::where('tenant_id', $tenant->id)->whereIn('status', TenantDuplicateGroup::OPEN)->findOrFail($id);
        $group->forceFill(['status' => 'dismissed', 'resolved_at' => now(), 'resolved_by' => $user->id])->save();

        return back()->with('flash', ['type' => 'success', 'message' => 'Marked as different products. They won\'t be suggested again.']);
    }

    public function download(): StreamedResponse
    {
        [$tenant] = $this->guard();
        $rows = TenantDuplicateGroup::where('tenant_id', $tenant->id)->whereIn('status', TenantDuplicateGroup::OPEN)->orderBy('status')->orderBy('label')->cursor();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['group', 'status', 'needs a look because', 'action', 'item', 'sku', 'from', 'shop price', 'list price', 'stock', 'sales']);
            foreach ($rows as $g) {
                foreach (($g->preview['copies'] ?? []) as $n => $c) {
                    fputcsv($out, [
                        $g->label, $g->status, implode(', ', (array) $g->reasons),
                        $n === 0 ? 'keep' : 'merge', $c['name'], $c['sku'], implode(', ', $c['from'] ?? []),
                        $c['shop_price'] !== null ? number_format($c['shop_price'] / 100, 2) : '',
                        $c['list_price'] !== null ? number_format($c['list_price'] / 100, 2) : '',
                        $c['stock'], $c['sales'],
                    ]);
                }
            }
            fclose($out);
        }, 'duplicate-items.csv', ['Content-Type' => 'text/csv']);
    }
}
