<?php
// MARKER-CAMPAIGN-DELIVERY

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantCampaign;
use App\Models\Tenant\TenantCampaignSend;
use App\Models\Tenant\TenantCustomer;
use App\Services\EmailService;
use App\Services\Tenant\ConsentService;
use App\Support\BlockRenderer;
use Illuminate\Console\Command;

/**
 * Drains pending campaign sends, oldest first, throttled per run
 * (scheduled every minute, so --limit is emails/minute platform-wide).
 * Consent and suppression are re-checked per recipient AT SEND TIME —
 * someone who unsubscribed after the queue was built is skipped.
 */
class ProcessCampaignSends extends Command
{
    protected $signature   = 'campaigns:process-sends {--limit=120}';
    protected $description = 'Send pending campaign emails (throttled, consent-checked)';

    /**
     * MARKER-CAMPAIGN-SCHED — scheduled campaigns whose time has passed.
     * The recipient list is built here, at fire time, for the same reason
     * send() builds it at click time: consent as it stands when the mail
     * actually goes out.
     */
    private function fireDueCampaigns(): void
    {
        $due = TenantCampaign::where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($due as $campaign) {
            $tenant = Tenant::find($campaign->tenant_id);
            if (! $tenant) {
                continue;
            }

            // A month can turn over between scheduling and firing.
            $capState = \App\Services\EmailLedger::capState($tenant);
            if ($capState['capped'] && $capState['reached']) {
                $campaign->update(['status' => 'draft', 'scheduled_at' => null]);
                logger()->warning('scheduled campaign returned to draft: email cap reached', [
                    'campaign_id' => $campaign->id, 'tenant_id' => $tenant->id,
                ]);
                continue;
            }

            $segment = $campaign->targeting['segment'] ?? 'all';
            $base = \App\Models\Tenant\TenantCustomer::where('tenant_id', $tenant->id);
            if ($segment === 'has_appointment') {
                $base->whereHas('appointments');
            }
            $mailable = (clone $base)->emailMailable()->get(['id', 'email']);

            if ($mailable->isEmpty()) {
                $campaign->update(['status' => 'draft', 'scheduled_at' => null]);
                logger()->warning('scheduled campaign returned to draft: no recipients with permission', [
                    'campaign_id' => $campaign->id, 'tenant_id' => $tenant->id,
                ]);
                continue;
            }

            foreach ($mailable as $customer) {
                \App\Models\Tenant\TenantCampaignSend::create([
                    'campaign_id'    => $campaign->id,
                    'customer_id'    => $customer->id,
                    'email'          => $customer->email,
                    'status'         => 'pending',
                    'tracking_token' => \Illuminate\Support\Str::random(32),
                    'created_at'     => now(),
                ]);
            }

            $campaign->update([
                'status'           => 'sending',
                'total_recipients' => $mailable->count(),
            ]);

            $this->info("Fired scheduled campaign {$campaign->id} to {$mailable->count()} recipients");
        }
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        if (\App\Services\EmailLedger::broadcastStream() === null) {
            // Not an error state — campaigns simply wait until master admin
            // configures the Postmark broadcast stream.
            return self::SUCCESS;
        }

        // MARKER-CAMPAIGN-SCHED — arm anything whose time has come, building
        // the recipient list NOW so opt-outs since scheduling are respected.
        $this->fireDueCampaigns();

        $campaigns = TenantCampaign::where('status', 'sending')->orderBy('sent_at')->get();
        if ($campaigns->isEmpty()) {
            return self::SUCCESS;
        }

        $budget = $limit;

        foreach ($campaigns as $campaign) {
            if ($budget <= 0) break;

            $tenant = Tenant::find($campaign->tenant_id);
            if (! $tenant) continue;

            // MARKER-EMAIL-BILLING — re-checked per campaign per run, so a
            // limit set (or reached) mid-send stops the rest of the queue.
            $cap = \App\Services\EmailLedger::capState($tenant);
            if ($cap['capped'] && $cap['reached']) {
                $this->warn("Campaign {$campaign->id} paused: monthly marketing limit reached.");
                continue;
            }

            $svc = EmailService::forTenant($tenant);

            // MARKER-CAMPAIGN-ATTRIBUTION — {{discount_code}} in any block is
            // replaced with the campaign's attached code; the same for everyone.
            $baseVars = ['shop_name' => (string) $tenant->name];
            if ($campaign->discount_id) {
                $d = \App\Models\Tenant\TenantDiscount::find($campaign->discount_id);
                if ($d) {
                    $baseVars['discount_code'] = $d->code;
                }
            }

            // MARKER-CAMPAIGN-V2A — rendering moved INSIDE the recipient loop.
            // It used to happen once here, so {{first_name}} could never be
            // personalised: every inbox got the literal token.
            $renderOpts = [
                'accent'        => $tenant->accent_color ?? '#BEF264',
                'accentText'    => '#0a0a0a',
                'preheader'     => (string) ($campaign->preheader ?? ''),
                'resolveTokens' => true,
                'fragment'      => true, // MARKER-CAMPAIGN-CHROME
            ];

            $rows = TenantCampaignSend::where('campaign_id', $campaign->id)
                ->where('status', 'pending')
                ->orderBy('created_at')
                ->limit($budget)
                ->get();

            foreach ($rows as $row) {
                $budget--;

                $customer = $row->customer_id ? TenantCustomer::find($row->customer_id) : null;

                if (! $customer || ! $customer->emailMarketingMailable()) {
                    $row->update(['status' => 'skipped', 'error_message' => 'no marketing consent at send time']);
                    continue;
                }

                if (\App\Models\Tenant\TenantEmailSuppression::isSuppressed($tenant->id, $row->email)) {
                    $row->update(['status' => 'skipped', 'error_message' => 'suppressed at send time']);
                    continue;
                }

                // MARKER-CAMPAIGN-V2A — this recipient's own values.
                $vars = $baseVars + [
                    'first_name' => (string) $customer->first_name,
                    'last_name'  => (string) $customer->last_name,
                    'name'       => trim($customer->first_name . ' ' . $customer->last_name),
                ];
                $html = BlockRenderer::render($campaign->blocks ?? [], $vars, $renderOpts);

                $ok = $svc->sendCampaign(
                    $row->email,
                    (string) $campaign->subject,
                    $html,
                    (string) $campaign->id,
                    ConsentService::unsubscribeUrl($tenant, $customer),
                    (string) $row->id, // MARKER-CAMPAIGN-RESULTS
                    (bool) ($campaign->show_header ?? true) // MARKER-CAMPAIGN-HDR
                );

                $row->update($ok
                    ? ['status' => 'sent', 'sent_at' => now()]
                    : ['status' => 'failed', 'error_message' => 'send failed — see application log']);
            }

            $remaining = TenantCampaignSend::where('campaign_id', $campaign->id)
                ->where('status', 'pending')->count();

            if ($remaining === 0) {
                $campaign->update([
                    'status'     => 'sent',
                    'sent_at'    => now(),
                    'total_sent' => TenantCampaignSend::where('campaign_id', $campaign->id)
                                        ->where('status', 'sent')->count(),
                ]);
                $this->info("Campaign {$campaign->id} complete.");
            }
        }

        return self::SUCCESS;
    }
}
