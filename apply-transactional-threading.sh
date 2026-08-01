#!/usr/bin/env bash
# apply-transactional-threading.sh
# MARKER-TXN-THREADING — every customer email becomes replyable, and lands
# in the inbox.
#
# Until now only inbox-originated messages carried a token Reply-To.
# Confirmations, reminders and receipts used
#   $tenant->email_reply_to ?? emailFromAddress()
# which for any tenant that never filled the field is
# {subdomain}@intake.works — an address nothing receives. The footer told
# the customer to reply into it.
#
# Option B, per Josh: find-or-create the customer's thread when sending,
# record the send into it, and use THAT thread's token as Reply-To. The
# inbox becomes the record of everything sent, and a reply threads
# precisely even when it comes from a different address than the one on
# file (which the cold path alone would read as a new customer).
#
# THE STAFF-EMAIL TRAP: sendRendered() also carries schedule publishes,
# announcements and time-clock mail to STAFF. Handing those a customer
# reply address would turn a staff member replying to their own schedule
# into a customer record. So the threading only engages when the recipient
# is already a TenantCustomer of this tenant; everything else falls
# through to the existing behaviour untouched.
#
# ORDERING: the recorded send deliberately does NOT bump last_message_at.
# If it did, a busy Saturday of confirmations would push every real
# conversation to the bottom of the list and the inbox would read as a
# transactional feed. Threads still rise when a human writes.
#
# WHAT'S RECORDED is the subject line, not the rendered HTML body — the
# inbox stays readable, and you can see what was sent and when. Full body
# capture is a later call if you want it.
#
# Controller/service change: optimize:clear + fpm cycle.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- 1. tenant
p = 'app/Models/Tenant.php'
s = io.open(p, encoding='utf-8').read()

old = """    public function emailFromAddress(): string
    {
        return $this->email_from_address
            ?: ($this->subdomain . '@intake.works');
    }"""
