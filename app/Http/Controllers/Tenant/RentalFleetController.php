<?php
// MARKER-PATCH-218

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantRental;
use App\Models\Tenant\TenantRentalCategory;
use App\Models\Tenant\TenantRentalConditionTemplate;
use App\Models\Tenant\TenantRentalUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Fleet admin: categories (rate cards), units, condition templates.
 *
 * Inline-edit protocol (matches the resources page idiom): the row sends
 * PATCH {field, value}; fields are whitelisted per entity; money fields
 * arrive in DOLLARS and are stored as cents. Destroys are archives —
 * history (rentals, ledger rows) is never orphaned.
 */
class RentalFleetController extends Controller
{
    public function index()
    {
        $tenant = tenant();

        $categories = TenantRentalCategory::where('tenant_id', $tenant->id)
            ->whereNull('archived_at')
            ->withCount(['units as units_count' => fn ($q) => $q->whereNull('archived_at')->where('status', '!=', 'retired')])
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        $units = TenantRentalUnit::where('tenant_id', $tenant->id)
            ->whereNull('archived_at')
            ->with('category:id,name')
            ->orderBy('name')
            ->get();

        $conditionTemplates = TenantRentalConditionTemplate::where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get();

        return view('tenant.rentals.fleet', compact('categories', 'units', 'conditionTemplates'));
    }

    // ------------------------------------------------------------ categories
    public function storeCategory(Request $request)
    {
        $tenant = tenant();

        $request->validate([
            'name'         => ['required', 'string', 'max:120'],
            'hourly_rate'  => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'daily_rate'   => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'weekend_rate' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'deposit'      => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ]);

        $maxSort = TenantRentalCategory::where('tenant_id', $tenant->id)->max('sort_order') ?? 90;

        TenantRentalCategory::create([
            'tenant_id'          => $tenant->id,
            'name'               => $request->input('name'),
            'hourly_rate_cents'  => $this->dollarsToCents($request->input('hourly_rate')),
            'daily_rate_cents'   => $this->dollarsToCents($request->input('daily_rate')),
            'weekend_rate_cents' => $this->dollarsToCents($request->input('weekend_rate')),
            'deposit_cents'      => $this->dollarsToCents($request->input('deposit')) ?? 0,
            'sort_order'         => $maxSort + 10,
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
            case 'hourly_rate':
            case 'daily_rate':
            case 'weekend_rate':
                $request->validate(['value' => ['nullable', 'numeric', 'min:0', 'max:99999']]);
                $category->update([str_replace('_rate', '_rate_cents', $field) => $this->dollarsToCents($value)]);
                break;
            case 'deposit':
                $request->validate(['value' => ['nullable', 'numeric', 'min:0', 'max:99999']]);
                $category->update(['deposit_cents' => $this->dollarsToCents($value) ?? 0]);
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
            ->whereNull('archived_at')
            ->where('status', '!=', 'retired')
            ->count();

        if ($activeUnits > 0) {
            return response()->json([
                'success' => false,
                'message' => "Move or retire the {$activeUnits} unit(s) in this category first.",
            ], 422);
        }

        $category->update(['archived_at' => now()]);

        return response()->json(['success' => true]);
    }

    // ----------------------------------------------------------------- units
    public function storeUnit(Request $request)
    {
        $tenant = tenant();

        $request->validate([
            'name'        => ['required', 'string', 'max:160'],
            'category_id' => ['required', 'string', 'uuid'],
            'identifier'  => ['nullable', 'string', 'max:60'],
            'size'        => ['nullable', 'string', 'max:40'],
        ]);

        // Ownership-verify the category (never trust a raw id).
        $category = TenantRentalCategory::where('tenant_id', $tenant->id)
            ->where('id', $request->input('category_id'))
            ->whereNull('archived_at')
            ->firstOrFail();

        TenantRentalUnit::create([
            'tenant_id'          => $tenant->id,
            'location_id'        => $request->session()->get('current_location_id'),
            'category_id'        => $category->id,
            'name'               => $request->input('name'),
            'identifier'         => $request->input('identifier'),
            'size'               => $request->input('size'),
            'status'             => 'available',
            'available_for_rent' => true,
            'online_booking'     => true,
            'buffer_minutes'     => 0,
        ]);

        return redirect()->route('tenant.rentals.fleet')->with('flash', 'Unit added.');
    }

    public function updateUnit(Request $request, string $id)
    {
        $tenant = tenant();
        $unit = TenantRentalUnit::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        [$field, $value] = $this->fieldValue($request);

        switch ($field) {
            case 'name':
                $request->validate(['value' => ['required', 'string', 'max:160']]);
                $unit->update(['name' => $value]);
                break;
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
            case 'category_id':
                $request->validate(['value' => ['required', 'string', 'uuid']]);
                TenantRentalCategory::where('tenant_id', $tenant->id)
                    ->where('id', $value)->whereNull('archived_at')->firstOrFail();
                $unit->update(['category_id' => $value]);
                break;
            case 'available_for_rent':
            case 'online_booking':
                $unit->update([$field => (bool) ((int) $value)]);
                break;
            case 'buffer_minutes':
                $request->validate(['value' => ['nullable', 'integer', 'min:0', 'max:1440']]);
                $unit->update(['buffer_minutes' => (int) ($value ?: 0)]);
                break;
            case 'hourly_rate_override':
            case 'daily_rate_override':
            case 'weekend_rate_override':
            case 'deposit_override':
                $request->validate(['value' => ['nullable', 'numeric', 'min:0', 'max:99999']]);
                // hourly_rate_override -> hourly_rate_cents_override, etc.;
                // deposit_override -> deposit_cents_override
                $col = $field === 'deposit_override'
                    ? 'deposit_cents_override'
                    : str_replace('_rate_override', '_rate_cents_override', $field);
                $unit->update([$col => $this->dollarsToCents($value)]);
                break;
            case 'condition_template_id':
                if ($value === '' || $value === null) {
                    $unit->update(['condition_template_id' => null]);
                    break;
                }
                $request->validate(['value' => ['string', 'uuid']]);
                TenantRentalConditionTemplate::where('tenant_id', $tenant->id)
                    ->where('id', $value)->firstOrFail();
                $unit->update(['condition_template_id' => $value]);
                break;
            default:
                return response()->json(['success' => false, 'message' => 'Unknown field.'], 422);
        }

        return response()->json(['success' => true]);
    }

    public function destroyUnit(Request $request, string $id)
    {
        $tenant = tenant();
        $unit = TenantRentalUnit::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $active = TenantRental::whereIn('status', ['reserved', 'out'])
            ->whereHas('lines', fn ($q) => $q->where('unit_id', $unit->id))
            ->count();

        if ($active > 0) {
            return response()->json([
                'success' => false,
                'message' => 'This unit has reserved or out rentals. Return or cancel them first.',
            ], 422);
        }

        $unit->update(['archived_at' => now()]);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------- condition templates
    public function storeConditionTemplate(Request $request)
    {
        $tenant = tenant();

        $request->validate([
            'name'  => ['required', 'string', 'max:120'],
            'items' => ['required', 'string', 'max:4000'],
        ]);

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

        // FK on units is nullOnDelete; past condition checks keep their
        // results JSON (snapshot) so history is intact.
        $template->delete();

        return response()->json(['success' => true]);
    }

    // ----------------------------------------------------------- internals
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

    /** One checklist item per line -> [{key, label}]. */
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
