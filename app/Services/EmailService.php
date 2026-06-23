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
        $replyTo   = $this->tenant->email_reply_to ?? $fromEmail;

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

        try {
            $tenantId = $this->tenant->id;
            Mail::send([], [], function ($message) use (
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
        } catch (\Throwable $e) {
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
        $replyTo   = $replyToOverride ?: ($this->tenant->email_reply_to ?? $fromEmail);

        if (\App\Models\Tenant\TenantEmailSuppression::isSuppressed($this->tenant->id, $toEmail)) {
            logger()->info("EmailService::sendRendered skipped (suppressed) [{$templateKey}]", [
                'tenant_id' => $this->tenant->id,
                'to'        => $toEmail,
            ]);
            return false;
        }

        try {
            $tenantId = $this->tenant->id;
            Mail::send([], [], function ($message) use (
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
            return true;
        } catch (\Throwable $e) {
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
        $replyTo   = $this->tenant->email_reply_to ?? $fromEmail;

        if (\App\Models\Tenant\TenantEmailSuppression::isSuppressed($this->tenant->id, $toEmail)) {
            logger()->info("EmailService::sendRenderedWithPdf skipped (suppressed) [{$templateKey}]", [
                'tenant_id' => $this->tenant->id,
                'to'        => $toEmail,
            ]);
            return false;
        }

        try {
            $tenantId = $this->tenant->id;
            Mail::send([], [], function ($message) use (
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
            return true;
        } catch (\Throwable $e) {
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
    public function renderHtml(string $body): string
    {
        $accent     = $this->tenant->accent_color     ?? '#BEF264';
        $accentText = \App\Support\ColorHelper::accentTextColor($accent);
        $name       = htmlspecialchars($this->tenant->name);
        $logo       = $this->tenant->logo_url;

        $header = $logo
            ? "<img src=\"{$logo}\" alt=\"{$name}\" style=\"height:36px;display:block;margin:0 auto 8px\">"
            : "<div style=\"font-family:-apple-system,sans-serif;font-size:20px;font-weight:700;color:#f0f0f0\">{$name}</div>";

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
      <tr>
        <td style="background:#111111;padding:24px 32px;text-align:center;border-radius:8px 8px 0 0">
          {$header}
        </td>
      </tr>

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

            // MARKER-PATCH-152C — internal delivery scheduling notifications
            'delivery_pickup_scheduled' => [
                'subject'   => 'Pickup scheduled — {{date_short}} at {{time_start}}',
                'body_html' => "<p>Hi {{first_name}},</p>
<p>{$shop} has scheduled a <strong>pickup</strong> for your bike.</p>
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
<p>Your bike is ready! {$shop} has scheduled a <strong>dropoff</strong> back to you.</p>
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
<p>A quick reminder that {$shop} will pick up your bike <strong>{{when_human}}</strong>.</p>
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
<p>A quick reminder that {$shop} will drop off your bike <strong>{{when_human}}</strong>.</p>
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
    public static function forTenant(Tenant $tenant): self
    {
        return new self($tenant);
    }
}
