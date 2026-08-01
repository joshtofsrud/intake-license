#!/usr/bin/env bash
# apply-inbound-cold-routing.sh
# MARKER-INBOUND-COLD — route mail that carries no thread token.
#
# Today the token IS the routing. No token, or a token that no longer
# matches a thread, and the mail is logged and dropped. That makes
# {subdomain}@reply.intake.works meaningless and it makes a stale token a
# silent black hole.
#
# New order of resolution:
#
#   1. dedupe on MessageID  (hoisted — it now guards BOTH paths; Postmark
#      retries, and the cold path can create a customer, so a replay must
#      not run twice)
#   2. token -> thread      (unchanged, still the precise path)
#   3. FALL THROUGH on a missing OR unknown token to the recipient address:
#      localpart before any +tag, matched against tenants.subdomain
#   4. sender's From address -> customer within that tenant (created if new)
#
# Step 3 falling through on an UNKNOWN token, not just a missing one, is
# deliberate and fixes a real edge: a customer composing fresh mail to
# grndctrl+anything@reply.intake.works puts "anything" in MailboxHash, so
# it takes the token path, misses, and would otherwise vanish. Same for a
# reply to a thread that has since been deleted — it now still reaches the
# right shop instead of nowhere.
#
# Recipient comes from OriginalRecipient (the address Postmark actually
# delivered to) before falling back to scanning To/Cc, so a shop address
# on Cc still routes.
#
# SPAM: the address is public and guessable, and the cold path creates
# customer records — so Postmark's own X-Spam-Status verdict is checked
# first and flagged mail is dropped before anything is written. Without
# that gate a scanner would populate a shop's customer list.
#
# Logging is deliberately split so a genuine misroute is findable and a
# scanner is not alarming: unknown_recipient (nobody owns that address) vs
# spam_dropped vs no_sender.
#
# Controller only: optimize:clear + fpm cycle.
set -e

python3 <<'PY'
import io

p = 'app/Http/Controllers/Webhooks/PostmarkInboundController.php'
s = io.open(p, encoding='utf-8').read()

# ---- imports
old = """use App\\Http\\Controllers\\Controller;
use App\\Models\\Tenant\\TenantMessage;
use App\\Models\\Tenant\\TenantThread;"""
assert s.count(old) == 1, 'C1 import anchor'
s = s.replace(old, """use App\\Http\\Controllers\\Controller;
use App\\Models\\Tenant; // MARKER-INBOUND-COLD
use App\\Models\\Tenant\\TenantCustomer; // MARKER-INBOUND-COLD
use App\\Models\\Tenant\\TenantMessage;
use App\\Models\\Tenant\\TenantThread;""")

# ---- routing block: dedupe first, then token, then fall through to address
old = """        $token = trim((string) ($payload['MailboxHash'] ?? ''));
        $msgId = trim((string) ($payload['MessageID'] ?? ''));

        if ($token === '') {
            Log::warning('postmark_inbound.no_token', ['message_id' => $msgId]);
            return response('OK', 200);
        }

        $thread = TenantThread::where('inbound_token', $token)->first();
        if (! $thread) {
            Log::warning('postmark_inbound.unknown_token', ['token' => $token, 'message_id' => $msgId]);
            return response('OK', 200);
        }

        // Dedupe on Postmark's retry behavior.
        if ($msgId !== '' && TenantMessage::where('external_id', $msgId)->exists()) {
            return response('OK', 200);
        }

        $from    = trim((string) ($payload['From'] ?? ($payload['FromFull']['Email'] ?? '')));"""
assert s.count(old) == 1, 'C2 routing block anchor'
s = s.replace(old, """        $token = trim((string) ($payload['MailboxHash'] ?? ''));
        $msgId = trim((string) ($payload['MessageID'] ?? ''));

        // MARKER-INBOUND-COLD — hoisted above routing so it guards both paths.
        // The cold path can CREATE a customer, so a Postmark retry landing
        // twice would be worse here than it ever was on the token path.
        if ($msgId !== '' && TenantMessage::where('external_id', $msgId)->exists()) {
            return response('OK', 200);
        }

        $from = trim((string) ($payload['From'] ?? ($payload['FromFull']['Email'] ?? '')));

        // 1. Token is still the precise route when it resolves.
        $thread = $token !== ''
            ? TenantThread::where('inbound_token', $token)->first()
            : null;

        // 2. MARKER-INBOUND-COLD — otherwise route on the address it was sent
        //    to. Reached by genuinely cold mail AND by a token that no longer
        //    matches (a deleted thread, or a customer composing fresh mail to
        //    grndctrl+something@, which Postmark reads as a MailboxHash).
        if (! $thread) {
            $thread = $this->threadFromRecipient($payload, $from, $msgId, $inbox);
        }

        if (! $thread) {
            return response('OK', 200); // reason already logged
        }
""")

