<?php
// MARKER-PATCH-527

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantDeliveryProposal;
use App\Models\Tenant\TenantRouteWindow;
use App\Services\Sms\SmsService;
use App\Services\Tenant\TenantDeliveryNotificationService; // MARKER-PATCH-531
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * DeliveryProposalService — the "Ready → propose/confirm" engine.
 *
 * When work hits Completed, staff can text the customer the next open
 * delivery windows. The customer confirms one on a public token page
 * (/d/{token}); if they don't reply by the tenant's assume-first hour,
 * the assume-first cron locks in the first proposed window.
 *
 * Candidates start tomorrow (tenant-local) — same-day delivery is never
 * proposed, matching the same-day-pickup stance from patch-524.
 */
class DeliveryProposalService
{
    public function __construct(private readonly Tenant $tenant) {}

    public static function forTenant(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /**
     * Next open window occurrences, starting tomorrow, scanning up to
     * 14 days out. Returns up to $count entries:
     * [{window_id, date, label, day_label, remaining}]
     */
    public function candidates(?int $count = null): array
    {
        $settings = (array) ($this->tenant->settings ?? []);
        $count    = $count ?: max(1, min(6, (int) ($settings['pd_windows_offered'] ?? 3)));
        $tz       = $this->tenant->timezone();

        $windows = TenantRouteWindow::query()
            ->where('tenant_id', $this->tenant->id)
            ->active()
            ->get();
        if ($windows->isEmpty()) return [];

        $out = [];
        for ($off = 1; $off <= 14 && count($out) < $count; $off++) {
            $day = Carbon::now($tz)->startOfDay()->addDays($off);
            foreach ($windows as $w) {
                if (count($out) >= $count) break;
                if (!$w->runsOn($day)) continue;
                $remaining = $w->remainingStops($day);
                if ($remaining < 1) continue;
                $out[] = [
                    'window_id' => $w->id,
                    'date'      => $day->toDateString(),
                    'label'     => $w->label,
                    'day_label' => $day->format('D n/j'),
                    'remaining' => $remaining,
                ];
            }
        }
        return $out;
    }

    /**
     * Create a proposal for a completed appointment and text the customer
     * the confirm link. Returns null when nothing sendable (no phone, no
     * open windows, or a pending proposal already exists).
     */
    public function proposeForAppointment(TenantAppointment $appointment, array $requestedChannels = ['sms']): ?TenantDeliveryProposal // MARKER-PATCH-536
    {
        $appointment->loadMissing('customer');
        $customer = $appointment->customer;
        if (!$customer) return null;
        $wantSms   = in_array('sms', $requestedChannels, true) && !empty($customer->phone);
        $wantEmail = in_array('email', $requestedChannels, true) && !empty($customer->email);
        if (!$wantSms && !$wantEmail) return null;

        // MARKER-PATCH-538 — supersede rather than refuse: old link dies, new one rules
        TenantDeliveryProposal::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('appointment_id', $appointment->id)
            ->whereIn('status', [TenantDeliveryProposal::STATUS_PENDING, TenantDeliveryProposal::STATUS_NO_REPLY])
            ->update(['status' => TenantDeliveryProposal::STATUS_CANCELLED]);

        $windows = $this->candidates();
        if (empty($windows)) return null;

        $settings   = (array) ($this->tenant->settings ?? []);
        $assumeHour = max(12, min(23, (int) ($settings['pd_assume_first_hour'] ?? 20)));
        $tz         = $this->tenant->timezone();

        // Deadline: today's assume hour if it hasn't passed, else tomorrow's —
        // but never at/after the first proposed window's morning. Stored UTC.
        $deadline = Carbon::now($tz)->setTime($assumeHour, 0);
        if ($deadline->isPast()) $deadline->addDay();
        $firstWindowDay = Carbon::parse($windows[0]['date'] . ' 00:00', $tz);
        if ($deadline->gte($firstWindowDay)) {
            $deadline = $firstWindowDay->copy()->subDay()->setTime($assumeHour, 0);
            if ($deadline->isPast()) $deadline = Carbon::now($tz)->addHours(2);
        }

        $proposal = TenantDeliveryProposal::create([
            'tenant_id'      => $this->tenant->id,
            'appointment_id' => $appointment->id,
            'customer_id'    => $customer->id,
            'token'          => Str::random(40),
            'windows'        => $windows,
            'status'         => TenantDeliveryProposal::STATUS_PENDING,
            'expires_at'     => $deadline->copy()->utc(),
        ]);

        $channels = [];
        if ($wantSms) {
            try {
                SmsService::send($this->tenant, $customer->phone, $this->smsBody($proposal, $customer->first_name ?? ''));
                $channels[] = 'sms';
            } catch (\Throwable $e) {
                Log::error('Delivery proposal SMS failed', [
                    'proposal_id' => $proposal->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }
        // MARKER-PATCH-536 — email flavor of the options link
        if ($wantEmail) {
            try {
                \App\Services\EmailService::forTenant($this->tenant)->send('delivery_windows_ready', $customer->email, [
                    'first_name'   => $customer->first_name ?? '',
                    'asset_noun'   => $this->tenant->asset_label_singular ?: 'order',
                    'window_count' => count($windows),
                    'first_window' => $windows[0]['day_label'] . ' ' . $windows[0]['label'],
                    'confirm_url'  => $this->confirmUrl($proposal),
                ]);
                $channels[] = 'email';
            } catch (\Throwable $e) {
                Log::error('Delivery proposal email failed', [
                    'proposal_id' => $proposal->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }
        if ($channels) $proposal->update(['sent_channels' => implode(',', $channels)]);

        return $proposal;
    }

    /**
     * MARKER-PATCH-531 — staff picked a window in the completion modal:
     * schedule the dropoff directly (no proposal/text-link round trip)
     * and send the standard "scheduled" notification.
     */
    public function scheduleDirect(TenantAppointment $appointment, string $windowId, string $date, array $channels = ['sms', 'email']): \App\Models\Tenant\TenantDelivery // MARKER-PATCH-534
    {
        $tz     = $this->tenant->timezone();
        $day    = Carbon::parse($date, $tz);
        $window = TenantRouteWindow::query()
            ->where('tenant_id', $this->tenant->id)->where('id', $windowId)->first();
        if (!$window || !$window->is_active || !$window->runsOn($day)) {
            throw new \RuntimeException('That window is no longer available.');
        }
        if ($day->lt(Carbon::now($tz)->startOfDay()->addDay())) {
            throw new \RuntimeException('Delivery day must be tomorrow or later.');
        }
        if ($window->remainingStops($day) < 1) {
            throw new \RuntimeException('That window just filled up.');
        }

        $appointment->loadMissing('customer');
        $c = $appointment->customer;
        $address = $c
            ? trim(implode(', ', array_filter([
                trim(($c->address_line1 ?? '') . ' ' . ($c->address_line2 ?? '')),
                $c->city ?? null,
                trim(($c->state ?? '') . ' ' . ($c->postcode ?? '')),
              ])))
            : '';
        $start = Carbon::parse($day->toDateString() . ' ' . (string) $window->starts_at, $tz);
        $end   = Carbon::parse($day->toDateString() . ' ' . (string) $window->ends_at, $tz);

        $delivery = \App\Models\Tenant\TenantDelivery::create([
            'tenant_id'      => $this->tenant->id,
            'type'           => \App\Models\Tenant\TenantDelivery::TYPE_DROPOFF,
            'status'         => \App\Models\Tenant\TenantDelivery::STATUS_SCHEDULED,
            'scheduled_at'   => $start->copy()->utc(),
            'window_minutes' => max(15, $start->diffInMinutes($end)),
            'address'        => $address !== '' ? $address : null,
            'customer_id'    => $appointment->customer_id,
            'appointment_id' => $appointment->id,
        ]);

        // Retire any pending proposal so the assume cron can't double-book.
        TenantDeliveryProposal::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('appointment_id', $appointment->id)
            ->where('status', TenantDeliveryProposal::STATUS_PENDING)
            ->update(['status' => TenantDeliveryProposal::STATUS_CANCELLED]);

        // MARKER-PATCH-534 — notify only on the channels staff chose in the modal
        if (!empty($channels)) {
            try {
                TenantDeliveryNotificationService::forTenant($this->tenant)->sendScheduled($delivery, $channels);
            } catch (\Throwable $e) {
                Log::error('Direct-schedule notification failed', [
                    'delivery_id' => $delivery->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $delivery;
    }

    public function confirmUrl(TenantDeliveryProposal $proposal): string
    {
        return rtrim($this->tenant->publicUrl(), '/') . '/d/' . $proposal->token;
    }

    private function smsBody(TenantDeliveryProposal $proposal, string $firstName): string
    {
        $shop = $this->tenant->name;
        $noun = $this->tenant->asset_label_singular ?: 'order'; // MARKER-PATCH-535
        $hi   = $firstName !== '' ? "{$firstName}, your" : 'Your';

        // MARKER-PATCH-534 — no assume-first: the link just offers the windows.
        return "{$shop}: {$hi} {$noun} is ready! Pick a delivery window that works: "
            . $this->confirmUrl($proposal)
            . " Reply STOP to opt out.";
    }
}
