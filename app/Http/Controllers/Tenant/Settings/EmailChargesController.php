<?php
// MARKER-EMAIL-BILLING

namespace App\Http\Controllers\Tenant\Settings;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantEmailLedgerEntry;
use App\Services\EmailLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmailChargesController extends Controller
{
    private function guardManager()
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            abort(redirect()->route('tenant.settings.index'));
        }
        return $me;
    }

    public function index()
    {
        $this->guardManager();
        $tenant = tenant();

        $mtd = EmailLedger::monthToDate($tenant->id);
        $cap = EmailLedger::capState($tenant);

        // Recent campaign spend, grouped by campaign — the lines a shop
        // would want to see behind the number.
        $campaigns = TenantEmailLedgerEntry::where('tenant_id', $tenant->id)
            ->where('kind', 'campaign')
            ->where('status', TenantEmailLedgerEntry::STATUS_SENT)
            ->whereNotNull('campaign_id')
            ->where('created_at', '>=', $mtd['since'])
            ->selectRaw('campaign_id, COUNT(*) as n, SUM(rate) as spend, MAX(created_at) as last_at')
            ->groupBy('campaign_id')
            ->orderByDesc('last_at')
            ->limit(20)
            ->get();

        $names = \App\Models\Tenant\TenantCampaign::whereIn('id', $campaigns->pluck('campaign_id'))
            ->pluck('name', 'id');

        return view('tenant.settings.email-charges', [
            'pageTitle' => 'Email charges',
            'mtd'       => $mtd,
            'cap'       => $cap,
            'rate'      => EmailLedger::rate(),
            'campaigns' => $campaigns,
            'names'     => $names,
        ]);
    }

    public function updateCap(Request $request)
    {
        $this->guardManager();
        $tenant = tenant();

        $data = $request->validate([
            'cap_enabled' => ['nullable'],
            'cap_dollars' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        $enabled = (bool) ($data['cap_enabled'] ?? false);

        $tenant->update([
            'email_spend_cap_cents' => $enabled && isset($data['cap_dollars'])
                ? (int) round(((float) $data['cap_dollars']) * 100)
                : null,
        ]);

        return back()->with('success', $enabled
            ? 'Monthly marketing limit saved. Receipts and confirmations are never affected by it.'
            : 'Monthly marketing limit removed.');
    }
}
