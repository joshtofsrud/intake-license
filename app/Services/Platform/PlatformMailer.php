<?php

namespace App\Services\Platform;

use App\Models\PlatformSettings;
use Illuminate\Support\Facades\Mail;

/**
 * MARKER-PLATFORM-EMAIL — Intake's own sender.
 *
 * Its own Postmark stream, deliberately NOT the tenants' broadcast stream: a
 * complaint against one must never cost the other its reputation or eat its
 * allowance. Unsubscribe headers go on every send, because this is marketing
 * mail from a company, not a shop's receipt.
 */
class PlatformMailer
{
    public static function stream(): ?string
    {
        $s = trim((string) (PlatformSettings::current()->platform_broadcast_stream ?? ''));
        return $s !== '' ? $s : null;
    }

    /**
     * MARKER-PLATFORM-INBOUND — the sender already existed. The Platform email
     * page owns mail_from_address / mail_from_name and PlatformSettings
     * resolves them with their own config fallbacks; adding a second pair of
     * columns just created two places for one address to be wrong.
     */
    public static function fromAddress(): string
    {
        return PlatformSettings::fromAddress() ?: config('mail.from.address', 'hello@intake.works');
    }

    public static function fromName(): string
    {
        return PlatformSettings::fromName() ?: 'Intake';
    }

    /**
     * MARKER-PLATFORM-INBOUND — where a reply should go.
     *
     * intake+{token}@{inbound domain}, from the same POSTMARK_INBOUND_ADDRESS
     * the tenant side derives from, so there is one domain to change. Null
     * when inbound isn't configured — and then callers fall back to the from
     * address, which is honest: a reply reaches a human, just not the app.
     */
    public static function replyTo(?string $token = null): ?string
    {
        $base = trim((string) config('services.postmark.inbound_address'));
        if ($base === '' || ! str_contains($base, '@')) {
            return null;
        }

        $domain = explode('@', $base, 2)[1];

        return $token
            ? 'intake+' . $token . '@' . $domain
            : 'intake@' . $domain;
    }

    public static function postalAddress(): string
    {
        return (string) (PlatformSettings::current()->platform_postal_address ?? '');
    }

    /**
     * Send one campaign email. Returns true when it left the building.
     *
     * NO STREAM, NO SEND — an empty stream setting is the platform-wide off
     * switch, the same way it works for tenants.
     */
    public static function send(
        string $toEmail,
        ?string $toName,
        string $subject,
        string $html,
        string $unsubscribeUrl,
        array $headers = [],
        ?string $replyToken = null   // MARKER-PLATFORM-INBOUND
    ): bool {
        $stream = self::stream();
        if (! $stream) {
            return false;
        }

        $from     = self::fromAddress();
        $fromName = self::fromName();

        // MARKER-PLATFORM-INBOUND — a tokenised Reply-To when inbound is
        // configured, so an answer comes back into the inbox instead of
        // disappearing into a mailbox the app can't see.
        $replyTo = self::replyTo($replyToken) ?: $from;

        Mail::send([], [], function ($message) use ($toEmail, $toName, $subject, $html, $from, $fromName, $stream, $unsubscribeUrl, $headers, $replyTo) {
            $message->to($toEmail, $toName ?: null)
                ->from($from, $fromName)
                ->replyTo($replyTo, $fromName)
                ->subject($subject)
                ->html($html);

            $h = $message->getHeaders();
            $h->addTextHeader('X-PM-Message-Stream', $stream);
            $h->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
            $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            foreach ($headers as $k => $v) {
                $h->addTextHeader($k, (string) $v);
            }
        });

        return true;
    }

    /**
     * MARKER-PLATFORM-SENDLOG — record a non-campaign platform send.
     *
     * Best-effort by design: a failure to write the log must never stop the
     * email, so every caller stays on its own path if this throws.
     */
    public static function log(string $kind, string $email, ?string $subject, array $extra = []): void
    {
        try {
            \App\Models\PlatformEmailSend::create([
                'kind'         => $kind,
                'template_key' => $extra['template_key'] ?? null,
                'email'        => mb_strtolower(trim($email)),
                'subject'      => $subject,
                'tenant_id'    => $extra['tenant_id'] ?? null,
                'status'       => $extra['status'] ?? 'sent',
                'error'        => $extra['error'] ?? null,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('platform send log failed', ['error' => $e->getMessage()]);
        }
    }
}
