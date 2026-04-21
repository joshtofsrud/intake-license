<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantWaitlistEntry;
use App\Models\Tenant\TenantWaitlistSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WaitlistPublicController extends Controller
{
    /**
     * GET /waitlist/join?service=SERVICE_ID  — form to join waitlist for a service.
     */
    public function join(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant->hasWaitlistFeature(), 404);

        $settings = TenantWaitlistSettings::forTenant($tenant);
        abort_unless($settings->enabled, 404);

        $serviceId = $request->query('service');
        $service = null;
        if ($serviceId) {
            $service = TenantServiceItem::where('tenant_id', $tenant->id)
                ->where('id', $serviceId)
                ->where('is_active', true)
                ->first();
        }

        $services = TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return view('public.waitlist.join', [
            'tenant'          => $tenant,
            'preselectedService' => $service,
            'services'        => $services,
            'pageTitle'       => 'Join the waitlist',
        ]);
    }

    /**
     * POST /waitlist/join  — create a waitlist entry.
     */
    public function submitJoin(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant->hasWaitlistFeature(), 404);

        $settings = TenantWaitlistSettings::forTenant($tenant);
        abort_unless($settings->enabled, 404);

        $validated = $request->validate([
            'service_item_id'      => 'required|uuid',
            'first_name'           => 'required|string|max:80',
            'last_name'            => 'required|string|max:80',
            'email'                => 'required|email|max:180',
            'phone'                => 'nullable|string|max:32',
            'date_range_start'     => 'required|date',
            'date_range_end'       => 'required|date|after_or_equal:date_range_start',
            'preferred_days'       => 'nullable|array',
            'preferred_days.*'     => 'integer|between:0,6',
            'preferred_time_start' => 'nullable|date_format:H:i',
            'preferred_time_end'   => 'nullable|date_format:H:i',
            'notes'                => 'nullable|string|max:500',
        ]);

        // Verify service belongs to tenant and is active
        $service = TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('id', $validated['service_item_id'])
            ->where('is_active', true)
            ->first();
        if (!$service) {
            return back()->withErrors(['service_item_id' => 'Service not available.'])->withInput();
        }

        // Find or create customer
        $customer = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('email', $validated['email'])
            ->first();
        if (!$customer) {
            $customer = TenantCustomer::create([
                'tenant_id'  => $tenant->id,
                'first_name' => $validated['first_name'],
                'last_name'  => $validated['last_name'],
                'email'      => $validated['email'],
                'phone'      => $validated['phone'] ?? null,
            ]);
        } else {
            // Update name/phone on existing customer record
            $customer->update([
                'first_name' => $validated['first_name'],
                'last_name'  => $validated['last_name'],
                'phone'      => $validated['phone'] ?? $customer->phone,
            ]);
        }

        // Enforce tenant-configured cap
        if ($settings->max_entries_per_customer !== null) {
            $active = TenantWaitlistEntry::where('tenant_id', $tenant->id)
                ->where('customer_id', $customer->id)
                ->where('status', 'active')
                ->count();
            if ($active >= $settings->max_entries_per_customer) {
                return back()
                    ->withErrors(['service_item_id' => 'You\'ve reached the limit of ' . $settings->max_entries_per_customer . ' active waitlist entries. Remove one to add another.'])
                    ->withInput();
            }
        }

        $entry = TenantWaitlistEntry::create([
            'tenant_id'            => $tenant->id,
            'customer_id'          => $customer->id,
            'service_item_id'      => $service->id,
            'addon_ids'            => [],
            'date_range_start'     => $validated['date_range_start'],
            'date_range_end'       => $validated['date_range_end'],
            'preferred_days'       => $validated['preferred_days'] ?? null,
            'preferred_time_start' => $validated['preferred_time_start'] ?? null,
            'preferred_time_end'   => $validated['preferred_time_end'] ?? null,
            'notes'                => $validated['notes'] ?? null,
            'status'               => 'active',
        ]);

        // Generate a lookup token for this customer so they can manage their entries
        $manageToken = base64_encode($customer->id);

        return redirect()->route('tenant.waitlist.my', ['token' => $manageToken])
            ->with('success', 'You\'ve been added to the waitlist. We\'ll notify you by email and SMS when a spot opens.');
    }

    /**
     * GET /waitlist/my?token=CUSTOMER_TOKEN  — customer's active entries.
     */
    public function myEntries(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant->hasWaitlistFeature(), 404);

        $token = $request->query('token');
        $customerId = $token ? base64_decode($token, true) : null;
        abort_unless($customerId, 404);

        $customer = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('id', $customerId)
            ->first();
        abort_unless($customer, 404);

        $entries = TenantWaitlistEntry::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['active', 'fulfilled'])
            ->with('serviceItem')
            ->orderByDesc('created_at')
            ->get();

        return view('public.waitlist.my', [
            'tenant'   => $tenant,
            'customer' => $customer,
            'entries'  => $entries,
            'token'    => $token,
            'pageTitle' => 'Your waitlist',
        ]);
    }

    /**
     * POST /waitlist/remove  — customer cancels one of their entries.
     */
    public function removeEntry(Request $request)
    {
        $tenant = tenant();
        $validated = $request->validate([
            'entry_id' => 'required|uuid',
            'token'    => 'required|string',
        ]);

        $customerId = base64_decode($validated['token'], true);
        abort_unless($customerId, 404);

        $entry = TenantWaitlistEntry::where('tenant_id', $tenant->id)
            ->where('id', $validated['entry_id'])
            ->where('customer_id', $customerId)
            ->first();
        abort_unless($entry, 404);

        $entry->update(['status' => 'cancelled_by_customer']);

        return redirect()->route('tenant.waitlist.my', ['token' => $validated['token']])
            ->with('success', 'Waitlist entry removed.');
    }
}
