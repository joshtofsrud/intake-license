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

    public function show(Request $request, string $id)
    {
        $tenant = tenant();

        $campaign = TenantCampaign::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $customerCount = TenantCustomer::where('tenant_id', $tenant->id)->count();

        // MARKER-CAMPAIGN-DELIVERY — the number that matters is who can
        // legally be emailed, not who exists.
        $mailableCount = TenantCustomer::where('tenant_id', $tenant->id)->emailMailable()->count();

        $segments = [
            'all'             => "All customers with marketing permission ({$mailableCount} of {$customerCount})",
            'has_appointment' => 'Customers with an appointment (permission-holders only)',
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

        // MARKER-CAMPAIGN-ATTRIBUTION — codes worth offering: active, and not
        // already spent. An expired code in the dropdown is a trap.
        $discounts = \App\Models\Tenant\TenantDiscount::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->filter(fn ($d) => $d->inactiveReason() === null || $d->id === $campaign->discount_id)
            ->values();

        $attribution = $this->attributionFor($campaign);

        return view('tenant.campaigns.show', compact(
            'campaign', 'customerCount', 'segments', 'blocks', 'discounts', 'attribution'
        ));
    }

    /**
     * MARKER-CAMPAIGN-ATTRIBUTION — what the campaign's code actually did.
     * Counts redemptions since the campaign was sent, so uses of the same
     * code before the email went out aren't credited to it.
     */
    protected function attributionFor(\App\Models\Tenant\TenantCampaign $campaign): ?array
    {
        if (! $campaign->discount_id) return null;

        $discount = \App\Models\Tenant\TenantDiscount::find($campaign->discount_id);
        if (! $discount) return null;

        $rows = \App\Models\Tenant\TenantDiscountRedemption::where('discount_id', $discount->id);

        if ($campaign->sent_at) {
            $rows->where('created_at', '>=', $campaign->sent_at);
        }

        $agg = (clone $rows)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount_cents),0) as given, COALESCE(SUM(subtotal_cents),0) as subtotal')
            ->first();

        return [
            'code'          => $discount->code,
            'summary'       => $discount->summary(),
            'uses'          => (int) ($agg->n ?? 0),
            'given_cents'   => (int) ($agg->given ?? 0),
            'sales_cents'   => (int) ($agg->subtotal ?? 0),
            'since'         => $campaign->sent_at,
        ];
    }

    /** MARKER-CAMPAIGN-ATTRIBUTION — attach or detach a code. */
    public function setDiscount(Request $request, string $id)
    {
        $tenant   = tenant();
        $campaign = \App\Models\Tenant\TenantCampaign::where('tenant_id', $tenant->id)->findOrFail($id);

        $data = $request->validate(['discount_id' => 'nullable|uuid']);

        $discountId = $data['discount_id'] ?: null;

        if ($discountId) {
            $discount = \App\Models\Tenant\TenantDiscount::where('tenant_id', $tenant->id)
                ->find($discountId);
            if (! $discount) {
                return back()->with('error', 'That discount code no longer exists.');
            }
            // Point the code back at the campaign too, so the Discounts page
            // can show where a code is being promoted.
            $discount->update(['campaign_id' => $campaign->id]);
        }

        // Release any previously attached code's back-reference.
        if ($campaign->discount_id && $campaign->discount_id !== $discountId) {
            \App\Models\Tenant\TenantDiscount::where('id', $campaign->discount_id)
                ->where('campaign_id', $campaign->id)
                ->update(['campaign_id' => null]);
        }

        $campaign->update(['discount_id' => $discountId]);

        return back()->with('success', $discountId
            ? 'Code attached. Use {{discount_code}} in the email where you want it to appear.'
            : 'Code removed from this campaign.');
    }

    public function store(Request $request)
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

    public function update(Request $request, string $id)
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
        $blocks     = json_decode($blocksJson, true);
        if (! is_array($blocks)) {
            $blocks = [];
        }
        $blocks = self::sanitizeBlocks($blocks);

        if ($name === '' || $subject === '') {
            return back()
                ->with('error', 'Name and subject are required.')
                ->withInput();
        }

        // MARKER-CAMPAIGN-V2A — tokens stay RAW here on purpose: the worker
        // re-renders per recipient, so a stored copy with one person's name
        // baked in would be wrong for everyone else.
        $preheader = trim((string) $request->input('preheader', ''));
        $preheader = mb_substr($preheader, 0, 200);

        $bodyHtml = BlockRenderer::render($blocks, [], [
            'accent'     => $tenant->accent_color ?? '#BEF264',
            'accentText' => '#0a0a0a',
            'preheader'  => $preheader,
        ]);

        // MARKER-CAMPAIGN-SCHED — editing something already aimed at customers
        // returns it to draft rather than quietly changing what will go out.
        $wasScheduled = $campaign->status === 'scheduled';

        $campaign->update([
            'status'       => $wasScheduled ? 'draft' : $campaign->status,
            'scheduled_at' => $wasScheduled ? null : $campaign->scheduled_at,
            'name'        => $name,
            'subject'     => $subject,
            'preheader'   => $preheader !== '' ? $preheader : null,
            'show_header' => (bool) $request->boolean('show_header', true), // MARKER-CAMPAIGN-HDR
            'blocks'    => $blocks,
            'body_html' => $bodyHtml,
            'targeting' => ['segment' => $segment],
        ]);

        return back()->with('success', $wasScheduled
            ? 'Saved — and the schedule was cleared, because the campaign changed. Schedule it again when you are ready.'
            : 'Campaign saved.');
    }

    /**
     * MARKER-CAMPAIGN-RESULTS — per-recipient outcomes for one campaign.
     * The aggregate counters live on the campaign; this is the detail behind
     * them, including WHY someone was skipped.
     */
    public function results(string $id)
    {
        $tenant   = tenant();
        $campaign = TenantCampaign::where('tenant_id', $tenant->id)->findOrFail($id);

        $rows = TenantCampaignSend::where('campaign_id', $campaign->id)
            ->orderByRaw("FIELD(status,'bounced','failed','skipped','sent','pending')")
            ->orderByDesc('clicked_at')
            ->orderByDesc('opened_at')
            ->limit(1000)
            ->get();

        $summary = [
            'sent'    => $rows->where('status', 'sent')->count(),
            'opened'  => $rows->whereNotNull('opened_at')->count(),
            'clicked' => $rows->whereNotNull('clicked_at')->count(),
            'skipped' => $rows->where('status', 'skipped')->count(),
            'failed'  => $rows->where('status', 'failed')->count(),
            'bounced' => $rows->where('status', 'bounced')->count(),
            'pending' => $rows->where('status', 'pending')->count(),
        ];

        return view('tenant.campaigns.results', [
            'pageTitle' => 'Results — ' . $campaign->name,
            'campaign'  => $campaign,
            'rows'      => $rows,
            'summary'   => $summary,
        ]);
    }

    public function send(Request $request, string $id)
    {
        $tenant = tenant();

        $campaign = TenantCampaign::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        if ($campaign->status !== 'draft') {
            return back()->with('error', 'This campaign has already been sent or is in progress.');
        }

        // MARKER-CAMPAIGN-CHECKS — the same checks the panel shows, enforced
        // here too so posting the form directly can't skip them.
        $failed = collect($this->preSendChecks($campaign, $tenant))->where('level', 'fail');
        if ($failed->isNotEmpty()) {
            return back()->with('error', 'Not sent — ' . $failed->map(fn ($r) => strtolower($r['label']) . ': ' . $r['detail'])->implode(' '));
        }

        // MARKER-CAMPAIGN-DELIVERY — sending, for real this time.
        if (\App\Services\EmailLedger::broadcastStream() === null) {
            return back()->with('error', 'Campaign sending isn\'t switched on for the platform yet — the broadcast sending lane is still being configured. Your draft is safe.');
        }

        // MARKER-EMAIL-BILLING — refuse to queue over the shop's own limit.
        $capState = \App\Services\EmailLedger::capState($tenant);
        if ($capState['capped'] && $capState['reached']) {
            return back()->with('error', 'This month\'s marketing limit ($' . number_format($capState['cap'], 2) . ') has been reached, so campaigns are paused. Raise or remove the limit in Settings → Email charges. Receipts and confirmations are unaffected.');
        }

        $segment = $campaign->targeting['segment'] ?? 'all';

        $base = TenantCustomer::where('tenant_id', $tenant->id);
        if ($segment === 'has_appointment') {
            $base->whereHas('appointments');
        }

        $totalInSegment = (clone $base)->whereNotNull('email')->where('email', '!=', '')->count();
        $mailable       = (clone $base)->emailMailable()->get(['id', 'email']);

        if ($mailable->isEmpty()) {
            return back()->with('error', $totalInSegment > 0
                ? "None of the {$totalInSegment} contacts in this segment have marketing permission yet. Customers opt in through booking, checkout or their account page — or confirm permission for imported contacts."
                : 'No recipients match this segment.');
        }

        foreach ($mailable->chunk(500) as $chunk) {
            foreach ($chunk as $customer) {
                TenantCampaignSend::create([
                    'campaign_id'    => $campaign->id,
                    'customer_id'    => $customer->id,
                    'email'          => $customer->email,
                    'status'         => 'pending',
                    'tracking_token' => Str::random(32),
                    'created_at'     => now(),
                ]);
            }
        }

        $campaign->update([
            'status'           => 'sending',
            'total_recipients' => $mailable->count(),
        ]);

        $note = $mailable->count() < $totalInSegment
            ? " ({$mailable->count()} of {$totalInSegment} in the segment have marketing permission — the rest are skipped)"
            : '';

        return back()->with('success', "Sending to {$mailable->count()} recipient(s){$note}. Emails go out in batches over the next minutes.");
    }

    /**
     * MARKER-CAMPAIGN-CHECKS — what's wrong with this campaign, before it
     * goes out. Returns rows of ['level' => ok|warn|fail, 'label', 'detail'].
     *
     * Blocking faults are the ones that reach every recipient and can't be
     * undone: no subject, no content, or a link that was never filled in.
     * Everything else is a warning — annoying, not damaging.
     */
    private function preSendChecks(TenantCampaign $campaign, $tenant): array
    {
        $rows   = [];
        $blocks = $campaign->blocks ?? [];

        $subject = trim((string) $campaign->subject);
        $rows[] = $subject === ''
            ? ['level' => 'fail', 'label' => 'Subject line', 'detail' => 'Empty — an email without one usually goes to spam.']
            : ['level' => 'ok', 'label' => 'Subject line', 'detail' => $subject];

        $rows[] = empty($blocks)
            ? ['level' => 'fail', 'label' => 'Content', 'detail' => 'No blocks yet.']
            : ['level' => 'ok', 'label' => 'Content', 'detail' => count($blocks) . ' block' . (count($blocks) === 1 ? '' : 's')];

        $pre = trim((string) ($campaign->preheader ?? ''));
        $rows[] = $pre === ''
            ? ['level' => 'warn', 'label' => 'Preheader', 'detail' => 'Empty — inboxes will show your first sentence instead.']
            : ['level' => 'ok', 'label' => 'Preheader', 'detail' => $pre];

        // Walk the blocks once, gathering links, images and merge tags.
        $badLinks = [];
        $noAlt    = 0;
        $bareTags = [];
        $bodyText = '';

        $walk = function ($value) use (&$walk, &$badLinks, &$bareTags, &$bodyText) {
            if (is_array($value)) {
                foreach ($value as $v) { $walk($v); }
                return;
            }
            if (! is_string($value)) {
                return;
            }
            $bodyText .= ' ' . $value;
            // {{tag}} with no |fallback
            if (preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $value, $m)) {
                foreach ($m[1] as $tag) {
                    if ($tag !== 'discount_code' && ! in_array($tag, $bareTags, true)) {
                        $bareTags[] = $tag;
                    }
                }
            }
        };

        foreach ($blocks as $b) {
            $data = $b['data'] ?? [];
            $walk($data);

            $type = $b['type'] ?? '';

            foreach (['url', 'link'] as $k) {
                // 'url' on an image block is the image itself, not a link.
                if ($k === 'url' && in_array($type, ['image', 'image_text', 'gallery'], true)) {
                    continue;
                }
                $u = trim((string) ($data[$k] ?? ''));
                if ($u === '') {
                    continue;
                }
                if (in_array($u, ['#', 'https://', 'http://'], true) || ! parse_url($u, PHP_URL_HOST)) {
                    if (! str_starts_with($u, '/') && ! str_starts_with($u, 'mailto:')) {
                        $badLinks[] = $u;
                    }
                }
            }

            if (in_array($type, ['image', 'image_text'], true)) {
                if (trim((string) ($data['url'] ?? '')) !== '' && trim((string) ($data['alt'] ?? '')) === '') {
                    $noAlt++;
                }
            }
            if ($type === 'gallery') {
                foreach ((array) ($data['images'] ?? []) as $img) {
                    if (is_array($img) && trim((string) ($img['alt'] ?? '')) === '') {
                        $noAlt++;
                    }
                }
            }
            if ($type === 'social') {
                foreach ((array) ($data['links'] ?? []) as $l) {
                    $u = trim((string) ($l['url'] ?? ''));
                    if ($u !== '' && ! parse_url($u, PHP_URL_HOST) && ! str_starts_with($u, 'mailto:')) {
                        $badLinks[] = $u;
                    }
                }
            }
        }

        $rows[] = $badLinks
            ? ['level' => 'fail', 'label' => 'Links', 'detail' => count($badLinks) . ' unfinished: ' . implode(', ', array_slice(array_unique($badLinks), 0, 3))]
            : ['level' => 'ok', 'label' => 'Links', 'detail' => 'All look complete.'];

        $rows[] = $noAlt > 0
            ? ['level' => 'warn', 'label' => 'Image alt text', 'detail' => $noAlt . ' image' . ($noAlt === 1 ? '' : 's') . ' with none — screen readers and blocked-image inboxes see nothing.']
            : ['level' => 'ok', 'label' => 'Image alt text', 'detail' => 'Present where needed.'];

        $rows[] = $bareTags
            ? ['level' => 'warn', 'label' => 'Merge tags', 'detail' => 'No fallback on ' . implode(', ', array_slice($bareTags, 0, 3)) . ' — a blank sends as "Hi ,".']
            : ['level' => 'ok', 'label' => 'Merge tags', 'detail' => 'Fallbacks in place.'];

        $rows[] = stripos($bodyText, 'unsubscribe') !== false
            ? ['level' => 'ok', 'label' => 'Unsubscribe', 'detail' => 'Mentioned in your content, and the footer link is added automatically.']
            : ['level' => 'warn', 'label' => 'Unsubscribe', 'detail' => 'The footer link is added automatically; some shops prefer to mention it in the copy too.'];

        return $rows;
    }

    /** MARKER-CAMPAIGN-CHECKS — recipients and what the send will cost. */
    private function preSendAudience($campaign, $tenant): array
    {
        $segment = $campaign->targeting['segment'] ?? 'all';

        $base = TenantCustomer::where('tenant_id', $tenant->id);
        if ($segment === 'has_appointment') {
            $base->whereHas('appointments');
        }

        $withEmail = (clone $base)->whereNotNull('email')->where('email', '!=', '')->count();
        $mailable  = (clone $base)->emailMailable()->count();
        $rate      = \App\Services\EmailLedger::rate();

        return [
            'mailable'  => $mailable,
            'withEmail' => $withEmail,
            'cost'      => $mailable * $rate,
        ];
    }

    /** MARKER-CAMPAIGN-CHECKS — the checks panel, fetched by the composer. */
    public function checks(Request $request, string $id)
    {
        $tenant   = tenant();
        $campaign = TenantCampaign::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $rows     = $this->preSendChecks($campaign, $tenant);
        $audience = $this->preSendAudience($campaign, $tenant);

        return response()->json([
            'success'  => true,
            'rows'     => $rows,
            'blocking' => collect($rows)->where('level', 'fail')->pluck('label')->values(),
            'audience' => $audience,
        ]);
    }

    /**
     * MARKER-CAMPAIGN-SCHED — arm a campaign for later.
     *
     * Deliberately does NOT queue recipient rows now. The worker builds the
     * list when it fires, so a customer who unsubscribes between scheduling
     * and sending is excluded — queueing early would mail them anyway.
     */
    public function schedule(Request $request, string $id)
    {
        $tenant   = tenant();
        $campaign = TenantCampaign::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        if ($campaign->status !== 'draft') {
            return back()->with('error', 'Only a draft can be scheduled.');
        }

        // MARKER-CAMPAIGN-CHECKS — a scheduled send is one nobody is watching,
        // so the same faults block here.
        $failed = collect($this->preSendChecks($campaign, $tenant))->where('level', 'fail');
        if ($failed->isNotEmpty()) {
            return back()->with('error', 'Not scheduled — ' . $failed->map(fn ($r) => strtolower($r['label']) . ': ' . $r['detail'])->implode(' '));
        }

        if (\App\Services\EmailLedger::broadcastStream() === null) {
            return back()->with('error', 'Campaign sending isn\'t switched on for the platform yet, so it can\'t be scheduled either. Your draft is safe.');
        }

        $raw = trim((string) $request->input('scheduled_at'));
        if ($raw === '') {
            return back()->with('error', 'Pick a date and time to send.');
        }

        // Entered in the shop's timezone; stored UTC.
        try {
            $when = \Carbon\Carbon::parse($raw, $tenant->timezone())->utc();
        } catch (\Throwable $e) {
            return back()->with('error', 'That date and time could not be read.');
        }

        if ($when->lessThan(now()->addMinutes(5))) {
            return back()->with('error', 'Schedule at least 5 minutes out, so there is time to cancel. Use Send now if you mean immediately.');
        }

        $campaign->update([
            'status'       => 'scheduled',
            'scheduled_at' => $when,
        ]);

        return back()->with('success', 'Scheduled for ' . $when->copy()->setTimezone($tenant->timezone())->format('M j, Y \a\t g:ia') . '. You can cancel any time before then.');
    }

    /** MARKER-CAMPAIGN-SCHED — disarm, back to draft. */
    public function unschedule(Request $request, string $id)
    {
        $tenant   = tenant();
        $campaign = TenantCampaign::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        if ($campaign->status !== 'scheduled') {
            return back()->with('error', 'That campaign is not scheduled.');
        }

        $campaign->update(['status' => 'draft', 'scheduled_at' => null]);

        return back()->with('success', 'Schedule cancelled — it is a draft again.');
    }

    /**
     * Live preview endpoint — takes blocks JSON, returns rendered HTML.
     * Used by the composer iframe for real-time preview without a full save.
     */
    public function preview(Request $request, string $id)
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

        // MARKER-CAMPAIGN-V2A — preview shows the preheader as typed (unsaved)
        // and resolves tokens, so what you see is what recipients get.
        $sample = BlockRenderer::SAMPLE_VARS;
        if ($campaign = TenantCampaign::where('tenant_id', $tenant->id)->find($request->input('campaign_id'))) {
            if ($campaign->discount_id) {
                $d = \App\Models\Tenant\TenantDiscount::find($campaign->discount_id);
                if ($d) {
                    $sample['discount_code'] = $d->code;
                }
            }
        }
        $sample['shop_name'] = (string) $tenant->name;

        // MARKER-CAMPAIGN-CHROME — the preview used to show blocks alone on
        // white while the real email carries a branded header and footer, so
        // nothing in the builder revealed how the finished email looked.
        $html = BlockRenderer::render($blocks, $sample, [
            'accent'        => $tenant->accent_color ?? '#BEF264',
            'accentText'    => '#0a0a0a',
            'preview'       => true,
            'preheader'     => trim((string) $request->input('preheader', '')),
            'resolveTokens' => true,
            // MARKER-CAMPAIGN-HDR — preview follows the toggle as typed.
            'chrome'        => true,
            'chromeHeader'  => $request->boolean('show_header', true),
        ]);

        return response($html)->header('Content-Type', 'text/html');
    }

    /**
     * MARKER-CAMPAIGN-V2C — one search across both catalogs for the block's
     * picker. Returns the display fields so the composer can show a preview
     * without re-implementing either lookup.
     */
    public function catalogSearch(Request $request)
    {
        $tenant = tenant();
        $q      = trim((string) $request->query('q', ''));

        $services = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->when($q !== '', fn ($b) => $b->where('name', 'like', '%' . $q . '%'))
            ->orderBy('name')->limit(20)
            ->get(['id', 'name', 'price_cents', 'image_url']);

        $products = \App\Models\Tenant\TenantInventoryItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->when($q !== '', fn ($b) => $b->where('name', 'like', '%' . $q . '%'))
            ->with('distributorCatalog:id,images')
            ->orderBy('name')->limit(20)
            ->get();

        $out = [];
        foreach ($services as $s) {
            $out[] = [
                'kind'  => 'service',
                'id'    => (string) $s->id,
                'name'  => (string) $s->name,
                'price' => $s->price_cents !== null ? '$' . number_format($s->price_cents / 100, 2) : null,
                'photo' => $s->image_url ?: null,
            ];
        }
        foreach ($products as $pI) {
            $ims   = (array) ($pI->distributorCatalog->images ?? []);
            $first = $ims[0] ?? null;
            $photo = is_array($first)
                ? ($first['Url'] ?? $first['url'] ?? $first['src'] ?? null)
                : (is_string($first) ? $first : null);
            $cents = $pI->effectiveSellPriceCents();
            $out[] = [
                'kind'  => 'product',
                'id'    => (string) $pI->id,
                'name'  => (string) $pI->name,
                'price' => $cents !== null ? '$' . number_format($cents / 100, 2) : null,
                'photo' => $photo,
            ];
        }

        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return response()->json(['items' => array_slice($out, 0, 30)]);
    }

    /**
     * MARKER-CAMPAIGN-V2A — send this campaign to the signed-in user, so a
     * draft can be checked in a real inbox before it goes to customers.
     * Goes through the normal ledger: it costs one email, like any send.
     */
    public function testSend(Request $request, string $id)
    {
        $tenant   = tenant();
        $campaign = TenantCampaign::where('tenant_id', $tenant->id)->findOrFail($id);

        // MARKER-CAMPAIGN-V2F — send the test wherever you like (a phone, a
        // colleague), defaulting to the signed-in user.
        $user  = auth('tenant')->user();
        $email = trim((string) ($request->input('to') ?: ($user->email ?? '')));

        if ($email === '') {
            return back()->with('error', 'Enter an address to send the test to.');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return back()->with('error', 'That doesn\'t look like a valid email address.');
        }

        if (empty($campaign->blocks)) {
            return back()->with('error', 'Add at least one content block first.');
        }

        if (\App\Services\EmailLedger::broadcastStream() === null) {
            return back()->with('error', 'Campaign sending isn\'t switched on for the platform yet, so a test can\'t go out either.');
        }

        $vars = BlockRenderer::SAMPLE_VARS;
        $vars['shop_name'] = (string) $tenant->name;
        if ($campaign->discount_id) {
            $d = \App\Models\Tenant\TenantDiscount::find($campaign->discount_id);
            if ($d) {
                $vars['discount_code'] = $d->code;
            }
        }

        $html = BlockRenderer::render($campaign->blocks ?? [], $vars, [
            'accent'        => $tenant->accent_color ?? '#BEF264',
            'accentText'    => '#0a0a0a',
            'preheader'     => (string) ($campaign->preheader ?? ''),
            'resolveTokens' => true,
            'fragment'      => true, // MARKER-CAMPAIGN-CHROME
        ]);

        $ok = \App\Services\EmailService::forTenant($tenant)->sendCampaign(
            $email,
            '[TEST] ' . (string) $campaign->subject,
            $html,
            (string) $campaign->id,
            rtrim((string) $tenant->publicUrl(), '/') . '/email/unsubscribe/test',
            null,
            (bool) ($campaign->show_header ?? true) // MARKER-CAMPAIGN-HDR
        );

        return back()->with(
            $ok ? 'success' : 'error',
            $ok
                ? "Test sent to {$email} with sample values. It counts as one email against your plan."
                : 'Test send failed — check the application log.'
        );
    }

    /**
     * Whitelist block shapes so we don't persist garbage or XSS.
     * Unknown block types are dropped; unknown fields are dropped.
     */
    private static function sanitizeBlocks(array $blocks): array
    {
        $allowed = [
            // MARKER-CAMPAIGN-V2E — styling fields added to the existing shapes.
            'heading'   => ['text', 'size', 'align', 'bg_color'],
            'paragraph' => ['html', 'text', 'align', 'bg_color', 'size'], // MARKER-CAMPAIGN-V2F
            'image'     => ['url', 'alt', 'width', 'align', 'link', 'radius', 'bg_color'],
            'button'    => ['text', 'url', 'align', 'full_width', 'bg_color'],
            'divider'   => [],
            'footer'    => ['text'],
            // MARKER-CAMPAIGN-V2B
            'spacer'     => ['height'],
            'two_column' => ['left', 'right', 'bg_color'],
            'image_text' => ['url', 'alt', 'text', 'side', 'ratio', 'bg_color'],
            'social'     => [], // links handled separately — it's an array
            'catalog'    => ['show_price', 'show_photo', 'cta_text', 'per_row', 'bg_color'], // MARKER-CAMPAIGN-V2C
            'gallery'    => ['layout', 'bg_color'], // MARKER-CAMPAIGN-V2F — images is an array
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
                    $value = is_string($block['data'][$field])
                        ? $block['data'][$field]
                        : (string) $block['data'][$field];

                    // Run HTML fields through the sanitizer before saving.
                    if ($type === 'paragraph' && $field === 'html') {
                        $value = \App\Support\BlockRenderer::sanitizeHtml($value);
                    }

                    $data[$field] = $value;
                }
            }
            // MARKER-CAMPAIGN-V2F — gallery keeps an array of {url,alt,link}.
            if ($type === 'gallery') {
                $imgs = $block['data']['images'] ?? [];
                if (is_string($imgs)) {
                    $decoded = json_decode($imgs, true);
                    $imgs = is_array($decoded) ? $decoded : [];
                }
                $out = [];
                foreach ((array) $imgs as $i) {
                    if (! is_array($i)) {
                        continue;
                    }
                    $u = trim(mb_substr((string) ($i['url'] ?? ''), 0, 500));
                    if ($u === '') {
                        continue;
                    }
                    $row = ['url' => $u, 'alt' => trim(mb_substr((string) ($i['alt'] ?? ''), 0, 160))];
                    $l = trim(mb_substr((string) ($i['link'] ?? ''), 0, 300));
                    if ($l !== '' && preg_match('/^(https?:\/\/|mailto:)/i', $l)) {
                        $row['link'] = $l;
                    }
                    $out[] = $row;
                    if (count($out) >= 6) {
                        break;
                    }
                }
                $data['images'] = $out;
            }

            // MARKER-CAMPAIGN-V2C — catalog keeps an array of picked items.
            // Only kind + id + optional overrides are stored: name, price and
            // photo are resolved at render time from the live catalog.
            if ($type === 'catalog') {
                $items = $block['data']['items'] ?? [];
                if (is_string($items)) {
                    $decoded = json_decode($items, true);
                    $items = is_array($decoded) ? $decoded : [];
                }
                $out = [];
                foreach ((array) $items as $i) {
                    if (! is_array($i)) {
                        continue;
                    }
                    $kind = ($i['kind'] ?? '') === 'product' ? 'product' : 'service';
                    $id   = trim((string) ($i['id'] ?? ''));
                    if ($id === '') {
                        continue;
                    }
                    $row = ['kind' => $kind, 'id' => $id];
                    $n = trim(mb_substr((string) ($i['name']  ?? ''), 0, 120));
                    $pr = trim(mb_substr((string) ($i['price'] ?? ''), 0, 30));
                    if ($n !== '')  $row['name']  = $n;
                    if ($pr !== '') $row['price'] = $pr;
                    $out[] = $row;
                    if (count($out) >= 4) {
                        break;
                    }
                }
                $data['items'] = $out;
            }

            // MARKER-CAMPAIGN-V2B — social keeps an array of {label,url};
            // the loop above only handles scalar fields.
            if ($type === 'social') {
                $links = $block['data']['links'] ?? [];
                if (is_string($links)) {
                    $decoded = json_decode($links, true);
                    $links = is_array($decoded) ? $decoded : [];
                }
                $out = [];
                foreach ((array) $links as $l) {
                    if (! is_array($l)) {
                        continue;
                    }
                    $label = trim(mb_substr((string) ($l['label'] ?? ''), 0, 40));
                    $url   = trim(mb_substr((string) ($l['url'] ?? ''), 0, 300));
                    if ($label === '' || ! preg_match('/^(https?:\/\/|mailto:)/i', $url)) {
                        continue;
                    }
                    $out[] = ['label' => $label, 'url' => $url];
                    if (count($out) >= 5) {
                        break;
                    }
                }
                $data['links'] = $out;
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
