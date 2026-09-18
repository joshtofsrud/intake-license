<?php

namespace App\Filament\Pages;

use App\Models\PlatformInboxMessage;
use App\Models\Tenant;
use App\Services\Tenant\StaffAlertService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Url;

/**
 * MARKER-INBOX — messages and alerts, one place.
 *
 * Messages come from people and expect a reply. Alerts come from the
 * platform and expect a dismiss. Both live in one table so there is one
 * place to look, and the tab keeps them from reading as the same thing.
 */
class PlatformInbox extends Page
{
    use \App\Support\UsesAdminNav;

    protected static ?string $navigationIcon  = 'heroicon-o-inbox';
    protected static ?string $navigationLabel = 'Inbox';
    protected static ?string $navigationGroup = 'Operations';
    protected static ?int    $navigationSort  = 5;
    protected static string  $view            = 'filament.pages.platform-inbox';
    protected static ?string $slug            = 'inbox';

    #[Url(as: 'tab', keep: true)]
    public string $tab = 'messages';      // messages | alerts

    #[Url(as: 'show', keep: true)]
    public string $show = 'open';         // open | archived | spam | all

    #[Url(as: 'id')]
    public ?string $selected = null;

    public string $reply = '';

    public static function getNavigationBadge(): ?string
    {
        $n = \App\Support\PlatformInbox::unreadCount();
        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    // ---------------------------------------------------------------- data

    protected function query()
    {
        $q = PlatformInboxMessage::query()->with('tenant:id,name');
        $q = $this->tab === 'alerts' ? $q->alerts() : $q->messages();

        match ($this->show) {
            'archived' => $q->where('status', 'archived'),
            'spam'     => $q->where('status', 'spam'),
            'all'      => $q,
            default    => $q->whereIn('status', ['new', 'read']),
        };

        return $q->orderByRaw("CASE WHEN status = 'new' THEN 0 ELSE 1 END")
                 ->orderByDesc('created_at');
    }

    protected function getViewData(): array
    {
        $rows = $this->query()->limit(200)->get();
        $sel  = $this->selected ? $rows->firstWhere('id', $this->selected) : null;

        if ($this->selected && ! $sel) {
            $sel = PlatformInboxMessage::with('tenant:id,name')->find($this->selected);
        }

        return [
            'rows'          => $rows,
            'sel'           => $sel,
            'unreadMsgs'    => PlatformInboxMessage::messages()->unread()->count(),
            'unreadAlerts'  => PlatformInboxMessage::alerts()->unread()->count(),
            'notifyAddress' => \App\Support\PlatformInbox::notifyAddress(),
        ];
    }

    // ------------------------------------------------------------- actions

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'alerts' ? 'alerts' : 'messages';
        $this->selected = null;
        $this->reply = '';
    }

    public function setShow(string $show): void
    {
        $this->show = in_array($show, ['open', 'archived', 'spam', 'all'], true) ? $show : 'open';
        $this->selected = null;
    }

    public function open(string $id): void
    {
        $row = PlatformInboxMessage::find($id);
        if (! $row) {
            return;
        }
        if ($row->status === 'new') {
            $row->update(['status' => 'read', 'read_at' => now()]);
        }
        $this->selected = $id;
        $this->reply = '';
    }

    public function setStatus(string $id, string $status): void
    {
        if (! in_array($status, ['new', 'read', 'archived', 'spam'], true)) {
            return;
        }
        PlatformInboxMessage::where('id', $id)->update([
            'status'  => $status,
            'read_at' => $status === 'new' ? null : now(),
        ]);
        if (in_array($status, ['archived', 'spam'], true)) {
            $this->selected = null;
        }
    }

    /** Dismiss = archive, for an alert. Same row state, different word. */
    public function dismiss(string $id): void
    {
        $this->setStatus($id, 'archived');
    }

    public function dismissAllAlerts(): void
    {
        PlatformInboxMessage::alerts()->whereIn('status', ['new', 'read'])
            ->update(['status' => 'archived', 'read_at' => now()]);
        $this->selected = null;
    }

    /**
     * Reply. A tenant's message goes onto their staff bell (every one of
     * their staff sees it next time anyone opens the admin) AND to the
     * owner's email. A stranger's message is answered by email to the
     * address they gave.
     */
    public function sendReply(): void
    {
        $text = trim($this->reply);
        $row  = $this->selected ? PlatformInboxMessage::find($this->selected) : null;

        if (! $row || $text === '' || ! $row->isMessage()) {
            return;
        }

        $sent = false;

        if ($row->tenant_id && ($tenant = Tenant::find($row->tenant_id))) {
            try {
                app(StaffAlertService::class)->broadcast($tenant, [
                    'title'       => 'A reply from Intake',
                    'body'        => $text,
                    'priority'    => 'high',
                    'show_banner' => true,
                    'send_email'  => true,
                ]);
                $sent = true;
            } catch (\Throwable $e) {
                Log::error('Inbox reply to tenant failed', ['id' => $row->id, 'error' => $e->getMessage()]);
            }
        } elseif ($row->email) {
            // MARKER-PLATFORM-INBOUND — Reply-To is now a tokenised platform
            // address, so their answer comes back into this thread instead of
            // a mailbox the app can't read. Falls back to the from address
            // when inbound isn't configured.
            $to      = $row->email;
            $token   = $row->replyToken();
            $replyTo = \App\Services\Platform\PlatformMailer::replyTo($token)
                ?: \App\Support\PlatformInbox::notifyAddress();
            $subject = 'Re: ' . ($row->subject ?: 'your message to Intake');
            try {
                Mail::raw($text, function ($m) use ($to, $subject, $replyTo) {
                    $m->to($to)->subject($subject);
                    if ($replyTo) {
                        $m->replyTo($replyTo);
                    }
                });
                $sent = true;
            } catch (\Throwable $e) {
                Log::error('Inbox reply email failed', ['id' => $row->id, 'error' => $e->getMessage()]);
            }
        }

        if ($sent) {
            $row->update([
                'status'          => 'read',
                'replied_at'      => now(),
                'reply_body'      => $text,
                'last_message_at' => now(), // MARKER-PLATFORM-INBOUND
            ]);

            // MARKER-PLATFORM-INBOUND — record our side of the conversation too,
            // so the thread reads in order rather than as one stored last reply.
            \App\Models\PlatformInboxReply::create([
                'message_id' => $row->id,
                'direction'  => 'out',
                'from_email' => \App\Services\Platform\PlatformMailer::fromAddress(),
                'body'       => $text,
            ]);

            $this->reply = '';
        }
    }
}
