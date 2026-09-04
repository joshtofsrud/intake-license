<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantEmailTemplate;
use Illuminate\Support\Facades\Mail;

class EmailService
{
    private Tenant $tenant;

    public function __construct(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }

    // MARKER-DEMO-COMMS — demo tenants never reach a real transport. The
    // send "succeeds" (callers behave exactly as live and record the
    // message); the record carries meta.demo_suppressed via InboxService.
    private function deliver(\Closure $build): void
    {
        if ($this->tenant && $this->tenant->is_demo) {
            \Illuminate\Support\Facades\Log::info('MARKER-DEMO-COMMS email suppressed (demo tenant)', ['tenant' => $this->tenant->id]);
            return;
        }
        Mail::send([], [], $build);
    }

    // ----------------------------------------------------------------
    // Send a named template email
    // $vars are merged into the template subject + body
    // ----------------------------------------------------------------
    public function send(string $templateKey, string $toEmail, array $vars = []): void
    {
        $template = TenantEmailTemplate::where('tenant_id', $this->tenant->id)
            ->where('template_type', $templateKey)
            ->first();

        // Fall back to built-in defaults if the tenant hasn't customized.
        // Eloquent rows expose 'subject' + 'body_html'; defaults are returned as
        // an array with the same keys for shape consistency.
        if (! $template || ! $template->is_enabled) {
            $template = $this->defaultTemplate($templateKey);
            if (! $template) return;
            $subject = $this->interpolate($template['subject'], $vars);
            $body    = $this->interpolate($template['body_html'], $vars);
        } else {
            $subject = $this->interpolate($template->subject, $vars);
            $body    = $this->interpolate($template->body_html, $vars);
        }

        $fromName  = $this->tenant->emailFromName();
        $fromEmail = $this->tenant->emailFromAddress();
        // MARKER-TXN-THREADING — thread it and reply into Intake; the old
        // fallback pointed at {subdomain}@intake.works, which receives nothing.
        $replyTo   = $this->threadedReplyTo($toEmail, $templateKey, $subject)
                  ?: ($this->tenant->email_reply_to ?? $fromEmail);

        // MARKER-PATCH-146 — suppression gate
        if (\App\Models\Tenant\TenantEmailSuppression::isSuppressed($this->tenant->id, $toEmail)) {
            logger()->info("EmailService skipped (suppressed) [{$templateKey}]", [
                'tenant_id' => $this->tenant->id,
                'to'        => $toEmail,
            ]);
            return;
        }

        // MARKER-PATCH-410 — wrap the body in the same branded chrome (header +
        // logo, footer) that receipts and inbox replies use, so every customer
        // email is one consistent system instead of bare text. body_html is the
        // content INSIDE the frame, exactly like the receipt greeting sits inside
        // the receipt layout.
        $html = $this->renderHtml($body);

        // MARKER-EMAIL-LEDGER — row first, voided on failure.
        $ledger = \App\Services\EmailLedger::begin(
            $this->tenant->id, \App\Services\EmailLedger::kindFor($templateKey), $toEmail, $templateKey
        );

        try {
            $tenantId = $this->tenant->id;
            $this->deliver(function ($message) use (
                $toEmail, $subject, $html, $fromName, $fromEmail, $replyTo, $tenantId
            ) {
                $message
                    ->to($toEmail)
                    ->from($fromEmail, $fromName)
                    ->replyTo($replyTo)
                    ->subject($subject)
                    ->html($html);
                // MARKER-PATCH-146 — header lets the bounce webhook map events back to tenants.
                // MARKER-PATCH-201 — also set Postmark Metadata (X-PM-Metadata-*) so the
                // Postmark bounce/complaint webhook can map events back to the tenant
                // (Postmark surfaces Metadata, not arbitrary custom headers, in webhooks).
                $h = $message->getHeaders();
                $h->addTextHeader('X-Tenant-Id', $tenantId);
                $h->addTextHeader('X-PM-Metadata-tenant_id', $tenantId);
            });
            \App\Services\EmailLedger::markSent($ledger); // MARKER-EMAIL-LEDGER
        } catch (\Throwable $e) {
            \App\Services\EmailLedger::void($ledger); // MARKER-EMAIL-LEDGER
            logger()->error("EmailService send failed [{$templateKey}]: {$e->getMessage()}");
        }
    }