# ---- helper methods on the class tail
old = """        $inbox->postInbound(
            $thread,
            $body !== '' ? $body : '(empty email)',
            $msgId ?: null,
            ['from' => $from, 'subject' => $subject, 'via' => 'postmark_inbound'],
            'email'
        );

        return response('OK', 200);
    }
}"""
assert s.count(old) == 1, 'C3 class tail anchor'
s = s.replace(old, """        $inbox->postInbound(
            $thread,
            $body !== '' ? $body : '(empty email)',
            $msgId ?: null,
            ['from' => $from, 'subject' => $subject, 'via' => 'postmark_inbound'],
            'email'
        );

        return response('OK', 200);
    }

    /**
     * MARKER-INBOUND-COLD — resolve a thread from the recipient address.
     *
     * {subdomain}@reply.intake.works identifies the shop; the From address
     * identifies the customer. Returns null (having logged why) when the
     * mail can't be placed — the caller still answers 200, because Postmark
     * retrying mail we will never route helps nobody.
     */
    private function threadFromRecipient(array $payload, string $from, string $msgId, InboxService $inbox): ?TenantThread
    {
        // The address is public and guessable and this path writes customer
        // rows, so the spam verdict is checked before anything is created.
        if ($this->looksLikeSpam($payload)) {
            Log::info('postmark_inbound.spam_dropped', ['message_id' => $msgId, 'from' => $from]);
            return null;
        }

        $tenant = $this->tenantFromRecipient($payload);
        if (! $tenant) {
            Log::warning('postmark_inbound.unknown_recipient', [
                'message_id' => $msgId,
                'to'         => (string) ($payload['OriginalRecipient'] ?? ($payload['To'] ?? '')),
            ]);
            return null;
        }

        $email = strtolower(trim((string) ($payload['FromFull']['Email'] ?? $from)));
        // Strip a display name if only the combined From was supplied.
        if ($email !== '' && str_contains($email, '<')) {
            $email = trim(strtok(substr($email, strpos($email, '<') + 1), '>'));
        }
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('postmark_inbound.no_sender', ['message_id' => $msgId, 'tenant_id' => $tenant->id]);
            return null;
        }

        [$first, $last] = $this->splitName((string) ($payload['FromFull']['Name'] ?? ''), $email);

        $customer = TenantCustomer::firstOrCreate(
            ['tenant_id' => $tenant->id, 'email' => $email],
            ['first_name' => $first, 'last_name' => $last]
        );

        $thread = $inbox->threadFor($tenant, $customer, 'email');

        $subject = trim((string) ($payload['Subject'] ?? ''));
        if (! $thread->subject && $subject !== '') {
            $thread->update(['subject' => \\Illuminate\\Support\\Str::limit($subject, 180, '')]);
        }

        return $thread;
    }

    /** Match the delivered-to localpart against tenants.subdomain. */
    private function tenantFromRecipient(array $payload): ?Tenant
    {
        $candidates = [];

        // OriginalRecipient is the address Postmark actually delivered to —
        // more reliable than To when the shop was on Cc or Bcc.
        if (! empty($payload['OriginalRecipient'])) {
            $candidates[] = (string) $payload['OriginalRecipient'];
        }
        foreach (['ToFull', 'CcFull'] as $key) {
            foreach ((array) ($payload[$key] ?? []) as $entry) {
                if (! empty($entry['Email'])) {
                    $candidates[] = (string) $entry['Email'];
                }
            }
        }

        foreach ($candidates as $address) {
            $local = strtolower(trim(strtok($address, '@')));
            if ($local === '') {
                continue;
            }
            // Drop any +tag: grndctrl+whatever@ is still Ground Control.
            if (str_contains($local, '+')) {
                $local = strtok($local, '+');
            }

            $tenant = Tenant::where('subdomain', $local)->first();
            if ($tenant) {
                return $tenant;
            }
        }

        return null;
    }

    /** Postmark's own spam verdict, read off the raw headers. */
    private function looksLikeSpam(array $payload): bool
    {
        foreach ((array) ($payload['Headers'] ?? []) as $h) {
            if (strcasecmp((string) ($h['Name'] ?? ''), 'X-Spam-Status') === 0) {
                return stripos(trim((string) ($h['Value'] ?? '')), 'yes') === 0;
            }
        }

        return false;
    }

    /** Best-effort first/last from the From display name, else the address. */
    private function splitName(string $name, string $email): array
    {
        $name = trim($name);
        if ($name === '') {
            return [strtok($email, '@') ?: 'Email', ''];
        }

        $parts = preg_split('/\\s+/', $name, 2);

        return [$parts[0], isset($parts[1]) ? trim($parts[1]) : ''];
    }
}""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- routing paths present ---"
grep -n "threadFromRecipient\|tenantFromRecipient\|looksLikeSpam\|splitName\|spam_dropped\|unknown_recipient" app/Http/Controllers/Webhooks/PostmarkInboundController.php

echo
echo "--- braces / parens ---"
python3 - <<'PY'
import io
s = io.open('app/Http/Controllers/Webhooks/PostmarkInboundController.php', encoding='utf-8').read()
i, n, d, par = 0, len(s), 0, 0
while i < n:
    c = s[i]
    if c == '#' or (c == '/' and i+1 < n and s[i+1] == '/'):
        while i < n and s[i] != '\n': i += 1
    elif c == '/' and i+1 < n and s[i+1] == '*':
        i += 2
        while i+1 < n and not (s[i] == '*' and s[i+1] == '/'): i += 1
        i += 2
    elif c in '"\'':
        q = c; i += 1
        while i < n and s[i] != q:
            if s[i] == '\\': i += 1
            i += 1
        i += 1
    else:
        if c == '{': d += 1
        elif c == '}': d -= 1
        elif c == '(': par += 1
        elif c == ')': par -= 1
        i += 1
print('braces', d, 'parens', par)
PY

echo
echo "apply-inbound-cold-routing: OK"
