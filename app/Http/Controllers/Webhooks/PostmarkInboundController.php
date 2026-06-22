<?php
// MARKER-PATCH-403

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
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

        $from    = trim((string) ($payload['From'] ?? ($payload['FromFull']['Email'] ?? '')));
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
}
