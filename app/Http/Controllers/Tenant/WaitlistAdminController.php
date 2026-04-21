<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantWaitlistEntry;
use App\Models\Tenant\TenantWaitlistOffer;
use App\Models\Tenant\TenantWaitlistSettings;
use App\Models\Tenant\TenantWaitlistSimilarMap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WaitlistAdminController extends Controller
{
    public function index(Request $request)
    {
        $tenant   = tenant();
        $settings = TenantWaitlistSettings::forTenant($tenant);

        $entries = TenantWaitlistEntry::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'fulfilled'])
            ->with(['customer', 'serviceItem', 'offers' => function ($q) {
                $q->orderByDesc('created_at')->limit(5);
            }])
            ->orderByDesc('created_at')
            ->get();

        return view('tenant.waitlist.index', [
            'tenant'     => $tenant,
            'settings'   => $settings,
            'entries'    => $entries,
            'pageTitle'  => 'Waitlist',
        ]);
    }

    public function settings(Request $request)
    {
        $tenant   = tenant();
        $settings = TenantWaitlistSettings::forTenant($tenant);
        $services = TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
        $similarMap = TenantWaitlistSimilarMap::where('tenant_id', $tenant->id)
            ->with(['serviceItem', 'substitutableServiceItem'])
            ->get()
            ->groupBy('service_item_id');

        return view('tenant.waitlist.settings', [
            'tenant'     => $tenant,
            'settings'   => $settings,
            'services'   => $services,
            'similarMap' => $similarMap,
            'pageTitle'  => 'Waitlist settings',
        ]);
    }

    public function updateSettings(Request $request)
    {
        $tenant   = tenant();
        $settings = TenantWaitlistSettings::forTenant($tenant);

        $validated = $request->validate([
            'enabled'                      => 'nullable|boolean',
            'similar_match_rule'           => 'required|in:exact_only,by_duration,by_category,by_tenant_mapping',
            'exclude_first_time_customers' => 'nullable|boolean',
            'include_cancellations'        => 'nullable|boolean',
            'include_manual_offers'        => 'nullable|boolean',
            'notify_sms'                   => 'nullable|boolean',
            'notify_email'                 => 'nullable|boolean',
            'max_entries_per_customer'     => 'nullable|integer|min:1|max:100',
            'offer_copy_override'          => 'nullable|string|max:2000',
        ]);

        $settings->update([
            'enabled'                      => (bool) ($validated['enabled'] ?? false),
            'similar_match_rule'           => $validated['similar_match_rule'],
            'exclude_first_time_customers' => (bool) ($validated['exclude_first_time_customers'] ?? false),
            'include_cancellations'        => (bool) ($validated['include_cancellations'] ?? false),
            'include_manual_offers'        => (bool) ($validated['include_manual_offers'] ?? false),
            'notify_sms'                   => (bool) ($validated['notify_sms'] ?? false),
            'notify_email'                 => (bool) ($validated['notify_email'] ?? false),
            'max_entries_per_customer'     => $validated['max_entries_per_customer'] ?? null,
            'offer_copy_override'          => $validated['offer_copy_override'] ?? null,
        ]);

        return redirect()->route('tenant.waitlist.settings')
            ->with('success', 'Settings saved.');
    }

    public function addSimilarMapping(Request $request)
    {
        $tenant = tenant();
        $validated = $request->validate([
            'service_item_id'                => 'required|uuid',
            'substitutable_service_item_id'  => 'required|uuid|different:service_item_id',
        ]);

        // Verify both services belong to tenant
        $count = TenantServiceItem::where('tenant_id', $tenant->id)
            ->whereIn('id', [$validated['service_item_id'], $validated['substitutable_service_item_id']])
            ->count();
        abort_unless($count === 2, 422, 'Invalid service reference.');

        TenantWaitlistSimilarMap::firstOrCreate([
            'tenant_id'                     => $tenant->id,
            'service_item_id'               => $validated['service_item_id'],
            'substitutable_service_item_id' => $validated['substitutable_service_item_id'],
        ]);

        return back()->with('success', 'Similar-service mapping added.');
    }

    public function removeSimilarMapping(Request $request, string $id)
    {
        $tenant = tenant();
        TenantWaitlistSimilarMap::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->delete();
        return back()->with('success', 'Mapping removed.');
    }

    public function cancelEntry(Request $request, string $id)
    {
        $tenant = tenant();
        $entry = TenantWaitlistEntry::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->first();
        abort_unless($entry, 404);

        $entry->update(['status' => 'cancelled_by_tenant']);
        return back()->with('success', 'Entry removed from waitlist.');
    }
}
