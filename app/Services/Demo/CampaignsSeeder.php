<?php

namespace App\Services\Demo;

use App\Models\Tenant;
use App\Models\Tenant\TenantCampaign;
use App\Models\Tenant\TenantCampaignSend;
use App\Models\Tenant\TenantUser;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds a realistic campaign history: past bulk sends with stats,
 * active automated campaigns, and a draft.
 *
 * Uses the legacy body_html path (blocks is nullable) — skips the
 * block builder system entirely to avoid schema-shape guessing.
 */
class CampaignsSeeder
{
    public function __construct(private readonly Closure $logger) {}

    private function log(string $msg): void { ($this->logger)($msg); }

    public function seed(Tenant $tenant, TenantUser $owner, array $customers): void
    {
        $campaignsCreated = 0;
        $sendsCreated = 0;

        $customerCount = count($customers);
        if ($customerCount === 0) {
            $this->log("  Campaigns: skipped (no customers).");
            return;
        }

        // ---- Past bulk: Spring Tune-Up Special ----
        $c = TenantCampaign::create([
            'tenant_id'        => $tenant->id,
            'name'             => 'Spring Tune-Up Special',
            'type'             => 'bulk',
            'status'           => 'sent',
            'subject'          => 'Ready for spring riding? Tune-ups are 20% off this week',
            'body_html'        => $this->springTuneupHtml(),
            'body_text'        => $this->springTuneupText(),
            'blocks'           => null,
            'targeting'        => ['segment' => 'all'],
            'scheduled_at'     => Carbon::now()->subDays(45)->setTime(9, 0),
            'sent_at'          => Carbon::now()->subDays(45)->setTime(9, 12),
            'total_recipients' => $customerCount,
            'total_sent'       => $customerCount,
            'total_opened'     => (int) round($customerCount * 0.42),
            'total_clicked'    => (int) round($customerCount * 0.12),
            'created_by'       => $owner->id,
            'created_at'       => Carbon::now()->subDays(47),
            'updated_at'       => Carbon::now()->subDays(45),
        ]);
        $sendsCreated += $this->seedSends($c, $customers, 0.42, 0.12);
        $campaignsCreated++;

        // ---- Past bulk: Summer Ride-Ready Week ----
        $c = TenantCampaign::create([
            'tenant_id'        => $tenant->id,
            'name'             => 'Summer Ride-Ready Week',
            'type'             => 'bulk',
            'status'           => 'sent',
            'subject'          => 'Get summer-ready — free safety check with any service',
            'body_html'        => $this->summerRideReadyHtml(),
            'body_text'        => $this->summerRideReadyText(),
            'blocks'           => null,
            'targeting'        => ['segment' => 'all'],
            'scheduled_at'     => Carbon::now()->subDays(14)->setTime(8, 30),
            'sent_at'          => Carbon::now()->subDays(14)->setTime(8, 42),
            'total_recipients' => $customerCount,
            'total_sent'       => $customerCount,
            'total_opened'     => (int) round($customerCount * 0.47),
            'total_clicked'    => (int) round($customerCount * 0.14),
            'created_by'       => $owner->id,
            'created_at'       => Carbon::now()->subDays(16),
            'updated_at'       => Carbon::now()->subDays(14),
        ]);
        $sendsCreated += $this->seedSends($c, $customers, 0.47, 0.14);
        $campaignsCreated++;

        // ---- Targeted automated: 90-day lapsed ----
        TenantCampaign::create([
            'tenant_id'        => $tenant->id,
            'name'             => 'We miss you — come back in',
            'type'             => 'targeted',
            'status'           => 'active',
            'subject'          => 'It has been a while — bring your bike in for a check-up',
            'body_html'        => $this->lapsedHtml(),
            'body_text'        => $this->lapsedText(),
            'blocks'           => null,
            'targeting'        => ['lapsed_days' => 90],
            'scheduled_at'     => null,
            'sent_at'          => null,
            'total_recipients' => 0,
            'total_sent'       => 24,   // sent to 24 lapsed customers over time
            'total_opened'     => 11,
            'total_clicked'    => 3,
            'created_by'       => $owner->id,
            'created_at'       => Carbon::now()->subDays(60),
            'updated_at'       => Carbon::now()->subDays(2),
        ]);
        $campaignsCreated++;

        // ---- Follow-up automated: post-service ----
        TenantCampaign::create([
            'tenant_id'        => $tenant->id,
            'name'             => 'Post-service follow-up',
            'type'             => 'follow_up',
            'status'           => 'active',
            'subject'          => 'How is your bike riding?',
            'body_html'        => $this->followUpHtml(),
            'body_text'        => $this->followUpText(),
            'blocks'           => null,
            'targeting'        => ['delay_days' => 3, 'trigger' => 'closed'],
            'scheduled_at'     => null,
            'sent_at'          => null,
            'total_recipients' => 0,
            'total_sent'       => 183,  // cumulative over time
            'total_opened'     => 112,
            'total_clicked'    => 28,
            'created_by'       => $owner->id,
            'created_at'       => Carbon::now()->subDays(75),
            'updated_at'       => Carbon::now()->subHours(8),
        ]);
        $campaignsCreated++;

        // ---- Draft: Black Friday ----
        TenantCampaign::create([
            'tenant_id'        => $tenant->id,
            'name'             => 'Black Friday Service Special',
            'type'             => 'bulk',
            'status'           => 'draft',
            'subject'          => 'Black Friday — 25% off winter storage prep',
            'body_html'        => $this->blackFridayHtml(),
            'body_text'        => $this->blackFridayText(),
            'blocks'           => null,
            'targeting'        => ['segment' => 'all'],
            'scheduled_at'     => null,
            'sent_at'          => null,
            'total_recipients' => 0,
            'total_sent'       => 0,
            'total_opened'     => 0,
            'total_clicked'    => 0,
            'created_by'       => $owner->id,
            'created_at'       => Carbon::now()->subDays(2),
            'updated_at'       => Carbon::now()->subHours(4),
        ]);
        $campaignsCreated++;

        $this->log("  Campaigns: {$campaignsCreated} ({$sendsCreated} individual sends).");
    }

