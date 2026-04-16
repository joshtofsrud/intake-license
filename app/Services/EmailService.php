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
            ->where('template_key', $templateKey)
            ->first();

        // Fall back to built-in defaults if the tenant hasn't customized
        if (! $template || ! $template->is_active) {
            $template = $this->defaultTemplate($templateKey);
        }

        if (! $template) return;

        $subject = $this->interpolate($template['subject'], $vars);
        $body    = $this->interpolate($template['body'], $vars);

        $fromName  = $this->tenant->emailFromName();
        $fromEmail = $this->tenant->emailFromAddress();
        $replyTo   = $this->tenant->email_reply_to ?? $fromEmail;

        try {
            Mail::send([], [], function ($message) use (
                $toEmail, $subject, $body, $fromName, $fromEmail, $replyTo
            ) {
                $message
                    ->to($toEmail)
                    ->from($fromEmail, $fromName)
                    ->replyTo($replyTo)
                    ->subject($subject)
                    ->html($body);
            });
        } catch (\Throwable $e) {
            logger()->error("EmailService send failed [{$templateKey}]: {$e->getMessage()}");
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
                'subject' => 'Your booking is confirmed — {{ra_number}}',
                'body'    => "<p>Hi {{first_name}},</p>
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
                'subject' => 'Your work order {{ra_number}} has been updated',
                'body'    => "<p>Hi {{first_name}},</p>
<p>Your work order <strong>{{ra_number}}</strong> at {$shop} has been updated.</p>
<p><strong>New status:</strong> {{status}}</p>
<p>{{status_note}}</p>
<p>— The {$shop} team</p>",
            ],
            'password_reset' => [
                'subject' => 'Reset your password — {{shop_name}}',
                'body'    => "<p>Hi {{name}},</p>
<p>You requested a password reset for your {$shop} staff account.</p>
<p style='margin:24px 0'>
  <a href='{{reset_url}}' style='background:{{accent}};color:{{accent_text}};padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block'>
    Reset my password
  </a>
</p>
<p>This link expires in 60 minutes. If you didn't request this, you can safely ignore this email.</p>
<p>— The {$shop} team</p>",
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
