<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;

/**
 * MARKER-DEMO-NO-MAIL — the last stop before any email leaves Intake.
 *
 *   - Nothing from a demo shop is sent, whichever code path built it (receipts,
 *     portal invites, password resets, waitlist offers, contact forms…). The
 *     shop is read from the X-Tenant-Id / Postmark metadata header, or the
 *     shop the request or job is running for.
 *   - Addresses on reserved fake domains (example.com, .example, .test,
 *     .invalid, .localhost) can never be delivered, so they're dropped from
 *     any message; one with nobody left isn't sent. Each would only bounce
 *     and count against Intake's sending reputation.
 *
 * Returning false cancels the send (Laravel's MessageSending contract).
 */
class BlockDemoAndReservedMail
{
    public function handle(MessageSending $event)
    {
        $msg = $event->message;

        $tenantId = null;
        foreach (['X-Tenant-Id', 'X-PM-Metadata-tenant_id'] as $h) {
            $header = $msg->getHeaders()->get($h);
            if ($header) { $tenantId = trim((string) $header->getBodyAsString()); break; }
        }
        if (! $tenantId) {
            try { $tenantId = function_exists('tenant') ? optional(tenant())->id : null; } catch (\Throwable $e) { $tenantId = null; }
        }
        if ($tenantId) {
            $isDemo = (bool) \App\Models\Tenant::whereKey($tenantId)->value('is_demo');
            if ($isDemo) {
                Log::info('MARKER-DEMO-NO-MAIL email not sent (demo shop)', [
                    'tenant_id' => $tenantId, 'subject' => $msg->getSubject(),
                ]);
                return false;
            }
        }

        $kept = 0;
        foreach (['To', 'Cc', 'Bcc'] as $field) {
            $getter = 'get' . $field;
            $setter = strtolower($field);
            $list = $msg->{$getter}();
            if (! $list) { continue; }
            $ok = array_values(array_filter($list, fn (Address $a) => ! self::reserved($a->getAddress())));
            if (count($ok) !== count($list)) {
                Log::info('MARKER-DEMO-NO-MAIL dropped reserved-domain recipients', [
                    'dropped' => array_map(fn (Address $a) => $a->getAddress(),
                        array_filter($list, fn (Address $a) => self::reserved($a->getAddress()))),
                ]);
                if ($ok) { $msg->{$setter}(...$ok); } else { $msg->getHeaders()->remove($field); }
            }
            $kept += count($ok);
        }
        if ($kept === 0) {
            return false;
        }

        return null;
    }

    public static function reserved(string $email): bool
    {
        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        if ($domain === '') { return false; }
        if (in_array($domain, ['example.com', 'example.net', 'example.org'], true)) { return true; }
        foreach (['.example', '.test', '.invalid', '.localhost'] as $tld) {
            if ($domain === ltrim($tld, '.') || str_ends_with($domain, $tld)) { return true; }
        }
        return false;
    }
}
