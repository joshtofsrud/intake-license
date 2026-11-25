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
 * fires due campaigns and drains pending sends.
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

        // unpaced rows first, so a long paced list never holds up anyone else
        $pending = PlatformCampaignSend::where('status', 'pending')
            // MARKER-PERSONAL-DELIVERY: personal rows wait for their own click
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', '!=', 'personal'))
            // MARKER-CAMPAIGN-STOP: a stopped campaign's rows wait, untouched, for Resume
            ->whereNotIn('campaign_id', PlatformCampaign::where('status', 'paused')->select('id'))
            ->orderBy('paced')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
        $started = microtime(true);

        foreach ($pending as $row) {
            // MARKER-CAMPAIGN-PACE: cold prospects go out one at a time at the
            // outreach sender's gap (business / free addresses apart), and
            // after its own last send, so the two never double up. A row
            // whose turn hasn't come stays pending for the next run.
            if ($row->paced && ! $this->paceAllows($row->email, $started)) {
                continue;
            }

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
            // MARKER-POSTMARK-INACTIVE
            $pmStream = $campaign->bulkMode() === 'outreach' ? PlatformMailer::outreachStream() : PlatformMailer::stream(); // MARKER-PERSONAL-DELIVERY
            if ($pmWhy = \App\Services\PostmarkInactive::reason($pmStream, $row->email)) {
                $row->update(['status' => 'skipped', 'skip_reason' => mb_substr(\App\Services\PostmarkInactive::skipText($pmWhy), 0, 120)]);
                continue;
            }

            try {
                $ok = $this->deliver($campaign, $row, $campaign->bulkMode(), $campaign->senderPair());
                if ($ok) {
                    if ($row->paced) {
                        \Illuminate\Support\Facades\Cache::put($this->paceKey($row->email), now()->getTimestamp(), 86400);
                    }
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

            $total = $this->buildList($campaign, $audiences);

            if ($total === 0) {
                $campaign->update(['status' => 'draft']);
                $this->warn("Campaign {$campaign->name} matched nobody — returned to draft.");
                continue;
            }

            $campaign->update([
                'status'           => 'sending',
                'total_recipients' => $total,
            ]);
        }
    }

    /**
     * The recipient rows for a campaign, built from its audience NOW so
     * anyone who unsubscribed since it was scheduled is left out. Personal
     * campaigns build theirs the same way, marked personal so the worker
     * leaves them for their Send buttons.
     */
    public function buildList(PlatformCampaign $campaign, PlatformAudienceService $audiences, ?string $channel = null): int
    {
        // MARKER-PROSPECTS-SQL: built 1,000 at a time with one insert per
        // batch; a 100k prospect list no longer sits in memory or costs a
        // query per person. The unique (campaign, email) key makes a rerun
        // after an interruption pick up where it stopped.
        $audiences->eachMailable($campaign->audience, function ($chunk) use ($campaign, $channel) {
            $now  = now();
            $rows = $chunk->map(fn ($r) => [
                'id'             => (string) Str::uuid(),
                'campaign_id'    => $campaign->id,
                'email'          => mb_substr((string) $r['email'], 0, 191),
                'name'           => isset($r['name']) ? mb_substr((string) $r['name'], 0, 160) : null,
                'source_type'    => $r['source_type'] ?? null,
                'source_id'      => isset($r['source_id']) ? (string) $r['source_id'] : null,
                'status'         => 'pending',
                'paced'          => ! empty($r['cold']),   // cold prospect: outreach pace
                'channel'        => $channel,
                'tracking_token' => Str::random(32),
                'created_at'     => $now,
                'updated_at'     => $now,
            ])->all();
            // repeats of an address (same person twice) are ignored by the key
            PlatformCampaignSend::insertOrIgnore($rows);
        });
        // MARKER-CAMPAIGN-STOP: sending again after a cancel. Those rows already
        // exist (skipped), so the insert above ignores them and the campaign
        // finished at once having sent nothing. Put them back in line; people
        // who already got it keep their sent row and are not mailed twice.
        static::revive($campaign);
        return PlatformCampaignSend::where('campaign_id', $campaign->id)->count();
    }

    /** reasons a row was skipped only because the campaign was stopped */
    public const STOPPED_REASONS = ['campaign cancelled', 'campaign gone'];

    /** rows skipped because the campaign was cancelled go back to waiting; returns how many */
    public static function revive(PlatformCampaign $campaign): int
    {
        return PlatformCampaignSend::where('campaign_id', $campaign->id)
            ->where('status', 'skipped')->whereIn('skip_reason', self::STOPPED_REASONS)
            ->update(['status' => 'pending', 'skip_reason' => null, 'updated_at' => now()]);
    }

    /**
     * MARKER-PERSONAL-DELIVERY: one email for one row, shared by the worker
     * (bulk) and the per-person Send button (personal). Throws on a mailer
     * error; returns false when there is no stream to send on. A resend of an
     * already-sent row counts once in the campaign total, not twice.
     */
    public function deliver(PlatformCampaign $campaign, PlatformCampaignSend $row, ?string $mode, ?array $sender): bool
    {
        $html = $this->renderFor($campaign, $row);
        // every campaign email gets an inbox message and its token up front,
        // so a reply has somewhere to land. It starts archived; the moment
        // they answer, the webhook flips it to new and it surfaces.
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
            ['X-PM-Metadata-platform_campaign' => $campaign->id, 'X-PM-Metadata-platform_send_id' => $row->id],
            $thread->replyToken(),
            $sender,
            $mode
        );
        if (! $ok) return false;

        $first = $row->status !== 'sent';
        $row->update([
            'status'     => 'sent',
            'sent_at'    => now(),
            'channel'    => $mode === 'personal' ? 'personal' : ($row->channel ?: 'bulk'),
            'send_count' => (int) $row->send_count + 1,
            'error'      => null,
        ]);
        if ($first) {
            $campaign->increment('total_sent');
            if ($row->source_type === 'prospects' && $row->source_id) {
                \App\Models\SalesProspect::find($row->source_id)?->activities()->create(['type' => 'email', 'body' => 'Emailed: ' . $campaign->name]);
            }
        }
        return true;
    }

    /** Public so the composer's test send renders exactly what a real send does. */
    public function renderFor(PlatformCampaign $campaign, PlatformCampaignSend $row): string
    {
        $vars = [
            'first_name' => $this->firstName($row->name) ?: 'there',
            'shop_name'  => $row->name ?: '',
            'email'      => $row->email,
        ];

        // a campaign written as a letter renders in
        // Intake's palette, with investor tokens for investor rows.
        if (\App\Support\PlatformLetter::isLetter($campaign->blocks ?? [])) {
            if ($row->source_type === 'investors') {
                $vars += \App\Support\PlatformLetter::investorVars(\App\Models\Investor::find($row->source_id));
            }
            return \App\Support\PlatformLetter::render(
                $campaign->blocks, $vars, ($campaign->theme ?? 'light') === 'dark' ? 'dark' : 'light',
                \App\Support\PlatformLetter::footerFor($row->source_type),
                PlatformUnsubscribeController::url($row->email),
                (string) $campaign->preheader
            );
        }

        // one body format across the platform:
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

    public function merge(string $text, PlatformCampaignSend $row): string
    {
        $first = $this->firstName($row->name) ?: 'there';
        $text = str_replace(
            ['{{first_name}}', '{{shop_name}}'],
            [$first, $row->name ?: ''],
            $text
        );
        // single-brace tokens, and investor tokens, in subjects too.
        $vars = ['first_name' => $first, 'shop_name' => $row->name ?: ''];
        if ($row->source_type === 'investors') {
            $vars += \App\Support\PlatformLetter::investorVars(\App\Models\Investor::find($row->source_id));
        }
        return \App\Support\PlatformLetter::merge($text, $vars);
    }

    protected function firstName(?string $full): ?string
    {
        $full = trim((string) $full);
        return $full === '' ? null : explode(' ', $full)[0];
    }

    private function paceKey(string $email): string
    {
        return 'platform_campaign_pace_' . (\App\Services\Sales\OutreachQueue::isFree($email) ? 'free' : 'business');
    }

    /**
     * May this paced row go now? Waits in-process when the gap ends within
     * this run's time budget (the command runs every minute), else says no.
     */
    private function paceAllows(string $email, float $started): bool
    {
        $kind = \App\Services\Sales\OutreachQueue::isFree($email) ? 'free' : 'business';
        $gap  = max(1, (int) (\App\Services\Sales\OutreachAutoSend::defaults()['gap_' . $kind] ?? ($kind === 'free' ? 60 : 10)));

        $last = (int) \Illuminate\Support\Facades\Cache::get($this->paceKey($email), 0);
        $auto = \App\Services\Sales\OutreachAutoSend::state()['last'][$kind] ?? null;   // the outreach sender's last send
        if ($auto) {
            try { $last = max($last, \Carbon\Carbon::parse($auto)->getTimestamp()); } catch (\Throwable $e) {}
        }

        $wait = ($last + $gap) - time();
        if ($wait <= 0) {
            return true;
        }
        if ((microtime(true) - $started) + $wait > 50) {
            return false;   // next minute's run picks it up
        }
        sleep($wait);
        return true;
    }

}
