<?php

namespace App\Console\Commands;

use App\Http\Controllers\Platform\PlatformUnsubscribeController;
use App\Models\PlatformCampaign;
use App\Models\PlatformCampaignSend;
use App\Models\PlatformEmailOptout;
use App\Services\Platform\PlatformAudienceService;
use App\Services\Platform\PlatformMailer;
use App\Support\BlockRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * MARKER-PLATFORM-EMAIL — fires due campaigns and drains pending sends.
 *
 * Two jobs, in order:
 *   1. a scheduled campaign whose time has come gets its recipient list built
 *      NOW, not when it was scheduled — anyone who unsubscribed in between is
 *      excluded, which is the whole reason the list isn't built up front;
 *   2. pending rows are sent, 120 a minute, with the opt-out re-checked at the
 *      moment of sending.
 *
 * The body is rendered PER RECIPIENT. Rendering once outside the loop is how
 * tenant campaigns once shipped "{{first_name}}" to every inbox as literal text.
 */
class ProcessPlatformCampaignSends extends Command
{
    protected $signature = 'platform:process-campaign-sends {--limit=120}';
    protected $description = 'Fire due platform campaigns and send their pending emails.';

    public function handle(PlatformAudienceService $audiences): int
    {
        $this->fireDue($audiences);

        if (! PlatformMailer::stream()) {
            $this->warn('No platform broadcast stream configured — nothing sends.');
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $sent  = 0;

        $pending = PlatformCampaignSend::where('status', 'pending')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($pending as $row) {
            $campaign = PlatformCampaign::find($row->campaign_id);
            if (! $campaign || $campaign->status === 'cancelled') {
                $row->update(['status' => 'skipped', 'skip_reason' => 'campaign gone']);
                continue;
            }

            // Re-checked HERE, not when the list was built: someone may have
            // unsubscribed between the fire and this row's turn.
            if (PlatformEmailOptout::has($row->email)) {
                $row->update(['status' => 'skipped', 'skip_reason' => 'unsubscribed']);
                continue;
            }

            try {
                $html = $this->renderFor($campaign, $row);
                // MARKER-PLATFORM-INBOUND — every campaign email gets an inbox
                // message and its token up front, so a reply has somewhere to
                // land. The message starts archived: a campaign send is not
                // something you need to read, but the moment they answer, the
                // webhook flips it to new and it surfaces.
                $thread = \App\Models\PlatformInboxMessage::firstOrCreate(
                    ['campaign_id' => $campaign->id, 'email' => $row->email],
                    [
                        'kind'    => \App\Models\PlatformInboxMessage::KIND_CONTACT,
                        'status'  => 'archived',
                        'name'    => $row->name,
                        'subject' => (string) $campaign->name,
                        'body'    => 'Sent as part of the campaign "' . $campaign->name . '".',
                        'meta'    => ['campaign' => $campaign->id, 'origin' => 'campaign_send'],
                    ]
                );

                $ok = PlatformMailer::send(
                    $row->email,
                    $row->name,
                    $this->merge((string) $campaign->subject, $row),
                    $html,
                    PlatformUnsubscribeController::url($row->email),
                    ['X-PM-Metadata-platform_campaign' => $campaign->id],
                    $thread->replyToken()
                );

                if ($ok) {
                    $row->update(['status' => 'sent', 'sent_at' => now()]);
                    $campaign->increment('total_sent');
                    $sent++;
                } else {
                    $row->update(['status' => 'failed', 'error' => 'no stream configured']);
                }
            } catch (\Throwable $e) {
                $row->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 400)]);
                \App\Support\JobFailureReporter::report(
                    static::class,
                    'Platform campaign send failed',
                    $e,
                    ['campaign' => $campaign->id, 'send' => $row->id]
                );
            }
        }

        // A campaign with nothing left pending is done.
        PlatformCampaign::where('status', 'sending')
            ->whereDoesntHave('sends', fn ($q) => $q->where('status', 'pending'))
            ->update(['status' => 'sent', 'sent_at' => now()]);

        if ($sent > 0) {
            $this->info("Sent {$sent}.");
        }

        return self::SUCCESS;
    }

    protected function fireDue(PlatformAudienceService $audiences): void
    {
        $due = PlatformCampaign::where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($due as $campaign) {
            $audience = $campaign->audience;
            if (! $audience) {
                $campaign->update(['status' => 'draft']);
                $this->warn("Campaign {$campaign->name} has no audience — returned to draft.");
                continue;
            }

            $recipients = $audiences->mailable($audience);
            if ($recipients->isEmpty()) {
                $campaign->update(['status' => 'draft']);
                $this->warn("Campaign {$campaign->name} matched nobody — returned to draft.");
                continue;
            }

            foreach ($recipients as $r) {
                PlatformCampaignSend::firstOrCreate(
                    ['campaign_id' => $campaign->id, 'email' => $r['email']],
                    [
                        'name'           => $r['name'] ?? null,
                        'source_type'    => $r['source_type'] ?? null,
                        'source_id'      => $r['source_id'] ?? null,
                        'status'         => 'pending',
                        'tracking_token' => Str::random(32),
                    ]
                );
            }

            $campaign->update([
                'status'           => 'sending',
                'total_recipients' => $recipients->count(),
            ]);
        }
    }

    protected function renderFor(PlatformCampaign $campaign, PlatformCampaignSend $row): string
    {
        $vars = [
            'first_name' => $this->firstName($row->name) ?: 'there',
            'shop_name'  => $row->name ?: '',
            'email'      => $row->email,
        ];

        // MARKER-PLATFORM-CAMPAIGNS-UI — one body format across the platform:
        // the composer writes text and it renders in the same Intake chrome the
        // template editor uses. Blocks remain supported, so a block-built
        // campaign still renders through BlockRenderer if one ever exists.
        if (trim((string) $campaign->body) !== '') {
            return view('emails.platform.custom', [
                'bodyHtml' => nl2br(e(\App\Support\PlatformEmailTemplates::merge((string) $campaign->body, $vars))),
                'postal'   => \App\Services\Platform\PlatformMailer::postalAddress(),
            ])->render();
        }

        return BlockRenderer::render($campaign->blocks ?? [], $vars, [
            'preheader'     => (string) $campaign->preheader,
            'resolveTokens' => true,
            'fragment'      => false,
        ]);
    }

    protected function merge(string $text, PlatformCampaignSend $row): string
    {
        $first = $this->firstName($row->name) ?: 'there';
        return str_replace(
            ['{{first_name}}', '{{shop_name}}'],
            [$first, $row->name ?: ''],
            $text
        );
    }

    protected function firstName(?string $full): ?string
    {
        $full = trim((string) $full);
        return $full === '' ? null : explode(' ', $full)[0];
    }
}
