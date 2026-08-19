<?php
// MARKER-PATCH-217 / MARKER-PATCH-218 / MARKER-PATCH-226 / MARKER-PATCH-227
// Fleet admin. 227 rebuilt this onto the model layer: category -> model ->
// unit, with rollups, search/filter/pagination and bulk add.

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantRental;
use App\Models\Tenant\TenantRentalCategory;
use App\Models\Tenant\TenantRentalConditionTemplate;
use App\Models\Tenant\TenantRentalModel;
use App\Models\Tenant\TenantRentalUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RentalFleetController extends Controller
{
    private const PER_PAGE = 12; // categories per page

    public function index(Request $request)
    {
        $tenant = tenant();

        $search   = trim((string) $request->query('q', ''));
        $catId    = $request->query('category');
        $status   = $request->query('status'); // available|out|leased|maintenance
        $page     = max(1, (int) $request->query('page', 1));

        // Units physically out right now, per unit id (derived, never stored).
        $outUnitIds = DB::table('tenant_rental_lines')
            ->join('tenant_rentals', 'tenant_rentals.id', '=', 'tenant_rental_lines.rental_id')
            ->where('tenant_rentals.tenant_id', $tenant->id)
            ->where('tenant_rentals.status', 'out')
            ->where('tenant_rental_lines.kind', 'unit')
            ->pluck('tenant_rental_lines.unit_id')
            ->filter()->unique()->flip();

        // Reserved (upcoming) unit ids — shown as committed in rollups.
        $reservedUnitIds = DB::table('tenant_rental_lines')
            ->join('tenant_rentals', 'tenant_rentals.id', '=', 'tenant_rental_lines.rental_id')
            ->where('tenant_rentals.tenant_id', $tenant->id)
            ->where('tenant_rentals.status', 'reserved')
            ->where('tenant_rental_lines.kind', 'unit')
            ->pluck('tenant_rental_lines.unit_id')
            ->filter()->unique()->flip();

        $catQuery = TenantRentalCategory::where('tenant_id', $tenant->id)
            ->whereNull('archived_at')
            ->when($catId, fn ($q) => $q->where('id', $catId))
            ->orderBy('sort_order')->orderBy('name');

        $totalCats = (clone $catQuery)->count();
        $categories = $catQuery->forPage($page, self::PER_PAGE)->get();
        $catIds = $categories->pluck('id');

        // Models for the visible categories, with their units.
        $models = TenantRentalModel::where('tenant_id', $tenant->id)
            ->whereNull('archived_at')
            ->whereIn('category_id', $catIds)
            ->with(['units' => fn ($q) => $q->whereNull('archived_at')->orderBy('identifier')->orderBy('size'),
                    'conditionTemplate:id,name'])
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        // Annotate each unit with its derived status + apply the search/status
        // filters in PHP (the dataset per page is small: a page of categories).
        $needle = mb_strtolower($search);
        $modelsByCat = [];
        foreach ($models as $model) {
            $units = $model->units->map(function ($u) use ($outUnitIds, $reservedUnitIds) {
                $derived = $u->status; // available|maintenance|retired
                if ($u->status === 'available') {
                    if ($outUnitIds->has($u->id))      $derived = 'out';
                    elseif ($reservedUnitIds->has($u->id)) $derived = 'reserved';
                }
                $u->derived_status = $derived;
                return $u;
            });

            // status filter
            if ($status) {
                $units = $units->filter(fn ($u) => $u->derived_status === $status
                    || ($status === 'maintenance' && $u->status === 'maintenance'));
            }
            // search across model name + unit serial/size
            if ($needle !== '') {
                $modelHit = str_contains(mb_strtolower($model->name . ' ' . $model->subtitle), $needle);
                if (!$modelHit) {
                    $units = $units->filter(fn ($u) =>
                        str_contains(mb_strtolower((string) $u->identifier . ' ' . (string) $u->size), $needle));
                    if ($units->isEmpty()) continue; // model has no matching units and name didn't match
                }
            }

            $model->view_units = $units->values();
            $model->avail_count = $units->where('derived_status', 'available')->count();
            $modelsByCat[$model->category_id][] = $model;
        }

        // Per-category rollup (available / out / reserved / maintenance).
        $rollups = [];
        foreach ($categories as $cat) {
            $catModels = $modelsByCat[$cat->id] ?? [];
            $all = collect($catModels)->flatMap(fn ($m) => $m->view_units);
            $rollups[$cat->id] = [
                'total'       => $all->count(),
                'available'   => $all->where('derived_status', 'available')->count(),
                'out'         => $all->where('derived_status', 'out')->count(),
                'reserved'    => $all->where('derived_status', 'reserved')->count(),
                'maintenance' => $all->where('status', 'maintenance')->count(),
                'models'      => count($catModels),
            ];
        }

        $allCategories = TenantRentalCategory::where('tenant_id', $tenant->id)
            ->whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'size_axis']);

        $conditionTemplates = TenantRentalConditionTemplate::where('tenant_id', $tenant->id)
            ->orderBy('name')->get();

        $unitTotal = TenantRentalUnit::where('tenant_id', $tenant->id)
            ->whereNull('archived_at')->where('status', '!=', 'retired')->count();
        $modelTotal = TenantRentalModel::where('tenant_id', $tenant->id)->whereNull('archived_at')->count();

        // MARKER-PATCH-236 — per-unit roster meta in four grouped
        // queries: last rented, 30d utilization overlap, in-check flags,
        // photo'd checks. Keyed by unit_id; blade falls back gracefully.
        $unitMeta = [];

        $lastRented = DB::table('tenant_rental_lines')
            ->join('tenant_rentals', 'tenant_rentals.id', '=', 'tenant_rental_lines.rental_id')
            ->where('tenant_rentals.tenant_id', $tenant->id)
            ->where('tenant_rentals.status', '!=', 'cancelled')
            ->where('tenant_rental_lines.kind', 'unit')
            ->whereNotNull('tenant_rental_lines.unit_id')
            ->groupBy('tenant_rental_lines.unit_id')
            ->selectRaw('tenant_rental_lines.unit_id, MAX(tenant_rentals.starts_at) as last_at')
            ->pluck('last_at', 'unit_id');

        $windowStart = now()->subDays(30);
        $windowRows = DB::table('tenant_rental_lines')
            ->join('tenant_rentals', 'tenant_rentals.id', '=', 'tenant_rental_lines.rental_id')
            ->where('tenant_rentals.tenant_id', $tenant->id)
            ->where('tenant_rentals.status', '!=', 'cancelled')
            ->where('tenant_rental_lines.kind', 'unit')
            ->whereNotNull('tenant_rental_lines.unit_id')
            ->where('tenant_rentals.starts_at', '<=', now())
            ->where(function ($w) use ($windowStart) {
                $w->whereNull('tenant_rentals.returned_at')
                  ->orWhere('tenant_rentals.returned_at', '>=', $windowStart);
            })
            ->get(['tenant_rental_lines.unit_id', 'tenant_rentals.starts_at', 'tenant_rentals.returned_at']);
        $utilHours = [];
        foreach ($windowRows as $row) {
            $from = \Carbon\Carbon::parse($row->starts_at, 'UTC');
            if ($from->lessThan($windowStart)) {
                $from = $windowStart->copy();
            }
            $to = $row->returned_at ? \Carbon\Carbon::parse($row->returned_at, 'UTC') : now();
            if ($to->greaterThan(now())) {
                $to = now();
            }
            if ($to->greaterThan($from)) {
                $utilHours[$row->unit_id] = ($utilHours[$row->unit_id] ?? 0) + $from->floatDiffInHours($to);
            }
        }

        $flagCounts = DB::table('tenant_rental_condition_checks')
            ->join('tenant_rentals', 'tenant_rentals.id', '=', 'tenant_rental_condition_checks.rental_id')
            ->where('tenant_rentals.tenant_id', $tenant->id)
            ->where('tenant_rental_condition_checks.phase', 'check_in')
            ->where('tenant_rental_condition_checks.flagged', true)
            ->groupBy('tenant_rental_condition_checks.unit_id')
            ->selectRaw('tenant_rental_condition_checks.unit_id, COUNT(*) as n')
            ->pluck('n', 'unit_id');

        $photoCounts = DB::table('tenant_rental_condition_checks')
            ->join('tenant_rentals', 'tenant_rentals.id', '=', 'tenant_rental_condition_checks.rental_id')
            ->where('tenant_rentals.tenant_id', $tenant->id)
            ->whereNotNull('tenant_rental_condition_checks.photos')
            ->groupBy('tenant_rental_condition_checks.unit_id')
            ->selectRaw('tenant_rental_condition_checks.unit_id, COUNT(*) as n')
            ->pluck('n', 'unit_id');

        foreach ($models as $model) {
            foreach ($model->units as $u) {
                $unitMeta[$u->id] = [
                    'last'   => $lastRented[$u->id] ?? null,
                    'util'   => isset($utilHours[$u->id])
                        ? (int) round(min(100, ($utilHours[$u->id] / 24 / 30) * 100))
                        : null,
                    'flags'  => (int) ($flagCounts[$u->id] ?? 0),
                    'photos' => (int) ($photoCounts[$u->id] ?? 0),
                ];
            }
        }

        return view('tenant.rentals.fleet', [
            'unitMeta'           => $unitMeta, // MARKER-PATCH-236
            'categories'         => $categories,
            'modelsByCat'        => $modelsByCat,
            'rollups'            => $rollups,
            'allCategories'      => $allCategories,
            'conditionTemplates' => $conditionTemplates,
            'search'             => $search,
            'filterCategory'     => $catId,
            'filterStatus'       => $status,
            'page'               => $page,
            'pageCount'          => max(1, (int) ceil($totalCats / self::PER_PAGE)),
            'unitTotal'          => $unitTotal,
            'modelTotal'         => $modelTotal,
        ]);
    }

    // ------------------------------------------------------------ categories

    // ---------------------------------------------- unit detail (PATCH-235)
    /**
     * MARKER-PATCH-235 — the serial's whole story: utilization, revenue,
     * rental history, maintenance notes, recent check photos. Everything is
     * derived — no new tables.
     */
    public function showUnit(string $id)
    {
        $tenant = tenant();

        $unit = \App\Models\Tenant\TenantRentalUnit::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->with(['model', 'category', 'conditionTemplate'])
            ->firstOrFail();

        // Rental history: every rental this unit appeared on.
        $rentals = \App\Models\Tenant\TenantRental::where('tenant_id', $tenant->id)
            ->whereHas('lines', fn ($l) => $l->where('unit_id', $unit->id))
            ->with([
                'customer:id,first_name,last_name',
                'lines' => fn ($l) => $l->where('unit_id', $unit->id),
                'conditionChecks' => fn ($c) => $c->where('unit_id', $unit->id),
            ])
            ->orderByDesc('starts_at')
            ->limit(25)
            ->get();

        // Derived live state: out / reserved trumps the stored status.
        $derived = $unit->status;
        if ($rentals->firstWhere('status', 'out')?->lines->isNotEmpty()) {
            $derived = 'out';
        } elseif ($unit->status === 'available'
            && $rentals->firstWhere('status', 'reserved')?->lines->isNotEmpty()) {
            $derived = 'reserved';
        }

        // Utilization, trailing 30 days: overlap-days of non-cancelled
        // rentals within the window / 30.
        $windowStart = now()->subDays(30);
        $windowEnd   = now();
        $overlapHours = 0.0;
        $windowRentals = \App\Models\Tenant\TenantRental::where('tenant_id', $tenant->id)
            ->whereHas('lines', fn ($l) => $l->where('unit_id', $unit->id))
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<=', $windowEnd)
            ->where(function ($w) use ($windowStart) {
                $w->whereNull('returned_at')->orWhere('returned_at', '>=', $windowStart);
            })
            ->get(['id', 'starts_at', 'returned_at', 'status']);
        foreach ($windowRentals as $r) {
            $from = $r->starts_at->greaterThan($windowStart) ? $r->starts_at : $windowStart;
            $to   = $r->returned_at ?? $windowEnd;
            if ($to->greaterThan($windowEnd)) {
                $to = $windowEnd;
            }
            if ($to->greaterThan($from)) {
                $overlapHours += $from->floatDiffInHours($to);
            }
        }
        $utilizationPct = (int) round(min(100, ($overlapHours / 24 / 30) * 100));

        // Lifetime revenue: this unit's line totals on non-cancelled rentals.
        $lifetimeCents = (int) \App\Models\Tenant\TenantRentalLine::where('unit_id', $unit->id)
            ->whereHas('rental', fn ($r) => $r->where('tenant_id', $tenant->id)->where('status', '!=', 'cancelled'))
            ->sum('line_total_cents');

        $flaggedReturns = \App\Models\Tenant\TenantRentalConditionCheck::where('unit_id', $unit->id)
            ->where('phase', 'check_in')->where('flagged', true)->count();

        // Recent check photos (both phases), newest first.
        $photoChecks = \App\Models\Tenant\TenantRentalConditionCheck::where('unit_id', $unit->id)
            ->whereNotNull('photos')
            ->orderByDesc('performed_at')
            ->limit(6)
            ->get();

        return view('tenant.rentals.units.show', compact(
            'unit', 'derived', 'rentals', 'utilizationPct', 'lifetimeCents',
            'flaggedReturns', 'photoChecks'
        ));
    }

    public function storeCategory(Request $request)
    {
        $tenant = tenant();
        $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'size_axis' => ['nullable', 'string', 'max:40'],
        ]);

        $maxSort = TenantRentalCategory::where('tenant_id', $tenant->id)->max('sort_order') ?? 90;

        TenantRentalCategory::create([
            'tenant_id'  => $tenant->id,
            'name'       => $request->input('name'),
            'size_axis'  => $request->input('size_axis') ?: null,
            'sort_order' => $maxSort + 10,
        ]);

        return redirect()->route('tenant.rentals.fleet')->with('flash', 'Category added.');
    }

    public function updateCategory(Request $request, string $id)
    {
        $tenant = tenant();
        $category = TenantRentalCategory::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        [$field, $value] = $this->fieldValue($request);

        switch ($field) {
            case 'name':
                $request->validate(['value' => ['required', 'string', 'max:120']]);
                $category->update(['name' => $value]);
                break;
            case 'size_axis':
                $request->validate(['value' => ['nullable', 'string', 'max:40']]);
                $category->update(['size_axis' => ($value === '' ? null : $value)]);
                break;
            default:
                return response()->json(['success' => false, 'message' => 'Unknown field.'], 422);
        }
        return response()->json(['success' => true]);
    }

    public function destroyCategory(Request $request, string $id)
    {
        $tenant = tenant();
        $category = TenantRentalCategory::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $activeUnits = TenantRentalUnit::where('tenant_id', $tenant->id)
            ->where('category_id', $category->id)
            ->whereNull('archived_at')->where('status', '!=', 'retired')->count();

        if ($activeUnits > 0) {
            return response()->json(['success' => false,
                'message' => "Move or retire the {$activeUnits} unit(s) in this category first."], 422);
        }

        $category->update(['archived_at' => now()]);
        return response()->json(['success' => true]);
    }

    // ---------------------------------------------------------------- models
    public function storeModel(Request $request)
    {
        $tenant = tenant();
        $request->validate([
            'name'         => ['required', 'string', 'max:160'],
            'category_id'  => ['required', 'string', 'uuid'],
            'subtitle'     => ['nullable', 'string', 'max:120'],
            'hourly_rate'  => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'daily_rate'   => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'weekend_rate' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'seasonal_rate'=> ['nullable', 'numeric', 'min:0', 'max:99999'],
            'deposit'      => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'condition_template_id' => ['nullable', 'string', 'uuid'],
        ]);

        $category = TenantRentalCategory::where('tenant_id', $tenant->id)
            ->where('id', $request->input('category_id'))->whereNull('archived_at')->firstOrFail();

        $maxSort = TenantRentalModel::where('tenant_id', $tenant->id)
            ->where('category_id', $category->id)->max('sort_order') ?? 90;

        $model = TenantRentalModel::create([
            'tenant_id'             => $tenant->id,
            'category_id'           => $category->id,
            'name'                  => $request->input('name'),
            'subtitle'              => $request->input('subtitle') ?: null,
            'hourly_rate_cents'     => $this->dollarsToCents($request->input('hourly_rate')),
            'daily_rate_cents'      => $this->dollarsToCents($request->input('daily_rate')),
            'weekend_rate_cents'    => $this->dollarsToCents($request->input('weekend_rate')),
            'seasonal_rate_cents'   => $this->dollarsToCents($request->input('seasonal_rate')),
            'deposit_cents'         => $this->dollarsToCents($request->input('deposit')) ?? 0,
            'condition_template_id' => $this->verifyTemplate($tenant->id, $request->input('condition_template_id')),
            'sort_order'            => $maxSort + 10,
        ]);

        // Convenience: optionally seed the first unit in the same submit.
        if ($request->filled('first_unit_identifier') || $request->boolean('create_first_unit')) {
            TenantRentalUnit::create([
                'tenant_id'   => $tenant->id,
                'location_id' => $request->session()->get('current_location_id'),
                'category_id' => $category->id,
                'model_id'    => $model->id,
                'name'        => $model->name,
                'identifier'  => $request->input('first_unit_identifier') ?: null,
                'size'        => $request->input('first_unit_size') ?: null,
                'status'      => 'available',
                'available_for_rent' => true,
                'online_booking'     => true,
                'buffer_minutes'     => 0,
            ]);
        }

        return redirect()->route('tenant.rentals.fleet')->with('flash', 'Model added.');
    }

    public function updateModel(Request $request, string $id)
    {
        $tenant = tenant();
        $model = TenantRentalModel::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        [$field, $value] = $this->fieldValue($request);

        switch ($field) {
            case 'name':
                $request->validate(['value' => ['required', 'string', 'max:160']]);
                $model->update(['name' => $value]);
                break;
            case 'subtitle':
                $request->validate(['value' => ['nullable', 'string', 'max:120']]);
                $model->update(['subtitle' => ($value === '' ? null : $value)]);
                break;
            case 'image_url': // MARKER-RENTAL-MODEL-PHOTOS
                $request->validate(['value' => ['nullable', 'string', 'max:500']]);
                $model->update(['image_url' => ($value === '' ? null : $value)]);
                break;
            case 'hourly_rate':
            case 'daily_rate':
            case 'weekend_rate':
            case 'seasonal_rate':
                $request->validate(['value' => ['nullable', 'numeric', 'min:0', 'max:99999']]);
                $model->update([str_replace('_rate', '_rate_cents', $field) => $this->dollarsToCents($value)]);
                break;
            case 'deposit':
                $request->validate(['value' => ['nullable', 'numeric', 'min:0', 'max:99999']]);
                $model->update(['deposit_cents' => $this->dollarsToCents($value) ?? 0]);
                break;
            case 'category_id':
                $request->validate(['value' => ['required', 'string', 'uuid']]);
                TenantRentalCategory::where('tenant_id', $tenant->id)
                    ->where('id', $value)->whereNull('archived_at')->firstOrFail();
                // Move the model AND its units together (units carry category_id too).
                DB::transaction(function () use ($model, $value) {
                    $model->update(['category_id' => $value]);
                    TenantRentalUnit::where('model_id', $model->id)->update(['category_id' => $value]);
                });
                break;
            case 'condition_template_id':
                $model->update(['condition_template_id' => $this->verifyTemplate($tenant->id, $value)]);
                break;
            default:
                return response()->json(['success' => false, 'message' => 'Unknown field.'], 422);
        }
        return response()->json(['success' => true]);
    }

    public function destroyModel(Request $request, string $id)
    {
        $tenant = tenant();
        $model = TenantRentalModel::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $activeUnits = TenantRentalUnit::where('model_id', $model->id)
            ->whereNull('archived_at')->where('status', '!=', 'retired')->count();
        if ($activeUnits > 0) {
            return response()->json(['success' => false,
                'message' => "Archive the {$activeUnits} unit(s) under this model first."], 422);
        }

        $model->update(['archived_at' => now()]);
        return response()->json(['success' => true]);
    }

    // ----------------------------------------------------------------- units
    public function storeUnit(Request $request)
    {
        $tenant = tenant();
        $request->validate([
            'model_id'   => ['required', 'string', 'uuid'],
            'identifier' => ['nullable', 'string', 'max:60'],
            'size'       => ['nullable', 'string', 'max:40'],
        ]);

        $model = TenantRentalModel::where('tenant_id', $tenant->id)
            ->where('id', $request->input('model_id'))->whereNull('archived_at')->firstOrFail();

        TenantRentalUnit::create([
            'tenant_id'   => $tenant->id,
            'location_id' => $request->session()->get('current_location_id'),
            'category_id' => $model->category_id,
            'model_id'    => $model->id,
            'name'        => $model->name,
            'identifier'  => $request->input('identifier') ?: null,
            'size'        => $request->input('size') ?: null,
            'status'      => 'available',
            'available_for_rent' => true,
            'online_booking'     => true,
            'buffer_minutes'     => 0,
        ]);

        return redirect()->route('tenant.rentals.fleet')->with('flash', 'Unit added.');
    }

    /** Bulk add N units to a model, auto-tagging serials from a prefix. */
    public function bulkAddUnits(Request $request)
    {
        $tenant = tenant();
        $request->validate([
            'model_id'    => ['required', 'string', 'uuid'],
            'count'       => ['required', 'integer', 'min:1', 'max:200'],
            'tag_prefix'  => ['nullable', 'string', 'max:40'],
            'start_number'=> ['nullable', 'integer', 'min:0', 'max:100000'],
            'size'        => ['nullable', 'string', 'max:40'],
        ]);

        $model = TenantRentalModel::where('tenant_id', $tenant->id)
            ->where('id', $request->input('model_id'))->whereNull('archived_at')->firstOrFail();

        $count  = (int) $request->input('count');
        $prefix = trim((string) $request->input('tag_prefix'));
        $start  = (int) ($request->input('start_number') ?? 1);
        $size   = $request->input('size') ?: null;
        $locId  = $request->session()->get('current_location_id');

        DB::transaction(function () use ($tenant, $model, $count, $prefix, $start, $size, $locId) {
            $rows = [];
            for ($i = 0; $i < $count; $i++) {
                $tag = $prefix !== '' ? $prefix . str_pad((string) ($start + $i), 2, '0', STR_PAD_LEFT) : null;
                $rows[] = [
                    'id'                 => (string) Str::uuid(),
                    'tenant_id'          => $tenant->id,
                    'location_id'        => $locId,
                    'category_id'        => $model->category_id,
                    'model_id'           => $model->id,
                    'name'               => $model->name,
                    'identifier'         => $tag,
                    'size'               => $size,
                    'status'             => 'available',
                    'available_for_rent' => true,
                    'online_booking'     => true,
                    'buffer_minutes'     => 0,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ];
            }
            // chunked insert keeps a 200-row bulk add to a couple queries.
            foreach (array_chunk($rows, 50) as $chunk) {
                DB::table('tenant_rental_units')->insert($chunk);
            }
        });

        return redirect()->route('tenant.rentals.fleet')
            ->with('flash', "Added {$count} units to {$model->name}.");
    }

    public function updateUnit(Request $request, string $id)
    {
        $tenant = tenant();
        $unit = TenantRentalUnit::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        [$field, $value] = $this->fieldValue($request);

        // MARKER-PATCH-227 — rates/deposit/checklist are MODEL fields now;
        // units only carry per-instance attributes.
        switch ($field) {
            case 'identifier':
                $request->validate(['value' => ['nullable', 'string', 'max:60']]);
                $unit->update(['identifier' => ($value === '' ? null : $value)]);
                break;
            case 'size':
                $request->validate(['value' => ['nullable', 'string', 'max:40']]);
                $unit->update(['size' => ($value === '' ? null : $value)]);
                break;
            case 'status':
                $request->validate(['value' => ['required', 'in:available,maintenance,retired']]);
                $unit->update(['status' => $value]);
                break;
            case 'available_for_rent':
            case 'online_booking':
                $unit->update([$field => (bool) ((int) $value)]);
                break;
            case 'buffer_minutes':
                $request->validate(['value' => ['nullable', 'integer', 'min:0', 'max:1440']]);
                $unit->update(['buffer_minutes' => (int) ($value ?: 0)]);
                break;
            default:
                return response()->json(['success' => false, 'message' => 'Unknown field (rates live on the model).'], 422);
        }
        return response()->json(['success' => true]);
    }

    public function destroyUnit(Request $request, string $id)
    {
        $tenant = tenant();
        $unit = TenantRentalUnit::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $active = TenantRental::whereIn('status', ['reserved', 'out'])
            ->whereHas('lines', fn ($q) => $q->where('unit_id', $unit->id))->count();
        if ($active > 0) {
            return response()->json(['success' => false,
                'message' => 'This unit has reserved or out rentals. Return or cancel them first.'], 422);
        }

        $unit->update(['archived_at' => now()]);
        return response()->json(['success' => true]);
    }

    // ------------------------------------------------- condition templates
    public function storeConditionTemplate(Request $request)
    {
        $tenant = tenant();
        $request->validate(['name' => ['required', 'string', 'max:120'], 'items' => ['required', 'string', 'max:4000']]);
        TenantRentalConditionTemplate::create([
            'tenant_id' => $tenant->id,
            'name'      => $request->input('name'),
            'items'     => $this->linesToItems($request->input('items')),
        ]);
        return redirect()->route('tenant.rentals.fleet')->with('flash', 'Checklist added.');
    }

    public function updateConditionTemplate(Request $request, string $id)
    {
        $tenant = tenant();
        $template = TenantRentalConditionTemplate::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        [$field, $value] = $this->fieldValue($request);
        switch ($field) {
            case 'name':
                $request->validate(['value' => ['required', 'string', 'max:120']]);
                $template->update(['name' => $value]);
                break;
            case 'items':
                $request->validate(['value' => ['required', 'string', 'max:4000']]);
                $template->update(['items' => $this->linesToItems($value)]);
                break;
            default:
                return response()->json(['success' => false, 'message' => 'Unknown field.'], 422);
        }
        return response()->json(['success' => true]);
    }

    public function destroyConditionTemplate(Request $request, string $id)
    {
        $tenant = tenant();
        $template = TenantRentalConditionTemplate::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $template->delete();
        return response()->json(['success' => true]);
    }

    // ----------------------------------------------------------- internals
    private function verifyTemplate(string $tenantId, $value): ?string
    {
        if ($value === '' || $value === null) {
            return null;
        }
        TenantRentalConditionTemplate::where('tenant_id', $tenantId)->where('id', $value)->firstOrFail();
        return $value;
    }

    private function fieldValue(Request $request): array
    {
        $request->validate(['field' => ['required', 'string', 'max:64']]);
        return [$request->input('field'), $request->input('value')];
    }

    private function dollarsToCents($v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        return (int) round(((float) $v) * 100);
    }

    private function linesToItems(string $raw): array
    {
        $items = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $label = trim($line);
            if ($label === '') {
                continue;
            }
            $items[] = ['key' => Str::slug(Str::limit($label, 40, '')), 'label' => Str::limit($label, 160, '')];
        }
        return $items;
    }
}