assert s.count(old) == 1, 'T1 emailFromAddress anchor'
s = s.replace(old, """    public function emailFromAddress(): string
    {
        return $this->email_from_address
            ?: ($this->subdomain . '@intake.works');
    }

    /**
     * MARKER-TXN-THREADING — this shop's public inbound address.
     *
     * {subdomain}@{inbound domain}, derived from POSTMARK_INBOUND_ADDRESS so
     * there is one place to change the domain. Mail here routes by localpart
     * (shop) plus From (customer) — see PostmarkInboundController's cold path.
     *
     * Null when inbound isn't configured, which keeps every caller on their
     * existing fallback rather than printing an address that receives nothing.
     */
    public function inboundAddress(): ?string
    {
        $base = trim((string) config('services.postmark.inbound_address'));
        if ($base === '' || ! str_contains($base, '@') || empty($this->subdomain)) {
            return null;
        }

        return $this->subdomain . '@' . explode('@', $base, 2)[1];
    }""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- 2. inbox service
p = 'app/Services/Tenant/InboxService.php'
s = io.open(p, encoding='utf-8').read()

old = """    public function postNote(TenantThread $thread, string $body, ?string $userId = null): TenantMessage"""
assert s.count(old) == 1, 'I1 postNote anchor'
s = s.replace(old, """    /**
     * MARKER-TXN-THREADING — record an email the system sent, without sending.
     *
     * postOutbound() is the staff reply path and actually dispatches the
     * message; calling it from EmailService would loop. This is record-only.
     *
     * last_message_at is deliberately left alone: a day of booking
     * confirmations must not push real conversations down the list. Threads
     * rise when a person writes, not when the system does.
     */
    public function recordTransactionalEmail(TenantThread $thread, string $subject, string $templateKey): TenantMessage
    {
        return TenantMessage::create([
            'thread_id'    => $thread->id,
            'direction'    => 'out',
            'kind'         => 'transactional',
            'body'         => $subject,
            'meta'         => ['template' => $templateKey, 'via' => 'system_email'],
            'channel'      => 'email',
            'delivered_at' => now(),
        ]);
    }

    public function postNote(TenantThread $thread, string $body, ?string $userId = null): TenantMessage""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- 3. email service
p = 'app/Services/EmailService.php'
s = io.open(p, encoding='utf-8').read()

old = """    public static function inboundReplyAddress(?string $token): ?string"""
assert s.count(old) == 1, 'E1 helper anchor'
s = s.replace(old, """    /**
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

        $customer = \\App\\Models\\Tenant\\TenantCustomer::where('tenant_id', $this->tenant->id)
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($toEmail))])
            ->first();

        if (! $customer) {
            return null;
        }

        try {
            $inbox  = app(\\App\\Services\\Tenant\\InboxService::class);
            $thread = $inbox->threadFor($this->tenant, $customer, 'email');
            $inbox->recordTransactionalEmail($thread, $subject, $templateKey);

            return self::inboundReplyAddress($thread->inbound_token);
        } catch (\\Throwable $e) {
            // Threading is an enhancement — never let it stop the send.
            logger()->error('email.threading_failed', [
                'tenant_id' => $this->tenant->id,
                'template'  => $templateKey,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    public static function inboundReplyAddress(?string $token): ?string""")

# send() — the template path (confirmations, reminders, status updates)
old = """        $replyTo   = $this->tenant->email_reply_to ?? $fromEmail;

        // MARKER-PATCH-146 — suppression gate"""
assert s.count(old) == 1, 'E2 send() replyTo anchor'
s = s.replace(old, """        // MARKER-TXN-THREADING — thread it and reply into Intake; the old
        // fallback pointed at {subdomain}@intake.works, which receives nothing.
        $replyTo   = $this->threadedReplyTo($toEmail, $templateKey, $subject)
                  ?: ($this->tenant->email_reply_to ?? $fromEmail);

        // MARKER-PATCH-146 — suppression gate""")

# sendRendered() — receipts. The override means the inbox already threaded it.
old = """        $replyTo   = $replyToOverride ?: ($this->tenant->email_reply_to ?? $fromEmail);

        if (\\App\\Models\\Tenant\\TenantEmailSuppression::isSuppressed($this->tenant->id, $toEmail)) {
            logger()->info(\"EmailService::sendRendered skipped (suppressed) [{$templateKey}]\", ["""
assert s.count(old) == 1, 'E3 sendRendered replyTo anchor'
s = s.replace(old, """        // MARKER-TXN-THREADING — an override means the inbox already owns this
        // send (postOutbound records it itself), so don't thread it twice.
        $replyTo   = $replyToOverride
                  ?: $this->threadedReplyTo($toEmail, $templateKey, $subject)
                  ?: ($this->tenant->email_reply_to ?? $fromEmail);

        if (\\App\\Models\\Tenant\\TenantEmailSuppression::isSuppressed($this->tenant->id, $toEmail)) {
            logger()->info(\"EmailService::sendRendered skipped (suppressed) [{$templateKey}]\", [""")

# sendRenderedWithPdf() — invoices, also customer-facing, same treatment.
old = """        $replyTo   = $this->tenant->email_reply_to ?? $fromEmail;"""
assert s.count(old) == 1, 'E4 sendRenderedWithPdf replyTo anchor (expected the last remaining one)'
s = s.replace(old, """        // MARKER-TXN-THREADING — invoices go to customers, same as receipts.
        $replyTo   = $this->threadedReplyTo($toEmail, $templateKey, $subject)
                  ?: ($this->tenant->email_reply_to ?? $fromEmail);""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- 4. settings display
p = 'resources/views/tenant/settings/index.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """        <div class=\"ia-form-group\">
          <label class=\"ia-form-label\">Reply-to (optional)</label>"""
assert s.count(old) == 1, 'S1 reply-to field anchor'
s = s.replace(old, """        {{-- MARKER-TXN-THREADING — the shop's public inbound address --}}
        @if($currentTenant->inboundAddress())
          <div class=\"ia-form-group\">
            <label class=\"ia-form-label\">Your inbox email address</label>
            <input type=\"text\" class=\"ia-input\" readonly value=\"{{ $currentTenant->inboundAddress() }}\"
                   onclick=\"this.select()\" style=\"cursor:text\">
            <div style=\"font-size:11px;color:var(--ia-text-dim);margin-top:4px\">
              Anyone who emails this lands in your Inbox. Put it on your website or business cards.
              Replies to emails you send arrive there automatically.
            </div>
          </div>
        @endif
        <div class=\"ia-form-group\">
          <label class=\"ia-form-label\">Reply-to (optional)</label>""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- wiring ---"
grep -n "threadedReplyTo\|inboundAddress\|recordTransactionalEmail" app/Services/EmailService.php app/Models/Tenant.php app/Services/Tenant/InboxService.php resources/views/tenant/settings/index.blade.php

echo
echo "--- balance ---"
python3 - <<'PY'
import io
def bal(p):
    s = io.open(p, encoding='utf-8').read()
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
    return d, par
for f in ['app/Models/Tenant.php', 'app/Services/Tenant/InboxService.php', 'app/Services/EmailService.php']:
    print(f, bal(f))
PY

echo
echo "--- settings blade sweep ---"
python3 - <<'PY'
import io, re
s = re.sub(r'\{\{--.*?--\}\}', '', io.open('resources/views/tenant/settings/index.blade.php', encoding='utf-8').read(), flags=re.S)
print('glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|elseif|else|unless|php|endphp)\b', s)))
o = len(re.findall(r'\B@if\b', s)); c = len(re.findall(r'\B@endif\b', s))
print('@if', o, '@endif', c, 'OK' if o == c else 'MISMATCH')
PY

echo
echo "apply-transactional-threading: OK"
