<?php

namespace App\Services\Billing;

use App\Models\BillingNotice;
use App\Models\BillingNoticeTemplate;
use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use App\Services\Tenant\StaffAlertService;
use Illuminate\Support\Facades\Mail;

/**
 * MARKER-BILLING-NOTICES — tells a shop something about their billing, once.
 *
 * Every word comes from a template row, so master admin owns the wording and
 * the channels; this class owns only the timing and the record.
 *
 * The repeat window is what stops three retries becoming three emails. A
 * notice is skipped entirely if the same event went out inside it.
 */
class BillingNoticeService
{
    public function __construct(private StaffAlertService $alerts) {}

    public function notify(Tenant $tenant, string $event, array $context = [], ?string $chargeRunId = null): ?BillingNotice
    {
        $template = BillingNoticeTemplate::find($event);
        if (! $template) {
            logger()->warning('MARKER-BILLING-NOTICES no template', ['event' => $event]);
            return null;
        }

        if ($this->sentRecently($tenant, $event, $template->repeat_after_hours)) {
            return null;
        }

        $link    = 'https://' . $tenant->subdomain . '.' . config('intake.domain') . '/admin/settings/billing-card';
        $tokens  = [
            '{shop}'       => $tenant->name,
            '{link}'       => $link,
            '{card}'       => trim(($tenant->card_brand ? strtoupper($tenant->card_brand) : 'Card') . ' ···· ' . ($tenant->card_last4 ?? '')),
            '{card_last4}' => $tenant->card_last4 ?? '',
            '{expires}'    => $tenant->card_exp_month ? sprintf('%02d/%d', $tenant->card_exp_month, $tenant->card_exp_year) : '',
        ] + $context;

        $subject = strtr($template->subject, $tokens);
        $body    = strtr($template->body, $tokens);

        $notice = BillingNotice::create([
            'tenant_id'     => $tenant->id,
            'event'         => $event,
            'charge_run_id' => $chargeRunId,
            'email_to'      => $template->send_email ? $this->billingEmail($tenant) : null,
        ]);

        if ($template->send_alert) {
            // Critical, so it reaches every shop rather than only those with
            // the alerts add-on — billing is not an optional extra.
            $this->alerts->emit($tenant, 'payment.failed', [
                'title' => $subject,
                'body'  => $body,
                'link'  => '/admin/settings/billing-card',
                'meta'  => ['billing_notice_id' => $notice->id, 'event' => $event],
            ]);
            $notice->forceFill(['alerted' => true])->save();
        }

        if ($template->send_email && $notice->email_to) {
            try {
                $to   = $notice->email_to;
                $text = $body . "\n\n— Intake";
                Mail::raw($text, function ($m) use ($to, $subject) {
                    $m->to($to)->subject($subject);
                });
                $notice->forceFill(['emailed' => true])->save();
            } catch (\Throwable $e) {
                logger()->error('MARKER-BILLING-NOTICES email failed', [
                    'tenant' => $tenant->id, 'event' => $event, 'error' => $e->getMessage(),
                ]);
            }
        }

        logger()->info('MARKER-BILLING-NOTICES sent', [
            'tenant' => $tenant->id, 'event' => $event,
            'alert' => $notice->alerted, 'email' => $notice->emailed,
        ]);

        return $notice;
    }

    /** Close the loop: what the shop did after being told. */
    public function resolve(Tenant $tenant, string $action, ?string $event = null): void
    {
        BillingNotice::where('tenant_id', $tenant->id)
            ->whereNull('resolved_at')
            ->when($event, fn ($q) => $q->where('event', $event))
            ->update(['resolved_by_action' => $action, 'resolved_at' => now()]);
    }

    private function sentRecently(Tenant $tenant, string $event, int $hours): bool
    {
        if ($hours <= 0) return false;   // 0 means always send, e.g. receipts

        return BillingNotice::where('tenant_id', $tenant->id)
            ->where('event', $event)
            ->where('created_at', '>=', now()->subHours($hours))
            ->exists();
    }

    private function billingEmail(Tenant $tenant): ?string
    {
        if ($tenant->billing_email) return $tenant->billing_email;

        // Fall back to an owner rather than nobody.
        return TenantUser::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderByRaw("FIELD(role, 'owner', 'manager', 'staff')")
            ->value('email');
    }
}
