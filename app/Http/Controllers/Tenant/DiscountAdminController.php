<?php
// MARKER-DISCOUNTS-ADMIN

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantDiscount;
use App\Models\Tenant\TenantDiscountRedemption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class DiscountAdminController extends Controller
{
    private function guardManager()
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            abort(redirect()->route('tenant.dashboard'));
        }
        return $me;
    }

    public function index()
    {
        $this->guardManager();
        $tenant = tenant();

        $discounts = TenantDiscount::where('tenant_id', $tenant->id)
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->get();

        // Money given away, all time and this month.
        $totals = TenantDiscountRedemption::where('tenant_id', $tenant->id)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount_cents),0) as cents')
            ->first();

        $monthTotals = TenantDiscountRedemption::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount_cents),0) as cents')
            ->first();

        // Per-code money given away, so the list can show it inline.
        $given = TenantDiscountRedemption::where('tenant_id', $tenant->id)
            ->selectRaw('discount_id, COUNT(*) as n, COALESCE(SUM(amount_cents),0) as cents')
            ->groupBy('discount_id')
            ->get()
            ->keyBy('discount_id');

        $recent = TenantDiscountRedemption::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        return view('tenant.discounts.index', [
            'pageTitle'   => 'Discounts',
            'discounts'   => $discounts,
            'given'       => $given,
            'totals'      => $totals,
            'monthTotals' => $monthTotals,
            'recent'      => $recent,
        ]);
    }

    public function store(Request $request)
    {
        $me     = $this->guardManager();
        $tenant = tenant();

        $data = $this->validated($request, null);

        TenantDiscount::create($data + [
            'tenant_id'  => $tenant->id,
            'created_by' => $me->id,
        ]);

        return back()->with('success', 'Discount code created.');
    }

    public function update(Request $request, string $id)
    {
        $this->guardManager();
        $discount = TenantDiscount::where('tenant_id', tenant()->id)->findOrFail($id);

        $discount->update($this->validated($request, $discount->id));

        return back()->with('success', 'Discount updated.');
    }

    public function toggle(string $id)
    {
        $this->guardManager();
        $discount = TenantDiscount::where('tenant_id', tenant()->id)->findOrFail($id);

        $discount->update(['is_active' => ! $discount->is_active]);

        return back()->with('success', $discount->is_active
            ? 'Code turned on — it can be used again.'
            : 'Code turned off. Past uses are unaffected.');
    }

    public function destroy(string $id)
    {
        $this->guardManager();
        $discount = TenantDiscount::where('tenant_id', tenant()->id)->findOrFail($id);

        $used = TenantDiscountRedemption::where('discount_id', $discount->id)->count();
        if ($used > 0) {
            return back()->with('error',
                'This code has been used ' . $used . ' time(s), so it stays as a record of money given away. Turn it off instead.');
        }

        $discount->delete();

        return back()->with('success', 'Discount deleted.');
    }

    /** Shared validation. $ignoreId lets an edit keep its own code. */
    private function validated(Request $request, ?string $ignoreId): array
    {
        $rules = [
            'code'  => [
                'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('tenant_discounts', 'code')
                    ->where(fn ($q) => $q->where('tenant_id', tenant()->id))
                    ->ignore($ignoreId),
            ],
            'label'              => ['nullable', 'string', 'max:120'],
            'type'               => ['required', Rule::in(['percent', 'fixed'])],
            'value_input'        => ['required', 'numeric', 'min:0.01'],
            'min_subtotal'       => ['nullable', 'numeric', 'min:0'],
            'max_discount'       => ['nullable', 'numeric', 'min:0'],
            'starts_at'          => ['nullable', 'date'],
            'ends_at'            => ['nullable', 'date', 'after_or_equal:starts_at'],
            'max_redemptions'    => ['nullable', 'integer', 'min:0'],
            'max_per_customer'   => ['nullable', 'integer', 'min:0'],
        ];

        $v = $request->validate($rules, [
            'code.regex'  => 'Codes can use letters, numbers, dots, dashes and underscores only.',
            'code.unique' => 'You already have a code with that name.',
        ]);

        // A percent is a whole number 1-100; a fixed amount is dollars -> cents.
        $value = $v['type'] === 'percent'
            ? (int) round((float) $v['value_input'])
            : (int) round(((float) $v['value_input']) * 100);

        if ($v['type'] === 'percent' && ($value < 1 || $value > 100)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'value_input' => 'A percentage must be between 1 and 100.',
            ]);
        }

        return [
            'code'               => strtoupper(trim($v['code'])),
            'label'              => $v['label'] ?? null,
            'type'               => $v['type'],
            'value'              => $value,
            'min_subtotal_cents' => (int) round(((float) ($v['min_subtotal'] ?? 0)) * 100),
            'max_discount_cents' => (int) round(((float) ($v['max_discount'] ?? 0)) * 100),
            'starts_at'          => $v['starts_at'] ?? null,
            'ends_at'            => $v['ends_at'] ?? null,
            'max_redemptions'    => (int) ($v['max_redemptions'] ?? 0),
            'max_per_customer'   => (int) ($v['max_per_customer'] ?? 0),
        ];
    }
}
