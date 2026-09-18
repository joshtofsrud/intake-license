<?php
// MARKER-PATCH-403

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Tenant; // MARKER-INBOUND-COLD
use App\Models\Tenant\TenantCustomer; // MARKER-INBOUND-COLD
use App\Models\Tenant\TenantMessage;
use App\Models\Tenant\TenantThread;
use App\Services\Tenant\InboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound email webhook (Postmark) -> unified inbox.
 *
 * Counterpart to TwilioInboundController for the email channel. The customer's
 * reply is addressed to our Postmark inbound stream with the thread's token in
 * the localpart ("...+{token}@..."), which Postmark surfaces as MailboxHash.
 * That token is the routing authority: it maps to exactly one thread (and thus
 * one tenant + customer), so no per-tenant inbound address is ever provisioned.
 *
 * Posture (the project's 3rd-party-callback rule): ALWAYS answer 2xx, but never
 * process what we can't route or trust:
 *   - missing/unknown MailboxHash token -> log, 200, no processing
 *   - duplicate MessageID               -> 200, no processing (Postmark retries)
 *
 * Body: prefer Postmark's StrippedTextReply (quoted history + signature already
 * removed) and fall back to TextBody. The From address is recorded in meta but
 * is NOT the auth — replies legitimately arrive from forwards/aliases.
 *
 * Security: sits behind the same edge Basic-Auth as the bounce webhook. The
 * per-thread token (96-bit random) is unguessable, so a forged POST cannot land
 * in a real thread without it.
 */
class PostmarkInboundController extends Controller
{
    public function handle(Request $request, InboxService $inbox)
    {
        // Postmark posts application/json; some test pings arrive form-encoded.
        $payload = $request->json()->all();
        if (! is_array($payload) || $payload === []) {
            $payload = $request->all();
        }

        $token = trim((string) ($payload['MailboxHash'] ?? ''));
        $msgId = trim((string) ($payload['MessageID'] ?? ''));

        // MARKER-INBOUND-COLD — hoisted above routing so it guards both paths.
        // The cold path can CREATE a customer, so a Postmark retry landing
        // twice would be worse here than it ever was on the token path.
        if ($msgId !== '' && TenantMessage::where('external_id', $msgId)->exists()) {
            return response('OK', 200);
        }

        $from = trim((string) ($payload['From'] ?? ($payload['FromFull']['Email'] ?? '')));

        // 1. Token is still the precise route when it resolves.
        // 1a. MARKER-PLATFORM-INBOUND — a reply to Intake ITSELF, before any
        //     tenant lookup. Platform tokens live in their own table, and this
        //     patch reserves the 'intake' subdomain so a tenant can never own
        //     the localpart the platform replies on.
        if ($token !== '') {
            $platform = \App\Models\PlatformInboxMessage::where('inbound_token', $token)->first();
            if ($platform) {
                return $this->platformReply($platform, $payload, $from, $msgId);
            }
        }

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

        $subject = trim((string) ($payload['Subject'] ?? ''));
        $body    = (string) ($payload['StrippedTextReply'] ?? '');
        if (trim($body) === '') {
            $body = (string) ($payload['TextBody'] ?? '');
        }
        $body = trim($body);

        // Token is the authority; flag a sender that doesn't match the thread's
        // customer for later review, but still thread the message.
        $onFile = strtolower((string) optional($thread->customer)->email);
        if ($from !== '' && $onFile !== '' && strtolower($from) !== $onFile) {
            Log::info('postmark_inbound.sender_mismatch', [
                'thread_id' => $thread->id,
                'from'      => $from,
                'on_file'   => $onFile,
            ]);
        }

        $inbox->postInbound(
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
            $thread->update(['subject' => \Illuminate\Support\Str::limit($subject, 180, '')]);
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

        $parts = preg_split('/\s+/', $name, 2);

        return [$parts[0], isset($parts[1]) ? trim($parts[1]) : ''];
    }

    /**
     * MARKER-PLATFORM-INBOUND — record a reply to Intake's own mail.
     *
     * Deliberately forgiving: an empty body still creates the turn, because a
     * person who hit reply and sent an image or a one-word answer has still
     * replied, and dropping it recreates the dead end this patch closes. The
     * message is reopened so it can't be answered into an archived thread.
     */
    protected function platformReply(
        \App\Models\PlatformInboxMessage $message,
        array $payload,
        string $from,
        string $msgId
    ) {
        if ($msgId !== '' && \App\Models\PlatformInboxReply::where('external_id', $msgId)->exists()) {
            return response('OK', 200); // Postmark retried; already recorded
        }

        $body = trim((string) ($payload['StrippedTextReply'] ?? ''));
        if ($body === '') {
            $body = trim(strip_tags((string) ($payload['TextBody'] ?? '')));
        }

        \App\Models\PlatformInboxReply::create([
            'message_id'  => $message->id,
            'direction'   => 'in',
            'from_email'  => $from ?: $message->email,
            'body'        => $body !== '' ? $body : '(no text — check the original email)',
            'external_id' => $msgId ?: null,
        ]);

        $message->forceFill([
            'status'          => 'new',
            'read_at'         => null,
            'last_message_at' => now(),
        ])->save();

        return response('OK', 200);
    }
}