    // ----------------------------------------------------------------
    // MARKER-PATCH-403 — build the per-thread inbound Reply-To address.
    // The thread's random inbound_token rides in the localpart as a "+tag";
    // Postmark surfaces it as MailboxHash on the inbound webhook, which the
    // PostmarkInboundController decodes back to the thread. Returns null when
    // no inbound address is configured or no token is given (caller falls back
    // to the tenant's normal Reply-To — fail-safe).
    // ----------------------------------------------------------------
    /**
     * MARKER-TXN-THREADING — thread this send and hand back its Reply-To.
     *
     * Returns null when the recipient is not a customer of this tenant, which
     * is the guard that keeps staff mail (schedule publishes, announcements,
     * time-clock) out of the customer inbox — a staff member replying to their
     * own schedule must not become a customer record.
     */
    private function threadedReplyTo(string $toEmail, string $templateKey, string $subject): ?string
    {
        if (! config('services.postmark.inbound_address')) {
            return null;
        }

        $customer = \App\Models\Tenant\TenantCustomer::where('tenant_id', $this->tenant->id)
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($toEmail))])
            ->first();

        if (! $customer) {
            return null;
        }

        try {
            $inbox  = app(\App\Services\Tenant\InboxService::class);
            $thread = $inbox->threadFor($this->tenant, $customer, 'email');
            $inbox->recordTransactionalEmail($thread, $subject, $templateKey);

            return self::inboundReplyAddress($thread->inbound_token);
        } catch (\Throwable $e) {
            // Threading is an enhancement — never let it stop the send.
            logger()->error('email.threading_failed', [
                'tenant_id' => $this->tenant->id,
                'template'  => $templateKey,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    public static function inboundReplyAddress(?string $token): ?string
    {
        $token = trim((string) $token);
        $base  = trim((string) config('services.postmark.inbound_address'));
        if ($token === '' || $base === '' || ! str_contains($base, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $base, 2);
        return $local . '+' . $token . '@' . $domain;
    }

    // ----------------------------------------------------------------
    // MARKER-PATCH-160 — send pre-rendered HTML
    // Used by receipts which need Blade-level looping for line items.
    // Mirrors send(): suppression-gated, X-Tenant-Id header, from/reply-to.
    // MARKER-PATCH-403 — optional $replyToOverride lets the inbox reply inject a
    // token-bearing Reply-To; all other callers keep the tenant's normal one.
    // ----------------------------------------------------------------
    public function sendRendered(
        string $templateKey,
        string $toEmail,
        string $subject,
        string $html,
        ?string $replyToOverride = null
    ): bool {
        $fromName  = $this->tenant->emailFromName();
        $fromEmail = $this->tenant->emailFromAddress();
        // MARKER-TXN-THREADING — an override means the inbox already owns this
        // send (postOutbound records it itself), so don't thread it twice.
        $replyTo   = $replyToOverride
                  ?: $this->threadedReplyTo($toEmail, $templateKey, $subject)
                  ?: ($this->tenant->email_reply_to ?? $fromEmail);

        if (\App\Models\Tenant\TenantEmailSuppression::isSuppressed($this->tenant->id, $toEmail)) {
            logger()->info("EmailService::sendRendered skipped (suppressed) [{$templateKey}]", [
                'tenant_id' => $this->tenant->id,
                'to'        => $toEmail,
            ]);
            return false;
        }

        // MARKER-EMAIL-LEDGER — row first, voided on failure.
        $ledger = \App\Services\EmailLedger::begin(
            $this->tenant->id, \App\Services\EmailLedger::kindFor($templateKey), $toEmail, $templateKey
        );

        try {
            $tenantId = $this->tenant->id;
            $this->deliver(function ($message) use (
                $toEmail, $subject, $html, $fromName, $fromEmail, $replyTo, $tenantId, $templateKey
            ) {
                $message
                    ->to($toEmail)
                    ->from($fromEmail, $fromName)
                    ->replyTo($replyTo)
                    ->subject($subject)
                    ->html($html);
                $headers = $message->getHeaders();
                $headers->addTextHeader('X-Tenant-Id', $tenantId);
                $headers->addTextHeader('X-Mail-Template', $templateKey);
                // MARKER-PATCH-201 — Postmark Metadata for webhook tenant mapping.
                $headers->addTextHeader('X-PM-Metadata-tenant_id', $tenantId);
            });
            \App\Services\EmailLedger::markSent($ledger); // MARKER-EMAIL-LEDGER
            return true;
        } catch (\Throwable $e) {
            \App\Services\EmailLedger::void($ledger); // MARKER-EMAIL-LEDGER
            logger()->error("EmailService::sendRendered failed [{$templateKey}]: {$e->getMessage()}");
            return false;
        }
    }

    // ----------------------------------------------------------------
    // MARKER-PATCH-204 — send a rendered HTML email WITH a PDF attachment.
    // Mirrors sendRendered(): suppression check, tenant from/reply-to,
    // Postmark metadata header for bounce-to-tenant mapping.
    // ----------------------------------------------------------------
    public function sendRenderedWithPdf(
        string $templateKey,
        string $toEmail,
        string $subject,
        string $html,
        string $pdfBytes,
        string $filename
    ): bool {
        $fromName  = $this->tenant->emailFromName();
        $fromEmail = $this->tenant->emailFromAddress();
        // MARKER-TXN-THREADING — invoices go to customers, same as receipts.
        $replyTo   = $this->threadedReplyTo($toEmail, $templateKey, $subject)
                  ?: ($this->tenant->email_reply_to ?? $fromEmail);

        if (\App\Models\Tenant\TenantEmailSuppression::isSuppressed($this->tenant->id, $toEmail)) {
            logger()->info("EmailService::sendRenderedWithPdf skipped (suppressed) [{$templateKey}]", [
                'tenant_id' => $this->tenant->id,
                'to'        => $toEmail,
            ]);
            return false;
        }

        // MARKER-EMAIL-LEDGER — row first, voided on failure.
        $ledger = \App\Services\EmailLedger::begin(
            $this->tenant->id, \App\Services\EmailLedger::kindFor($templateKey), $toEmail, $templateKey
        );

        try {
            $tenantId = $this->tenant->id;
            $this->deliver(function ($message) use (
                $toEmail, $subject, $html, $fromName, $fromEmail, $replyTo, $tenantId, $templateKey, $pdfBytes, $filename
            ) {
                $message
                    ->to($toEmail)
                    ->from($fromEmail, $fromName)
                    ->replyTo($replyTo)
                    ->subject($subject)
                    ->html($html);
                $message->attachData($pdfBytes, $filename, ['mime' => 'application/pdf']);
                $headers = $message->getHeaders();
                $headers->addTextHeader('X-Tenant-Id', $tenantId);
                $headers->addTextHeader('X-Mail-Template', $templateKey);
                $headers->addTextHeader('X-PM-Metadata-tenant_id', $tenantId);
            });
            \App\Services\EmailLedger::markSent($ledger); // MARKER-EMAIL-LEDGER
            return true;
        } catch (\Throwable $e) {
            \App\Services\EmailLedger::void($ledger); // MARKER-EMAIL-LEDGER
            logger()->error("EmailService::sendRenderedWithPdf failed [{$templateKey}]: {$e->getMessage()}");
            return false;
        }
    }

    // ----------------------------------------------------------------
    // Interpolate {{variable}} placeholders
    // ----------------------------------------------------------------
    public function interpolate(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }
        // Clean up any unreplaced placeholders
        $template = preg_replace('/\{\{[^}]+\}\}/', '', $template);
        return $template;
    }

    // ----------------------------------------------------------------
    // Render a template body as full HTML email
    // ----------------------------------------------------------------
    /**
     * MARKER-TRAFFIC-IDENTITY -- tag links that point at our own marketing site
     * so campaign clicks are attributable.
     *
     * Postmark rewrites every link through its click tracker, so by the time
     * someone lands, document.referrer is Postmark's domain or nothing at all,
     * and the visit reads as Direct. A utm_source survives that redirect
     * because it travels in the destination URL, and the tracker already reads
     * utm_source, utm_medium and utm_campaign.
     *
     * Only OUR domain is touched: rewriting a shop's own links, or a supplier's,
     * would be meddling with someone else's analytics.
     */
    public function tagMarketingLinks(string $html, ?string $campaignName = null): string
    {
        $domain = (string) config('intake.domain', 'intake.works');

        return preg_replace_callback(
            '/href="(https?:\/\/[^"]+)"/i',
            function ($m) use ($domain, $campaignName) {
                $url = $m[1];

                $host = parse_url($url, PHP_URL_HOST) ?: '';
                if ($host === '' || ! str_ends_with(strtolower($host), strtolower($domain))) {
                    return $m[0];   // not ours; leave it alone
                }
                if (str_contains($url, 'utm_source=')) {
                    return $m[0];   // already tagged by hand
                }

                $params = ['utm_source' => 'email', 'utm_medium' => 'campaign'];
                if ($campaignName) {
                    $params['utm_campaign'] = \Illuminate\Support\Str::slug(mb_substr($campaignName, 0, 60));
                }

                $sep = str_contains($url, '?') ? '&' : '?';

                return 'href="' . $url . $sep . http_build_query($params) . '"';
            },
            $html
        ) ?? $html;
    }

    public function renderHtml(string $body, bool $withHeader = true): string
    {
        // MARKER-CAMPAIGN-HDR — a campaign can drop the shop header so it can
        // lead with its own hero instead of stacking one under a logo.
        $accent     = $this->tenant->accent_color     ?? '#BEF264';
        $accentText = \App\Support\ColorHelper::accentTextColor($accent);
        $name       = htmlspecialchars($this->tenant->name);
        $logo       = $this->tenant->emailLogoUrl(); // MARKER-PATCH-411

        // MARKER-CAMPAIGN-CHROME — a width attribute too: Outlook ignores
        // height-only sizing and falls back to the image's native size.
        $header = $logo
            ? "<img src=\"{$logo}\" alt=\"{$name}\" width=\"150\" style=\"width:auto;max-width:150px;height:36px;display:block;margin:0 auto 8px;border:0\">"
            : "<div style=\"font-family:-apple-system,sans-serif;font-size:20px;font-weight:700;color:#f0f0f0\">{$name}</div>";

        $headerRow = '';
        if ($withHeader) {
            $headerRow = <<<HDR
            <tr>
              <td style="background:#111111;padding:24px 32px;text-align:center;border-radius:8px 8px 0 0">
                {$header}
              </td>
            </tr>
            HDR;
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#f4f4f2;font-family:-apple-system,BlinkMacSystemFont,'Inter',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f2;padding:32px 0">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">

      <!-- Header -->
      {$headerRow}

      <!-- Body -->
      <tr>
        <td style="background:#ffffff;padding:32px;border-left:1px solid #e8e8e4;border-right:1px solid #e8e8e4">
          <div style="font-size:15px;line-height:1.7;color:#111111">
            {$body}
          </div>
        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="background:#f8f8f6;padding:20px 32px;text-align:center;border-radius:0 0 8px 8px;border:1px solid #e8e8e4;border-top:none">
          <p style="font-size:12px;color:#888;margin:0">
            This email was sent by {$name}.
            If you have questions, reply to this email.
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
    }

    // ----------------------------------------------------------------
    // Default templates — used when tenant hasn't customised yet
    // ----------------------------------------------------------------
    private function defaultTemplate(string $key): ?array
    {
        $shop = $this->tenant->name;

        $defaults = [
            'booking_confirmation' => [
                'subject'   => 'Your booking is confirmed — {{ra_number}}',
                'body_html' => "<p>Hi {{first_name}},</p>
<p>Your booking with {$shop} is confirmed.</p>
<table style='font-size:14px;line-height:1.8'>
  <tr><td style='color:#666;padding-right:16px'>Reference</td><td><strong>{{ra_number}}</strong></td></tr>
  <tr><td style='color:#666'>Date</td><td>{{appointment_date}}</td></tr>
  <tr><td style='color:#666'>Total</td><td>{{total}}</td></tr>
</table>
<p>We'll be in touch when your work is ready.</p>
<p>— The {$shop} team</p>",
            ],
            // MARKER-GC-EMAILS -- the card visual sits INSIDE the editable body
            // so a shop that customizes the copy keeps the design, and one that
            // never opens the editor still sends something that looks made.
            'gift_card_delivery' => [
                'subject'   => "You've received a {$shop} gift card",
                'body_html' => "<p>{{recipient_name}}, you've been sent a gift card for {$shop}.</p>
<p>{{gift_message}}</p>
<table role='presentation' width='100%' style='margin:20px 0;border-collapse:collapse'>
  <tr><td style='background:#161616;color:#ffffff;border-radius:14px;padding:24px 26px'>
    <div style='font-size:12px;letter-spacing:.1em;text-transform:uppercase;opacity:.55'>{$shop}</div>
    <div style='font-size:34px;font-weight:800;padding-top:10px'>{{card_amount}}</div>
    <div style='font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;opacity:.45;padding-top:16px'>Card code</div>
    <div style='font-family:monospace;font-size:16px;letter-spacing:.16em;padding-top:6px'>{{card_code}}</div>
  </td></tr>
</table>
<p>Use it in store or online — just give this code at checkout. {{gift_policy}}</p>
<p>Check the balance any time at <a href='{{balance_url}}'>{{balance_url}}</a>.</p>
<p>— The {$shop} team</p>",
            ],
            'gift_card_purchase_receipt' => [
                'subject'   => "Your {$shop} gift card purchase",
                'body_html' => "<p>Hi {{first_name}},</p>
<p>Thanks — your gift card purchase went through.</p>
<table style='font-size:14px;line-height:1.8'>
  <tr><td style='color:#666;padding-right:16px'>Amount</td><td><strong>{{card_amount}}</strong></td></tr>
  <tr><td style='color:#666'>Type</td><td>{{card_type}}</td></tr>
  <tr><td style='color:#666'>Delivery</td><td>{{card_delivery}}</td></tr>
</table>
<p>{{card_next_step}}</p>
<p>— The {$shop} team</p>",
            ],
            'status_update' => [
                'subject'   => 'Your work order {{ra_number}} has been updated',
                'body_html' => "<p>Hi {{first_name}},</p>
<p>Your work order <strong>{{ra_number}}</strong> at {$shop} has been updated.</p>
<p><strong>New status:</strong> {{status}}</p>
<p>{{status_note}}</p>
<p>— The {$shop} team</p>",
            ],
            'password_reset' => [
                'subject'   => 'Reset your password — {{shop_name}}',
                'body_html' => "<p>Hi {{name}},</p>
<p>You requested a password reset for your {$shop} staff account.</p>
<p style='margin:24px 0'>
  <a href='{{reset_url}}' style='background:{{accent}};color:{{accent_text}};padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block'>
    Reset my password
  </a>
</p>
<p>This link expires in 60 minutes. If you didn't request this, you can safely ignore this email.</p>
<p>— The {$shop} team</p>",
            ],

            // MARKER-PATCH-154 — 24-hour appointment reminder
            // MARKER-PATCH-154-FIX1 — uses when_human to handle drop-off mode
            'appointment_reminder' => [
                'subject'   => 'Reminder: your appointment is tomorrow',
                'body_html' => "<p>Hi {{first_name}},</p>
<p>A quick reminder that you have an appointment with {$shop} tomorrow.</p>
<table style='font-size:14px;line-height:1.8;margin:12px 0'>
  <tr><td style='color:#666;padding-right:16px'>When</td><td><strong>{{when_human}}</strong></td></tr>
  <tr><td style='color:#666;padding-right:16px'>Reference</td><td>{{ra_number}}</td></tr>
</table>
<p>See you then!</p>
<p>— The {$shop} team</p>",
            ],

            // MARKER-PATCH-536 — "your work is ready, pick a window" options email
            // MARKER-PATCH-574 — online store order confirmation
            'order_confirmation' => [
                'subject'   => 'Order confirmed — {{order_number}}',
                'body_html' => "<p>Hi {{first_name}},</p>
<p>Thanks for your order with {$shop}! Here's what we have:</p>
{{items_rows}}
<table style='font-size:14px;line-height:1.9;margin-top:8px'>
  <tr><td style='color:#666;padding-right:16px'>Order</td><td><strong>{{order_number}}</strong></td></tr>
  <tr><td style='color:#666;padding-right:16px'>Total</td><td><strong>{{total}}</strong></td></tr>
  <tr><td style='color:#666;padding-right:16px'>Getting it to you</td><td>{{fulfillment_line}}</td></tr>
</table>
<p style='margin-top:14px'><a href='{{order_url}}' style='color:#111'>View your order</a></p>
<p>{{whats_next}}</p>",
            ],
            'delivery_windows_ready' => [
                'subject'   => 'Your {{asset_noun}} is ready — pick a delivery window',
                'body_html' => "<p>Hi {{first_name}},</p>
<p>Good news — your {{asset_noun}} is ready! We have {{window_count}} delivery windows open, starting {{first_window}}.</p>
<p style='margin:20px 0'><a href='{{confirm_url}}' style='display:inline-block;padding:12px 22px;border-radius:8px;background:#111;color:#fff;text-decoration:none;font-weight:600'>Pick your window</a></p>
<p style='font-size:13px;color:#666'>Or copy this link: {{confirm_url}}</p>
<p>— The {$shop} team</p>",
            ],

            // MARKER-PATCH-152C — internal delivery scheduling notifications
            'delivery_pickup_scheduled' => [
                'subject'   => 'Pickup scheduled — {{date_short}} at {{time_start}}',
                'body_html' => "<p>Hi {{first_name}},</p>
<p>{$shop} has scheduled a <strong>pickup</strong> for your {{asset_noun}}.</p>
<table style='font-size:14px;line-height:1.8;margin:12px 0'>
  <tr><td style='color:#666;padding-right:16px'>When</td><td><strong>{{date_human}}</strong></td></tr>
  <tr><td style='color:#666;padding-right:16px'>Window</td><td>{{window}}</td></tr>
  <tr><td style='color:#666;padding-right:16px'>From</td><td>{{address}}</td></tr>
</table>
<p>We'll text you when we're on our way.</p>
<p>— The {$shop} team</p>",
            ],
            'delivery_dropoff_scheduled' => [
                'subject'   => 'Dropoff scheduled — {{date_short}} at {{time_start}}',
                'body_html' => "<p>Hi {{first_name}},</p>
<p>Your {{asset_noun}} is ready! {$shop} has scheduled a <strong>dropoff</strong> back to you.</p>
<table style='font-size:14px;line-height:1.8;margin:12px 0'>
  <tr><td style='color:#666;padding-right:16px'>When</td><td><strong>{{date_human}}</strong></td></tr>
  <tr><td style='color:#666;padding-right:16px'>Window</td><td>{{window}}</td></tr>
  <tr><td style='color:#666;padding-right:16px'>To</td><td>{{address}}</td></tr>
</table>
<p>Reply to this email if you need to change anything.</p>
<p>— The {$shop} team</p>",
            ],

            // MARKER-PATCH-155 — 24-hour delivery reminders
            'delivery_pickup_reminder' => [
                'subject'   => 'Reminder: pickup tomorrow at {{time_start}}',
                'body_html' => "<p>Hi {{first_name}},</p>
<p>A quick reminder that {$shop} will pick up your {{asset_noun}} <strong>{{when_human}}</strong>.</p>
<table style='font-size:14px;line-height:1.8;margin:12px 0'>
  <tr><td style='color:#666;padding-right:16px'>When</td><td><strong>{{when_human}}</strong></td></tr>
  <tr><td style='color:#666;padding-right:16px'>Window</td><td>{{window}}</td></tr>
  <tr><td style='color:#666;padding-right:16px'>From</td><td>{{address}}</td></tr>
</table>
<p>Reply to this email if you need to change anything.</p>
<p>— The {$shop} team</p>",
            ],
            'delivery_dropoff_reminder' => [
                'subject'   => 'Reminder: bike dropoff tomorrow at {{time_start}}',
                'body_html' => "<p>Hi {{first_name}},</p>
<p>A quick reminder that {$shop} will drop off your {{asset_noun}} <strong>{{when_human}}</strong>.</p>
<table style='font-size:14px;line-height:1.8;margin:12px 0'>
  <tr><td style='color:#666;padding-right:16px'>When</td><td><strong>{{when_human}}</strong></td></tr>
  <tr><td style='color:#666;padding-right:16px'>Window</td><td>{{window}}</td></tr>
  <tr><td style='color:#666;padding-right:16px'>To</td><td>{{address}}</td></tr>
</table>
<p>Reply to this email if you need to change anything.</p>
<p>— The {$shop} team</p>",
            ],

            // MARKER-PATCH-160 — POS sale receipt (rendered via Blade, not string interpolation)
            // Tenant edits subject + greeting + footer through the existing templates editor;
            // the Blade view reads body_html as a 'greeting' block + footer line.
            'sale_receipt' => [
                'subject'   => 'Receipt from {{shop_name}} — #{{sale_number}}',
                'body_html' => "Thanks for your purchase, {{first_name}}. Here's your receipt for the visit on {{date}}. We appreciate your business and hope to see you again soon.",
            ],

            // MARKER-PATCH-160 — appointment work-order receipt
            'appointment_receipt' => [
                'subject'   => 'Your {{shop_name}} work is complete — #{{ra_number}}',
                'body_html' => "Hi {{first_name}} — we finished the work on your service request. Here's everything we did and what it cost. Reply to this email or call us with any questions.",
            ],
        ];

        return $defaults[$key] ?? null;
    }

    // ----------------------------------------------------------------
    // Static helper for one-off sends without a service instance
    // ----------------------------------------------------------------
    // ----------------------------------------------------------------
    // MARKER-CAMPAIGN-DELIVERY — marketing sends. Three hard rules that
    // invert the transactional path:
    //   1. no broadcast stream configured -> no send, ever
    //   2. no ledger row -> no send (transactional is the other way round)
    //   3. every message carries an unsubscribe footer + one-click headers
    // ----------------------------------------------------------------
    public function sendCampaign(
        string $toEmail,
        string $subject,
        string $bodyHtml,
        string $campaignId,
        string $unsubscribeUrl,
        ?string $sendId = null, // MARKER-CAMPAIGN-RESULTS — recipient row id
        bool $withHeader = true // MARKER-CAMPAIGN-HDR
    ): bool {
        $stream = \App\Services\EmailLedger::broadcastStream();
        if ($stream === null) {
            logger()->warning('sendCampaign refused: no broadcast stream configured');
            return false;
        }

        if (\App\Models\Tenant\TenantEmailSuppression::isSuppressed($this->tenant->id, $toEmail)) {
            return false;
        }

        $ledger = \App\Services\EmailLedger::begin(
            $this->tenant->id, 'campaign', $toEmail, null, null
        );
        if ($ledger === null) {
            // Unbillable marketing mail must not go out.
            logger()->error('sendCampaign refused: ledger row could not be written', [
                'tenant_id' => $this->tenant->id, 'campaign_id' => $campaignId,
            ]);
            return false;
        }
        $ledger->update(['campaign_id' => $campaignId, 'stream' => $stream]);

        $footer = '<p style="font-size:12px;color:#8a8a8e;margin-top:28px;line-height:1.6">'
            . 'You\'re receiving this because you\'re a customer of ' . e($this->tenant->name) . '. '
            . '<a href="' . e($unsubscribeUrl) . '" style="color:#8a8a8e">Unsubscribe</a> from marketing email — '
            . 'receipts and booking confirmations are unaffected.</p>';

        // MARKER-TRAFFIC-IDENTITY — tag before the chrome wraps it, so the
        // footer's own links are left alone.
        $bodyHtml = $this->tagMarketingLinks($bodyHtml, $campaign->name ?? null);
        $html = $this->renderHtml($bodyHtml . $footer, $withHeader);

        $fromName  = $this->tenant->emailFromName();
        $fromEmail = $this->tenant->emailFromAddress();
        $replyTo   = $this->tenant->email_reply_to ?? $fromEmail;

        try {
            $tenantId = $this->tenant->id;
            $this->deliver(function ($message) use (
                $toEmail, $subject, $html, $fromName, $fromEmail, $replyTo,
                $tenantId, $stream, $unsubscribeUrl, $campaignId, $sendId
            ) {
                $message->to($toEmail)
                    ->subject($subject)
                    ->from($fromEmail, $fromName)
                    ->replyTo($replyTo)
                    ->html($html);
                $h = $message->getHeaders();
                $h->addTextHeader('X-Tenant-Id', $tenantId);
                $h->addTextHeader('X-PM-Metadata-tenant_id', $tenantId);
                $h->addTextHeader('X-PM-Message-Stream', $stream);
                // MARKER-CAMPAIGN-RESULTS — Postmark echoes Metadata back on
                // Open/Click/Bounce, which is how events find the right row.
                $h->addTextHeader('X-PM-Metadata-campaign_id', $campaignId);
                if ($sendId !== null) {
                    $h->addTextHeader('X-PM-Metadata-send_id', $sendId);
                }
                $h->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
                $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
            \App\Services\EmailLedger::markSent($ledger);
            return true;
        } catch (\Throwable $e) {
            \App\Services\EmailLedger::void($ledger);
            logger()->error("EmailService::sendCampaign failed: {$e->getMessage()}");
            return false;
        }
    }

    public static function forTenant(Tenant $tenant): self
    {
        return new self($tenant);
    }
}
