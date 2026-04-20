<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCampaign;
use App\Models\Tenant\TenantCampaignSend;
use App\Models\Tenant\TenantCustomer;
use App\Support\BlockRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CampaignController extends Controller
{
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

    public function show(Request $request, string $subdomain, string $id)
    {
        $tenant = tenant();

        $campaign = TenantCampaign::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $customerCount = TenantCustomer::where('tenant_id', $tenant->id)->count();

        $segments = [
            'all'             => "All customers ({$customerCount})",
            'has_appointment' => 'Customers with at least one appointment',
        ];

        // If blocks are empty, seed with a single paragraph so the composer starts useful
        $blocks = $campaign->blocks ?? [];
        if (empty($blocks)) {
            $blocks = [
                [
                    'id'   => (string) Str::uuid(),
                    'type' => 'paragraph',
                    'data' => ['text' => '', 'align' => 'left'],
                ],
            ];
        }

        return view('tenant.campaigns.show', compact('campaign', 'customerCount', 'segments', 'blocks'));
    }

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
            'blocks'     => [],
            'targeting'  => ['segment' => 'all'],
            'created_by' => auth('tenant')->id(),
        ]);

        return redirect()
            ->route('tenant.campaigns.show', ['id' => $campaign->id])
            ->with('success', 'Campaign created. Compose your message below.');
    }

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
        $segment = $request->input('segment', 'all');

        // Blocks come in as JSON string from the hidden form field
        $blocksJson = (string) $request->input('blocks_json', '[]');
        \Log::info('CAMPAIGN_SAVE: raw input', [
            'blocks_json_length' => strlen($blocksJson),
            'blocks_json_preview' => substr($blocksJson, 0, 500),
            'name' => $request->input('name'),
            'subject' => $request->input('subject'),
        ]);
        $blocks     = json_decode($blocksJson, true);
        if (! is_array($blocks)) {
            $blocks = [];
        }
        \Log::info('CAMPAIGN_SAVE: decoded', ['count' => count($blocks), 'blocks' => $blocks]);
        $blocks = self::sanitizeBlocks($blocks);
        \Log::info('CAMPAIGN_SAVE: sanitized', ['count' => count($blocks), 'blocks' => $blocks]);

        if ($name === '' || $subject === '') {
            return back()
                ->with('error', 'Name and subject are required.')
                ->withInput();
        }

        // Render blocks to final HTML once at save time (also used by future send worker)
        $bodyHtml = BlockRenderer::render($blocks, [], [
            'accent'     => $tenant->accent_color ?? '#BEF264',
            'accentText' => '#0a0a0a',
        ]);

        $campaign->update([
            'name'      => $name,
            'subject'   => $subject,
            'blocks'    => $blocks,
            'body_html' => $bodyHtml,
            'targeting' => ['segment' => $segment],
        ]);

        return back()->with('success', 'Campaign saved.');
    }

    public function send(Request $request, string $subdomain, string $id)
    {
        $tenant = tenant();

        $campaign = TenantCampaign::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        if ($campaign->status !== 'draft') {
            return back()->with('error', 'This campaign has already been sent or is in progress.');
        }

        if (trim($campaign->subject) === '' || empty($campaign->blocks)) {
            return back()->with('error', 'Please add a subject and at least one content block before sending.');
        }

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

        foreach ($customers as $customer) {
            TenantCampaignSend::create([
                'campaign_id'    => $campaign->id,
                'customer_id'    => $customer->id,
                'email'          => $customer->email,
                'status'         => 'pending',
                'tracking_token' => Str::random(32),
                'created_at'     => now(),
            ]);
        }

        $campaign->update([
            'status'           => 'sending',
            'total_recipients' => $customers->count(),
            'sent_at'          => now(),
        ]);

        return back()->with('success', "Campaign queued for {$customers->count()} recipient(s). Sending will complete shortly.");
    }

    /**
     * Live preview endpoint — takes blocks JSON, returns rendered HTML.
     * Used by the composer iframe for real-time preview without a full save.
     */
    public function preview(Request $request, string $subdomain, string $id)
    {
        $tenant = tenant();

        $campaign = TenantCampaign::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $blocks = $request->input('blocks', []);
        if (! is_array($blocks)) {
            $blocks = [];
        }
        $blocks = self::sanitizeBlocks($blocks);

        $html = BlockRenderer::render($blocks, BlockRenderer::SAMPLE_VARS, [
            'accent'     => $tenant->accent_color ?? '#BEF264',
            'accentText' => '#0a0a0a',
            'preview'    => true,
        ]);

        return response($html)->header('Content-Type', 'text/html');
    }

    /**
     * Whitelist block shapes so we don't persist garbage or XSS.
     * Unknown block types are dropped; unknown fields are dropped.
     */
    private static function sanitizeBlocks(array $blocks): array
    {
        $allowed = [
            'heading'   => ['text', 'size', 'align'],
            'paragraph' => ['text', 'align'],
            'image'     => ['url', 'alt'],
            'button'    => ['text', 'url', 'align'],
            'divider'   => [],
            'footer'    => ['text'],
        ];

        $clean = [];
        foreach ($blocks as $block) {
            if (! is_array($block) || empty($block['type']) || ! isset($allowed[$block['type']])) {
                continue;
            }
            $type = $block['type'];
            $data = [];
            foreach ($allowed[$type] as $field) {
                if (isset($block['data'][$field])) {
                    $data[$field] = is_string($block['data'][$field])
                        ? $block['data'][$field]
                        : (string) $block['data'][$field];
                }
            }
            $clean[] = [
                'id'   => $block['id'] ?? (string) Str::uuid(),
                'type' => $type,
                'data' => $data,
            ];
        }
        return $clean;
    }
}
