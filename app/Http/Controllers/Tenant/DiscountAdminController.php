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

        // MARKER-PROMO-TAGS — tag names per code, and how many redemptions
        // had no customer (those can never be tagged; say so).
        $tagSvc      = app(\App\Services\Tenant\DiscountTagService::class);
        $tagNames    = $tagSvc->namesByDiscount($tenant->id);
        $anonByCode  = TenantDiscountRedemption::where('tenant_id', $tenant->id)
            ->whereNull('customer_id')->selectRaw('discount_id, COUNT(*) as n')
            ->groupBy('discount_id')->pluck('n', 'discount_id')->all();

        return view('tenant.discounts.index', [
            'pageTitle'   => 'Discounts',
            'tagNames'    => $tagNames,
            'anonByCode'  => $anonByCode,
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

        $discount = TenantDiscount::create($data + [
            'tenant_id'  => $tenant->id,
            'created_by' => $me->id,
        ]);

        // MARKER-PROMO-TAGS — optional on create; a comma-separated list.
        $tags = trim((string) $request->input('tags', ''));
        if ($tags !== '') {
            app(\App\Services\Tenant\DiscountTagService::class)->setTags($discount, $tags, $me->id);
        }

        return back()->with('success', 'Discount code created.');
    }

    /**
     * MARKER-PROMO-TAGS — change the tags on an existing code. Adding a tag
     * backfills everyone who already redeemed it; removing one only stops
     * future tagging (past customers keep it — it was true when applied).
     */
    public function tags(Request $request, string $id)
    {
        $me = $this->guardManager();
        $discount = TenantDiscount::where('tenant_id', tenant()->id)->findOrFail($id);

        $v = $request->validate(['tags' => ['nullable', 'string', 'max:600']]);
        $ids = app(\App\Services\Tenant\DiscountTagService::class)->setTags($discount, (string) ($v['tags'] ?? ''), $me->id);

        return back()->with('success', $ids ? 'Tags saved. Past redeemers were tagged too.' : 'Tags cleared.');
    }

    /**
     * MARKER-PROMO-TAGS — "Email these customers": a draft campaign whose
     * audience is everyone tagged by this code's FIRST tag, straight into
     * the composer. One tag, not all of them, so the audience is exactly
     * "used this code" and not the shared promo tag's wider set.
     */
    public function campaign(string $id)
    {
        $me = $this->guardManager();
        $tenant   = tenant();
        $discount = TenantDiscount::where('tenant_id', $tenant->id)->findOrFail($id);

        $tagIds = app(\App\Services\Tenant\DiscountTagService::class)->tagIdsFor($discount->id);
        if (! $tagIds) {
            return back()->with('error', 'Give this code a tag first — that\'s what the campaign targets.');
        }

        $campaign = \App\Models\Tenant\TenantCampaign::create([
            'tenant_id'  => $tenant->id,
            'name'       => 'Customers who used ' . $discount->code,
            'type'       => 'bulk',
            'status'     => 'draft',
            'subject'    => '',
            'body_html'  => '',
            'blocks'     => [],
            'targeting'  => ['mode' => 'rules', 'rules' => [['field' => 'tag', 'op' => 'is', 'value' => $tagIds[0]]]],
            'created_by' => $me->id,
        ]);

        return redirect()->route('tenant.campaigns.show', ['id' => $campaign->id])
            ->with('success', 'Campaign created for everyone who used ' . $discount->code . '. Compose your message below.');
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
