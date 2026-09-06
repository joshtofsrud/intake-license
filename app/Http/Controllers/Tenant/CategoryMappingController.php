<?php
// MARKER-CAT-MAP

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryCategory;
use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inventory > Category mappings — the one place source -> category lives.
 * Import step 3 and the uncategorized mapper both write here; this is where
 * a shop comes back to read and change what they decided.
 */
class CategoryMappingController extends Controller
{
    public function index(Request $request)
    {
        $tenant = tenant();
        $q      = trim((string) $request->input('q', ''));
        $src    = trim((string) $request->input('source', ''));
        $only   = (string) $request->input('only', '');

        $rules = DB::table('tenant_bucket_rules as r')
            ->leftJoin('tenant_inventory_categories as c', 'c.id', '=', 'r.category_id')
            ->where('r.tenant_id', $tenant->id)
            ->when($q !== '', fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('r.bucket_key', 'like', "%{$q}%")->orWhere('c.name', 'like', "%{$q}%");
            }))
            ->when($src !== '', fn ($w) => $w->where('r.source_name', $src))
            ->orderBy('r.source_kind')->orderBy('r.source_name')->orderBy('r.bucket_key')
            ->get(['r.id', 'r.source_kind', 'r.source_name', 'r.bucket_key', 'r.category_id',
                   'r.set_by', 'r.hits', 'r.updated_at', 'c.name as category_name']);

        // Items each rule covers: distributor rules by catalog category,
        // import rules by the source string kept on the item.
        $counts = [];
        foreach ($rules as $r) {
            $counts[$r->id] = $r->source_kind === 'import'
                ? TenantInventoryItem::where('tenant_id', $tenant->id)
                    ->where('source_name', $r->source_name)->where('source_category', $r->bucket_key)->count()
                : TenantInventoryItem::where('tenant_id', $tenant->id)
                    ->whereHas('distributorCatalog', fn ($w) => $w->where('category', $r->bucket_key)
                        ->when($r->source_name !== 'UNKNOWN', fn ($v) => $v->where('distributor_code', $r->source_name)))
                    ->count();
        }

        // Source strings on items that have NO rule yet — the "unmapped" view.
        $unmapped = collect();
        if ($only === 'unmapped' || $only === '') {
            $seen = $rules->map(fn ($r) => $r->source_name . '|' . $r->bucket_key)->flip();
            $unmapped = TenantInventoryItem::query()
                ->where('tenant_id', $tenant->id)->whereNotNull('source_category')
                ->when($src !== '', fn ($w) => $w->where('source_name', $src))
                ->selectRaw('source_name, source_category, COUNT(*) as n')
                ->groupBy('source_name', 'source_category')->orderByDesc('n')->get()
                ->reject(fn ($u) => isset($seen[$u->source_name . '|' . $u->source_category]));
        }

        $groups = $rules->groupBy(fn ($r) => $r->source_kind . '|' . $r->source_name);
        $sources = $rules->map(fn ($r) => $r->source_name)
            ->merge($unmapped->map(fn ($u) => $u->source_name))->unique()->filter()->sort()->values();

        $categories = InventoryController::categoryOptions($tenant->id);
        $catOpts = [];
        foreach ($categories as $opt) {
            $catOpts[$opt['cat']->id] = str_repeat("\u{00A0}\u{00A0}", max(0, $opt['depth'] - 1))
                . ($opt['depth'] ? '└ ' : '') . $opt['cat']->name;
        }

        return view('tenant.inventory.category-mappings', compact(
            'rules', 'groups', 'counts', 'unmapped', 'sources', 'catOpts', 'q', 'src', 'only'
        ));
    }

    /** Set or change one rule. Optionally move the items the old rule covered. */
    public function save(Request $request): RedirectResponse
    {
        $tenant = tenant();
        $data = $request->validate([
            'source_kind' => ['required', 'in:distributor,import'],
            'source_name' => ['required', 'string', 'max:64'],
            'bucket_key'  => ['required', 'string', 'max:160'],
            'category_id' => ['nullable', 'uuid'],
            'new_category' => ['nullable', 'string', 'max:120'],
            'apply'       => ['nullable', 'in:forward,move'],
        ]);

        $categoryId = $data['category_id'] ?? null;
        if (! $categoryId && filled($data['new_category'] ?? null)) {
            $cat = TenantInventoryCategory::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => trim($data['new_category']), 'parent_id' => null],
                ['id' => (string) Str::uuid(), 'sort_order' => 999]
            );
            $categoryId = $cat->id;
        }
        if ($categoryId) {
            abort_unless(TenantInventoryCategory::where('tenant_id', $tenant->id)->where('id', $categoryId)->exists(), 422);
        }

        $key = ['tenant_id' => $tenant->id, 'source_kind' => $data['source_kind'],
                'source_name' => $data['source_name'], 'bucket_key' => $data['bucket_key']];

        if (! $categoryId) {
            DB::table('tenant_bucket_rules')->where($key)->delete();
            return back()->with('flash', ['type' => 'success', 'message' => 'Rule removed — those items stay where they are.']);
        }

        DB::table('tenant_bucket_rules')->updateOrInsert($key, [
            'id' => DB::raw("COALESCE(id, '" . (string) Str::uuid() . "')"),
            'category_id' => $categoryId, 'set_by' => 'user', 'set_by_user_id' => auth('tenant')->id(),
            'last_used_at' => now(), 'updated_at' => now(), 'created_at' => DB::raw('COALESCE(created_at, NOW())'),
        ]);

        $moved = 0;
        if (($data['apply'] ?? 'forward') === 'move') {
            $moved = $this->moveCovered($tenant->id, $data, $categoryId);
        }

        $cat = TenantInventoryCategory::find($categoryId);
        return back()->with('flash', ['type' => 'success',
            'message' => "“{$data['bucket_key']}” → {$cat->name}" . ($moved ? " · {$moved} item(s) moved" : ' · applies from now on')]);
    }

    /**
     * Move every item this source string covers to the category, through the
     * same ledger the mapper writes — so it appears on the undo rail.
     */
    private function moveCovered(string $tenantId, array $d, string $categoryId): int
    {
        $q = TenantInventoryItem::where('tenant_id', $tenantId)
            ->where(function ($w) use ($categoryId) { $w->whereNull('category_id')->orWhere('category_id', '!=', $categoryId); });

        if ($d['source_kind'] === 'import') {
            $q->where('source_name', $d['source_name'])->where('source_category', $d['bucket_key']);
        } else {
            $q->whereHas('distributorCatalog', fn ($w) => $w->where('category', $d['bucket_key'])
                ->when($d['source_name'] !== 'UNKNOWN', fn ($v) => $v->where('distributor_code', $d['source_name'])));
        }

        $rows = $q->get(['id', 'category_id']);
        if ($rows->isEmpty()) return 0;

        $cat = TenantInventoryCategory::find($categoryId);
        $assignmentId = (string) Str::uuid();
        DB::transaction(function () use ($rows, $assignmentId, $tenantId, $cat, $d, $categoryId) {
            DB::table('tenant_category_assignments')->insert([
                'id' => $assignmentId, 'tenant_id' => $tenantId,
                'bucket_key' => $d['bucket_key'], 'category_id' => $categoryId, 'category_name' => $cat->name,
                'item_count' => $rows->count(), 'source' => 'rule', 'created_by' => auth('tenant')->id(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($rows->chunk(500) as $chunk) {
                DB::table('tenant_category_assignment_items')->insert(
                    $chunk->map(fn ($r) => ['id' => (string) Str::uuid(), 'assignment_id' => $assignmentId,
                                           'item_id' => $r->id, 'prior_category_id' => $r->category_id])->all()
                );
                TenantInventoryItem::whereIn('id', $chunk->pluck('id'))->update(['category_id' => $categoryId]);
            }
        });

        return $rows->count();
    }
}
