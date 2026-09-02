<?php
// MARKER-PATCH-221

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantMessage;
use App\Models\Tenant\TenantThread;
use App\Services\Sms\SmsService;

/**
 * InboxService — the ONE writer for threads and messages. Inbound webhook,
 * admin composer, extension offers, and staff-alert system events all post
 * through here so thread state (status, unread, recency) can never drift.
 */
class InboxService
{
    /** Find the customer's thread for a channel, creating it if needed. */
    public function threadFor(Tenant $tenant, TenantCustomer $customer, string $channel = 'sms'): TenantThread
    {
        // MARKER-PATCH-396 — one thread per customer; channels live on messages.
        // Match per customer (ignore channel); $channel only seeds a new thread.
        $thread = TenantThread::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->orderBy('created_at')
            ->first();

        if ($thread) {
            return $thread;
        }

        return TenantThread::create([
            'tenant_id'   => $tenant->id,
            'customer_id' => $customer->id,
            'channel'     => $channel,
            'status'      => 'open',
        ]);
    }

    /** Inbound customer message: needs_reply + unread bump. */
    public function postInbound(TenantThread $thread, string $body, ?string $externalId = null, array $meta = [], ?string $channel = null): TenantMessage
    {
        // MARKER-PATCH-403 — explicit channel (email inbound passes 'email');
        // falls back to the thread seed for the existing SMS caller.
        $message = TenantMessage::create([
            'thread_id'   => $thread->id,
            'direction'   => 'in',
            'kind'        => 'message',
            'body'        => $body,
            'meta'        => $meta ?: null,
            'channel'     => $channel ?? $thread->channel,
            'external_id' => $externalId,
        ]);

        $thread->update([
            'status'          => 'needs_reply',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'unread_count'    => (int) $thread->unread_count + 1,
        ]);

        return $message;
    }

    /**
     * Outbound staff message. Guards opt-out and missing phone; sends via
     * SmsService (per-tenant Twilio creds, null driver in dev) and records
     * the message. Throws \RuntimeException with a human message on guard
     * failure so the controller can flash it.
     */
    public function postOutbound(Tenant $tenant, TenantThread $thread, string $body, ?string $userId = null, ?string $channel = null): TenantMessage
    {
        $customer = $thread->customer;

        // MARKER-PATCH-396 — reply channel: explicit choice, else the customer's
        // last inbound channel, else the thread's seed channel.
        // MARKER-INBOX-NEW — the last inbound also decides the email subject
        // below: "Re:" is only honest when the customer actually wrote first.
        $lastIn = TenantMessage::where('thread_id', $thread->id)
            ->where('direction', 'in')
            ->orderByDesc('created_at')
            ->first();

        $channel = $channel
            ?? optional($lastIn)->channel
            ?? $thread->channel;

        if (in_array($channel, ['web', 'email'], true)) {
            if (!$customer || empty($customer->email)) {
                throw new \RuntimeException('This customer has no email address on file.');
            }
            $mailer  = \App\Services\EmailService::forTenant($tenant);
            $subject = $lastIn
                ? 'Re: your message to ' . $tenant->emailFromName()
                : 'Message from ' . $tenant->emailFromName(); // MARKER-INBOX-NEW
            $html    = $mailer->renderHtml(nl2br(e($body)));
            // MARKER-PATCH-403 — stamp the thread's inbound token into Reply-To so the
            // customer's reply routes back into THIS thread via the Postmark inbound webhook.
            $replyTo = \App\Services\EmailService::inboundReplyAddress($thread->inbound_token);
            if (! $mailer->sendRendered('inbox_reply', $customer->email, $subject, $html, $replyTo)) {
                throw new \RuntimeException('The email could not be sent — check your email settings and try again.');
            }
        } else {
            if (!$customer || empty($customer->phone)) {
                throw new \RuntimeException('This customer has no phone number on file.');
            }
            if ($customer->sms_opt_out_at !== null) {
                throw new \RuntimeException('This customer has opted out of SMS (STOP). They must text START to resume.');
            }

            SmsService::send($tenant, $customer->phone, $body);
        }

        $message = TenantMessage::create([
            'thread_id'       => $thread->id,
            'direction'       => 'out',
            'kind'            => 'message',
            'body'            => $body,
            'channel'         => $channel,
            'sent_by_user_id' => $userId,
            'meta'            => $tenant->is_demo ? ['demo_suppressed' => true] : null, // MARKER-DEMO-COMMS
            'delivered_at'    => now(), // best-effort; delivery receipts are a later enhancement
        ]);

        $thread->update([
            'status'          => 'open',
            'last_message_at' => now(),
            'unread_count'    => 0,
        ]);

        return $message;
    }

    /** Internal note — visible to staff only, never sent. */
    /**
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
            'meta'         => array_filter([
                'template'        => $templateKey,
                'via'             => 'system_email',
                'demo_suppressed' => $thread->tenant?->is_demo ? true : null, // MARKER-DEMO-COMMS
            ]),
            'channel'      => 'email',
            'delivered_at' => now(),
        ]);
    }

    public function postNote(TenantThread $thread, string $body, ?string $userId = null): TenantMessage
    {
        $message = TenantMessage::create([
            'thread_id'       => $thread->id,
            'direction'       => 'system',
            'kind'            => 'internal_note',
            'body'            => $body,
            'channel'         => $thread->channel,
            'sent_by_user_id' => $userId,
        ]);

        $thread->update(['last_message_at' => now()]);

        return $message;
    }

    /** System event (opt-out, offer lifecycle, …) rendered from meta. */
    public function postSystem(TenantThread $thread, string $body, array $meta = [], string $kind = 'system_event'): TenantMessage
    {
        $message = TenantMessage::create([
            'thread_id' => $thread->id,
            'direction' => 'system',
            'kind'      => $kind,
            'body'      => $body,
            'meta'      => $meta ?: null,
            'channel'   => $thread->channel,
        ]);

        $thread->update(['last_message_at' => now()]);

        return $message;
    }

    public function markRead(TenantThread $thread): void
    {
        if ((int) $thread->unread_count !== 0 || $thread->status === 'needs_reply') {
            $thread->update([
                'unread_count' => 0,
                'status'       => $thread->status === 'needs_reply' ? 'open' : $thread->status,
            ]);
        }
    }
}