    /**
     * Create per-customer send records for a bulk campaign, with realistic
     * open/click distribution.
     */
    private function seedSends(TenantCampaign $campaign, array $customers, float $openRate, float $clickRate): int
    {
        $sentAt = $campaign->sent_at ?? Carbon::now();
        $rows = [];

        foreach ($customers as $customer) {
            $opened = (random_int(1, 1000) / 1000) < $openRate;
            $clicked = $opened && (random_int(1, 1000) / 1000) < ($clickRate / max($openRate, 0.01));

            $openedAt = $opened
                ? $sentAt->copy()->addMinutes(random_int(10, 2880))
                : null;
            $clickedAt = $clicked && $openedAt
                ? $openedAt->copy()->addMinutes(random_int(1, 120))
                : null;

            $rows[] = [
                'id'             => (string) Str::uuid(),
                'campaign_id'    => $campaign->id,
                'customer_id'    => $customer->id,
                'email'          => $customer->email,
                'status'         => 'sent',
                'tracking_token' => Str::random(64),
                'open_count'     => $opened ? random_int(1, 3) : 0,
                'click_count'    => $clicked ? random_int(1, 2) : 0,
                'sent_at'        => $sentAt->toDateTimeString(),
                'opened_at'      => $openedAt?->toDateTimeString(),
                'clicked_at'     => $clickedAt?->toDateTimeString(),
                'created_at'     => $sentAt->toDateTimeString(),
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            \Illuminate\Support\Facades\DB::table('tenant_campaign_sends')->insert($chunk);
        }

        return count($rows);
    }

    // ============================================================
    // Email content — simple inline HTML, no block builder
    // ============================================================

    private function springTuneupHtml(): string
    {
        return '<p>Spring is here. Days are longer, trails are drying out, and your bike is ready to come out of the garage.</p><p>This week only, bring your bike in for a <strong>Standard Tune-Up at 20% off</strong>. That covers a full drivetrain clean, brake and shift adjustment, wheel truing, and bolt check. Get back on the road feeling like your bike is brand new.</p><p>No appointment needed — just drop off.</p>';
    }

    private function springTuneupText(): string
    {
        return "Spring is here. Days are longer, trails are drying out, and your bike is ready to come out of the garage.\n\nThis week only, bring your bike in for a Standard Tune-Up at 20% off.\n\nNo appointment needed — just drop off.";
    }

    private function summerRideReadyHtml(): string
    {
        return '<p>Summer riding is the best riding. Make sure your bike is ready for it.</p><p>This week, any service booked gets a <strong>free 15-point safety check</strong>. Brakes, shifting, tire wear, bolt torque — we catch the small stuff before it becomes a problem on the trail.</p><p>Book online or drop off anytime this week.</p>';
    }

    private function summerRideReadyText(): string
    {
        return "Summer riding is the best riding. Make sure your bike is ready for it.\n\nThis week, any service booked gets a free 15-point safety check.\n\nBook online or drop off anytime this week.";
    }

    private function lapsedHtml(): string
    {
        return '<p>It has been about three months since your last service. Whether you have been riding hard or your bike has been sitting, a check-up keeps small issues from becoming big ones.</p><p>Drop off anytime — we will have you back on the road quickly.</p>';
    }

    private function lapsedText(): string
    {
        return "It has been about three months since your last service. A check-up keeps small issues from becoming big ones.\n\nDrop off anytime — we will have you back on the road quickly.";
    }

    private function followUpHtml(): string
    {
        return '<p>Your bike has been back in your hands for a few days now. How is it riding?</p><p>If anything is not quite right, come back in — we stand behind our work. If everything feels great, we would love a quick review.</p>';
    }

    private function followUpText(): string
    {
        return "Your bike has been back in your hands for a few days now. How is it riding?\n\nIf anything is not quite right, come back in — we stand behind our work.";
    }

    private function blackFridayHtml(): string
    {
        return '<p>Winter is coming. Give your bike the off-season care it deserves.</p><p>Black Friday only: <strong>25% off winter storage prep</strong> — full clean, lube, chain check, and tire pressure notes. Ready to ride come spring.</p>';
    }

    private function blackFridayText(): string
    {
        return "Winter is coming. Give your bike the off-season care it deserves.\n\nBlack Friday only: 25% off winter storage prep.";
    }
}
