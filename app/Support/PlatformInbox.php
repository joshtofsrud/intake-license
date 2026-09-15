<?php

namespace App\Support;

use App\Models\PlatformInboxMessage;
use App\Models\PlatformSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * MARKER-INBOX — the one door into the inbox. Feeds call these; nothing
 * inserts into platform_inbox_messages directly.
 */
class PlatformInbox
{
    /** A message from a person. Emails Josh at the dashboard alert address. */
    public static function message(string $kind, array $data): ?PlatformInboxMessage
    {
        try {
            $row = PlatformInboxMessage::create([
                'kind'       => $kind,
                'status'     => 'new',
                'tenant_id'  => $data['tenant_id']  ?? null,
                'name'       => $data['name']       ?? null,
                'email'      => $data['email']      ?? null,
                'phone'      => $data['phone']      ?? null,
                'company'    => $data['company']    ?? null,
                'subject'    => $data['subject']    ?? null,
                'body'       => $data['body']       ?? null,
                'source_url' => $data['source_url'] ?? null,
                'meta'       => $data['meta']       ?? null,
                'ip'         => $data['ip']         ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('PlatformInbox::message failed', ['kind' => $kind, 'error' => $e->getMessage()]);
            return null;
        }

        self::notify(
            '[Intake] ' . ($data['subject'] ?: ucfirst(str_replace('_', ' ', $kind)) . ' from ' . ($data['name'] ?? 'someone')),
            self::renderNotice($row)
        );

        return $row;
    }

    /** The platform telling you something. No email here — the feed that raised it decides that. */
    public static function alert(array $data): ?PlatformInboxMessage
    {
        try {
            return PlatformInboxMessage::create([
                'kind'      => PlatformInboxMessage::KIND_ALERT,
                'status'    => 'new',
                'tenant_id' => $data['tenant_id'] ?? null,
                'subject'   => $data['subject']   ?? 'Alert',
                'body'      => $data['body']      ?? null,
                'ref_id'    => $data['ref_id']    ?? null,
                'meta'      => $data['meta']      ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('PlatformInbox::alert failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public static function unreadCount(): int
    {
        try {
            return PlatformInboxMessage::unread()->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** The address on the master-admin dashboard's alert setting. */
    public static function notifyAddress(): ?string
    {
        try {
            return PlatformSettings::current()->alert_500_email ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function notify(string $subject, string $body): void
    {
        $to = self::notifyAddress();
        if (! $to) {
            return;
        }
        try {
            Mail::raw($body, function ($m) use ($to, $subject) {
                $m->to($to)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::warning('PlatformInbox notify email failed', ['error' => $e->getMessage()]);
        }
    }

    protected static function renderNotice(PlatformInboxMessage $row): string
    {
        $lines = [];
        if ($row->name)    $lines[] = 'From: ' . $row->name;
        if ($row->email)   $lines[] = 'Email: ' . $row->email;
        if ($row->phone)   $lines[] = 'Phone: ' . $row->phone;
        if ($row->company) $lines[] = 'Company: ' . $row->company;
        if ($row->tenant_id) $lines[] = 'Tenant: ' . $row->tenant_id;
        $lines[] = '';
        $lines[] = (string) $row->body;
        $lines[] = '';
        $lines[] = 'Open the inbox: ' . url('/admin/inbox');
        return implode("\n", $lines);
    }
}
