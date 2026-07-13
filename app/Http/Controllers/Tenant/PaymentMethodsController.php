<?php
// MARKER-PATCH-629 — manage the unified payment methods list (Settings → Payments).

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentMethodsController extends Controller
{
    private const SURFACES = ['register', 'online', 'booking', 'rental'];

    public function update(Request $request, string $methodId)
    {
        $tenant = tenant();
        $m = TenantPaymentMethod::where('tenant_id', $tenant->id)->where('id', $methodId)->firstOrFail();

        $data = $request->validate([
            'name'         => ['sometimes', 'string', 'max:80'],
            'handle'       => ['nullable', 'string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:300'],
            'mode'         => ['nullable', 'in:manual,stripe'],
        ]);

        $surfaces = [];
        foreach (self::SURFACES as $sf) {
            $surfaces[$sf] = [
                'on'   => (bool) $request->input("surface_{$sf}"),
                'hint' => mb_substr(trim((string) $request->input("hint_{$sf}", '')), 0, 60),
            ];
        }

        // MARKER-PATCH-636 — QB deposit account mapping
        $qb = $m->qb ?? [];
        $qb['deposit_account'] = trim((string) $request->input('qb_deposit_account', '')) ?: null;

        $m->update([
            'qb'           => $qb,
            'name'         => $m->is_custom ? ($data['name'] ?? $m->name) : $m->name,
            'enabled'      => (bool) $request->input('enabled'),
            'handle'       => $data['handle'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'mode'         => $m->method_key === 'cash_app' ? ($data['mode'] ?? 'manual') : $m->mode,
            'link_qr'      => (bool) $request->input('link_qr', $m->link_qr),
            'surfaces'     => $surfaces,
        ]);

        TenantPaymentMethod::syncLegacyKeys($tenant);

        return back()->with('success', $m->name . ' updated.')->withFragment('payments');
    }

    public function storeCustom(Request $request)
    {
        $tenant = tenant();
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:80'],
            'instructions' => ['nullable', 'string', 'max:300'],
        ]);

        $key = 'custom_' . Str::slug($data['name'], '_');
        if (TenantPaymentMethod::where('tenant_id', $tenant->id)->where('method_key', $key)->exists()) {
            return back()->with('error', 'A method with that name already exists.')->withFragment('payments');
        }

        $maxSort = (int) TenantPaymentMethod::where('tenant_id', $tenant->id)->max('sort');
        TenantPaymentMethod::create([
            'tenant_id'    => $tenant->id,
            'method_key'   => $key,
            'name'         => trim($data['name']),
            'kind'         => 'manual',
            'is_custom'    => true,
            'enabled'      => true,
            'instructions' => $data['instructions'] ?? null,
            'surfaces'     => [
                'register' => ['on' => true,  'hint' => 'Staff confirmed'],
                'online'   => ['on' => false, 'hint' => ''],
                'booking'  => ['on' => false, 'hint' => ''],
                'rental'   => ['on' => false, 'hint' => ''],
            ],
            'sort'         => $maxSort + 10,
        ]);

        return back()->with('success', '"' . $data['name'] . '" added — configure where it shows.')->withFragment('payments');
    }

    /** MARKER-PATCH-636 — global QB credit accounts (income / tax / tips). */
    public function saveQbAccounts(Request $request)
    {
        $tenant = tenant();
        $data = $request->validate([
            'qb_income_account' => ['nullable', 'string', 'max:120'],
            'qb_tax_account'    => ['nullable', 'string', 'max:120'],
            'qb_tips_account'   => ['nullable', 'string', 'max:120'],
        ]);
        $settings = $tenant->settings ?? [];
        foreach (['qb_income_account', 'qb_tax_account', 'qb_tips_account'] as $k) {
            $settings[$k] = trim((string) ($data[$k] ?? '')) ?: null;
        }
        $tenant->update(['settings' => $settings]);
        return back()->with('success', 'QuickBooks accounts saved.')->withFragment('payments');
    }

    public function destroyCustom(Request $request, string $methodId)
    {
        $tenant = tenant();
        $m = TenantPaymentMethod::where('tenant_id', $tenant->id)->where('id', $methodId)->firstOrFail();
        if (! $m->is_custom) {
            return back()->with('error', 'Built-in methods can be disabled but not deleted.')->withFragment('payments');
        }
        $m->delete();
        return back()->with('success', 'Method removed.')->withFragment('payments');
    }
}

