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
        $thread = TenantThread::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->where('channel', $channel)
            ->orderByDesc('last_message_at')
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
    public function postInbound(TenantThread $thread, string $body, ?string $externalId = null, array $meta = []): TenantMessage
    {
        $message = TenantMessage::create([
            'thread_id'   => $thread->id,
            'direction'   => 'in',
            'kind'        => 'message',
            'body'        => $body,
            'meta'        => $meta ?: null,
            'channel'     => $thread->channel,
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
    public function postOutbound(Tenant $tenant, TenantThread $thread, string $body, ?string $userId = null): TenantMessage
    {
        $customer = $thread->customer;

        if (!$customer || empty($customer->phone)) {
            throw new \RuntimeException('This customer has no phone number on file.');
        }
        if ($customer->sms_opt_out_at !== null) {
            throw new \RuntimeException('This customer has opted out of SMS (STOP). They must text START to resume.');
        }

        SmsService::send($tenant, $customer->phone, $body);

        $message = TenantMessage::create([
            'thread_id'       => $thread->id,
            'direction'       => 'out',
            'kind'            => 'message',
            'body'            => $body,
            'channel'         => $thread->channel,
            'sent_by_user_id' => $userId,
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
