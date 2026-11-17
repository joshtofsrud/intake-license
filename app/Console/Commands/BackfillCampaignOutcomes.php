<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-SHOP-CAMPAIGN-REPORT: one-time fill of the report's outcome columns
 * for shop campaigns sent before they existed.
 *
 *   - spam marks: every complaint Postmark reported for a shop, matched to the
 *     newest campaign email that address got before the complaint (exact);
 *   - bounce types: each bounced send gets the type and time of the bounce
 *     Postmark logged for that address after it was sent (exact);
 *   - unsubscribes: a customer's opt-out goes on the newest campaign email
 *     they were sent within --days before it (a close guess, so kept tight).
 *
 * Only fills empty fields; safe to run twice. Without --apply it only counts.
 */
class BackfillCampaignOutcomes extends Command
{
    protected $signature = 'campaigns:backfill-outcomes {--days=7 : how close before an opt-out a campaign email must be to take the unsubscribe} {--apply : write the changes (otherwise counts only)}';
    protected $description = 'Fill spam marks, bounce types and unsubscribes on older shop campaign emails.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $days  = max(1, (int) $this->option('days'));
        $n = ['complaints' => 0, 'bounce_types' => 0, 'unsubscribes' => 0];

        // the newest sent campaign email from this shop to this address, at or before a moment
        $lastSend = function (string $tenantId, ?string $email, ?string $customerId, string $before, ?string $after) {
            return DB::table('tenant_campaign_sends as s')
                ->join('tenant_campaigns as c', 'c.id', '=', 's.campaign_id')
                ->where('c.tenant_id', $tenantId)
                ->whereIn('s.status', ['sent', 'bounced'])
                ->whereNotNull('s.sent_at')
                ->where('s.sent_at', '<=', $before)
                ->when($after, fn ($q) => $q->where('s.sent_at', '>=', $after))
                ->when($customerId, fn ($q) => $q->where('s.customer_id', $customerId),
                                    fn ($q) => $q->whereRaw('LOWER(s.email) = ?', [mb_strtolower(trim((string) $email))]))
                ->orderByDesc('s.sent_at')
                ->first(['s.id', 's.complained_at', 's.unsubscribed_at']);
        };

        // 1. spam marks
        DB::table('tenant_email_bounce_events')->where('event_type', 'complaint')->whereNotNull('tenant_id')
            ->orderBy('id')->chunkById(500, function ($events) use ($lastSend, $apply, &$n) {
                foreach ($events as $e) {
                    $row = $lastSend((string) $e->tenant_id, $e->email, null, (string) $e->created_at, now()->parse($e->created_at)->subDays(30)->toDateTimeString());
                    if (! $row || $row->complained_at) continue;
                    $n['complaints']++;
                    if ($apply) DB::table('tenant_campaign_sends')->where('id', $row->id)->update(['complained_at' => $e->created_at]);
                }
            });

        // 2. bounce types on bounced sends that don't have one
        DB::table('tenant_campaign_sends as s')
            ->join('tenant_campaigns as c', 'c.id', '=', 's.campaign_id')
            ->where('s.status', 'bounced')->whereNull('s.bounce_type')->whereNotNull('s.sent_at')
            ->select('s.id', 's.email', 's.sent_at', 'c.tenant_id')
            ->orderBy('s.id')->chunkById(500, function ($rows) use ($apply, &$n) {
                foreach ($rows as $r) {
                    $ev = DB::table('tenant_email_bounce_events')->where('event_type', 'bounce')
                        ->where('tenant_id', $r->tenant_id)->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $r->email))])
                        ->where('created_at', '>=', $r->sent_at)->orderBy('created_at')->first(['bounce_type', 'created_at']);
                    if (! $ev) continue;
                    $n['bounce_types']++;
                    if ($apply) DB::table('tenant_campaign_sends')->where('id', $r->id)
                        ->update(['bounce_type' => mb_substr((string) ($ev->bounce_type ?: 'Bounce'), 0, 64), 'bounced_at' => $ev->created_at]);
                }
            }, 's.id', 'id');

        // 3. unsubscribes, only when a campaign email came just before
        DB::table('tenant_customers')->whereNotNull('email_marketing_opt_out_at')
            ->orderBy('id')->chunkById(500, function ($customers) use ($lastSend, $apply, $days, &$n) {
                foreach ($customers as $cu) {
                    $at  = (string) $cu->email_marketing_opt_out_at;
                    $row = $lastSend((string) $cu->tenant_id, null, (string) $cu->id, $at, now()->parse($at)->subDays($days)->toDateTimeString());
                    if (! $row || $row->unsubscribed_at) continue;
                    $n['unsubscribes']++;
                    if ($apply) DB::table('tenant_campaign_sends')->where('id', $row->id)->update(['unsubscribed_at' => $at]);
                }
            });

        $this->table(['Filled in', 'Campaign emails'], [
            ['Spam marks (exact)', number_format($n['complaints'])],
            ['Bounce types (exact)', number_format($n['bounce_types'])],
            ["Unsubscribes (within {$days} days of a campaign email)", number_format($n['unsubscribes'])],
        ]);
        $this->line($apply ? 'Written. Running it again changes nothing.' : 'Counted only. Run again with --apply to write these.');
        return self::SUCCESS;
    }
}
