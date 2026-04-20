<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCampaign;
use App\Models\Tenant\TenantCampaignSend;
use App\Models\Tenant\TenantCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CampaignController extends Controller
{
    // ----------------------------------------------------------------
    // Index — list campaigns grouped by status
    // ----------------------------------------------------------------
    public function index()
    {
        $tenant = tenant();

        $campaigns = TenantCampaign::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->get();

        $groups = [
            'draft'     => $campaigns->whereIn('status', ['draft'])->values(),
            'scheduled' => $campaigns->whereIn('status', ['scheduled', 'sending'])->values(),
            'sent'      => $campaigns->whereIn('status', ['sent'])->values(),
        ];

        $customerCount = TenantCustomer::where('tenant_id', $tenant->id)->count();

        return view('tenant.campaigns.index', compact('campaigns', 'groups', 'customerCount'));
    }

    // ----------------------------------------------------------------
    // Show — view/edit a single campaign
    // ----------------------------------------------------------------
    public function show(Request $request, string $subdomain, string $id)
    {
        $tenant = tenant();

        $campaign = TenantCampaign::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $customerCount = TenantCustomer::where('tenant_id', $tenant->id)->count();

        // Segment options for bulk sends
        $segments = [
            'all'             => "All customers ({$customerCount})",
            'has_appointment' => 'Customers with at least one appointment',
        ];

        return view('tenant.campaigns.show', compact('campaign', 'customerCount', 'segments'));
    }

    // ----------------------------------------------------------------
    // Store — create a new campaign
    // ----------------------------------------------------------------
    public function store(Request $request, string $subdomain)
    {
        $tenant = tenant();

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return back()->with('error', 'Campaign name is required.');
        }

        $campaign = TenantCampaign::create([
            'tenant_id'  => $tenant->id,
            'name'       => $name,
            'type'       => 'bulk',
            'status'     => 'draft',
            'subject'    => '',
            'body_html'  => '',
            'targeting'  => ['segment' => 'all'],
            'created_by' => auth('tenant')->id(),
        ]);

        return redirect()
            ->route('tenant.campaigns.show', ['id' => $campaign->id])
            ->with('success', 'Campaign created. Now compose your message.');
    }

    // ----------------------------------------------------------------
    // Update — save campaign edits
    // ----------------------------------------------------------------
    public function update(Request $request, string $subdomain, string $id)
    {
        $tenant = tenant();

        $campaign = TenantCampaign::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        if (! in_array($campaign->status, ['draft', 'scheduled', 'paused'])) {
            return back()->with('error', 'Cannot edit a campaign that has already been sent.');
        }

        $name    = trim((string) $request->input('name', ''));
        $subject = trim((string) $request->input('subject', ''));
        $body    = (string) $request->input('body', '');
        $segment = $request->input('segment', 'all');

        if ($name === '' || $subject === '' || $body === '') {
            return back()
                ->with('error', 'Name, subject, and body are required.')
                ->withInput();
        }

        $campaign->update([
            'name'      => $name,
            'subject'   => $subject,
            'body_html' => $body,
            'targeting' => ['segment' => $segment],
        ]);

        return back()->with('success', 'Campaign saved.');
    }

    // ----------------------------------------------------------------
    // Send — dispatch campaign to audience (queues send rows)
    // ----------------------------------------------------------------
    public function send(Request $request, string $subdomain, string $id)
    {
        $tenant = tenant();

        $campaign = TenantCampaign::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        if ($campaign->status !== 'draft') {
            return back()->with('error', 'This campaign has already been sent or is in progress.');
        }

        if (trim($campaign->subject) === '' || trim($campaign->body_html) === '') {
            return back()->with('error', 'Please add a subject and body before sending.');
        }

        // Resolve audience based on segment
        $segment = $campaign->targeting['segment'] ?? 'all';
        $query   = TenantCustomer::where('tenant_id', $tenant->id)
                                 ->whereNotNull('email')
                                 ->where('email', '!=', '');

        if ($segment === 'has_appointment') {
            $query->whereHas('appointments');
        }

        $customers = $query->get(['id', 'email']);

        if ($customers->isEmpty()) {
            return back()->with('error', 'No recipients match this segment.');
        }

        // Create send rows (pending). Actual dispatch happens via queue worker.
        foreach ($customers as $customer) {
            TenantCampaignSend::create([
                'campaign_id'     => $campaign->id,
                'customer_id'     => $customer->id,
                'email'           => $customer->email,
                'status'          => 'pending',
                'tracking_token'  => Str::random(32),
                'created_at'      => now(),
            ]);
        }

        $campaign->update([
            'status'           => 'sending',
            'total_recipients' => $customers->count(),
            'sent_at'          => now(),
        ]);

        return back()->with('success', "Campaign queued for {$customers->count()} recipient(s). Sending will complete shortly.");
    }
}
