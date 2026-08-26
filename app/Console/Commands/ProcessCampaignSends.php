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

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        if (\App\Services\EmailLedger::broadcastStream() === null) {
            // Not an error state — campaigns simply wait until master admin
            // configures the Postmark broadcast stream.
            return self::SUCCESS;
        }

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

            $svc  = EmailService::forTenant($tenant);
            $html = BlockRenderer::render($campaign->blocks ?? []);

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

                $ok = $svc->sendCampaign(
                    $row->email,
                    (string) $campaign->subject,
                    $html,
                    (string) $campaign->id,
                    ConsentService::unsubscribeUrl($tenant, $customer),
                    (string) $row->id // MARKER-CAMPAIGN-RESULTS
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
