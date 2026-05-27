<?php

namespace App\Jobs;

use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantNotificationLog;
use App\Services\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * MARKER-PATCH-160 — Sends a POS sale receipt to the customer.
 *
 * Dispatched from:
 *  - RegisterController::storeSale      (direct sale at POS)
 *  - RegisterController::commitDraft    (draft → committed sale)
 *  - RegisterController::resendReceipt  (manual re-send from sale detail)
 *
 * Fail-open: any exception is logged but does not bubble up — a failed
 * receipt should never roll back the sale.
 *
 * Pattern mirrors SendBookingConfirmationJob (3 tries, 60s backoff,
 * one log write per attempt).
 */
class SendSaleReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;

    public function __construct(
        public readonly string $saleId,
        public readonly ?string $overrideEmail = null,
        public readonly string $reason = 'auto_send_on_sale'
    ) {}

    public function handle(): void
    {
        $sale = TenantSale::with(['tenant', 'customer', 'items', 'rangUpBy'])
            ->find($this->saleId);
        if (!$sale) return;

        $tenant = $sale->tenant;
        if (!$tenant) return;

        // Auto-send dispatches respect the tenant's notification toggle.
        // Manual re-sends bypass it (the staff member is explicitly requesting it).
        $isManual = $this->reason === 'manual_resend';
        if (!$isManual && !$tenant->notificationEnabled('sale_receipt_email')) {
            $this->log($sale, '(disabled)', 'skipped', 'disabled by tenant');
            return;
        }

        // Refund sales get their own template (not implemented yet); skip for now.
        if ($sale->isRefund()) {
            $this->log($sale, '(refund)', 'skipped', 'refund receipts not yet implemented');
            return;
        }

        // Resolve recipient.
        $to = $this->overrideEmail ?: $sale->customer?->email;
        if (!$to) {
            $this->log($sale, '(none)', 'skipped', 'no email available');
            return;
        }

        // Build greeting + subject from the tenant's template (or default).
        $vars = [
            'first_name'  => $sale->customer?->first_name ?? '',
            'sale_number' => $sale->sale_number,
            'shop_name'   => $tenant->name,
            'date'        => optional($sale->paid_at ?? $sale->created_at)->format('M j, Y'),
            'total'       => format_money($sale->total_cents),
        ];

        $svc = EmailService::forTenant($tenant);
        $template = \App\Models\Tenant\TenantEmailTemplate::where('tenant_id', $tenant->id)
            ->where('template_type', 'sale_receipt')
            ->first();

        // body_html on this template is treated as a "greeting" string,
        // NOT a full HTML body — the Blade view owns the structure.
        if ($template && $template->is_enabled) {
            $subject  = $svc->interpolate($template->subject ?: 'Receipt from {{shop_name}} — #{{sale_number}}', $vars);
            $greeting = $svc->interpolate($template->body_html ?: '', $vars);
        } else {
            $subject  = $svc->interpolate('Receipt from {{shop_name}} — #{{sale_number}}', $vars);
            $greeting = $svc->interpolate(
                "Thanks for your purchase, {{first_name}}. Here's your receipt for the visit on {{date}}.",
                $vars
            );
        }

        // Tracking pixel toggle (default ON, can be disabled per-tenant).
        $trackPixel = (bool) (($tenant->settings ?? [])['email_track_opens'] ?? true);
        $pixelUrl   = $trackPixel
            ? url('/_e/o/sale/' . $sale->id . '.gif')
            : '';

        $html = View::make('emails.sale-receipt', [
            'tenant'      => $tenant,
            'sale'        => $sale,
            'greeting'    => $greeting,
            'subject'     => $subject,
            'track_pixel' => $trackPixel,
            'pixel_url'   => $pixelUrl,
        ])->render();

        try {
            $ok = $svc->sendRendered('sale_receipt', $to, $subject, $html);
            if ($ok) {
                $this->log($sale, $to, 'sent', null);
            } else {
                // sendRendered returns false on suppression — log as skipped.
                $this->log($sale, $to, 'skipped', 'suppressed or send failed');
            }
        } catch (\Throwable $e) {
            Log::error('SendSaleReceiptJob failed', [
                'sale_id' => $sale->id,
                'error'   => $e->getMessage(),
            ]);
            $this->log($sale, $to, 'failed', $e->getMessage());
        }
    }

    private function log(TenantSale $sale, string $recipient, string $status, ?string $error): void
    {
        TenantNotificationLog::record([
            'tenant_id'     => $sale->tenant_id,
            'event_type'    => 'sale_receipt',
            'channel'       => 'email',
            'recipient'     => $recipient,
            'related_type'  => 'sale',
            'related_id'    => $sale->id,
            'status'        => $status,
            'error_message' => $error,
            'template_key'  => 'sale_receipt',
        ]);
    }
}
