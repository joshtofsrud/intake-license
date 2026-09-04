<?php

namespace App\Services\Platform;

use App\Models\PlatformBooking;
use App\Models\PlatformBookingSetting;
use App\Models\PlatformSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * MARKER-SCHED-PUBLIC — every email the scheduling feature sends.
 * Never throws into a controller or Livewire action: a mail failure is
 * logged and recorded as a booking event, the booking itself stands.
 */
class BookingMailer
{
    public function confirmation(PlatformBooking $b): bool
    {
        if (! $b->email) return false;
        $when = $this->whenForBooker($b);
        $rows = $this->rows($b);
        if ($b->message_to_them) {
            $rows[] = ['From ' . $this->hostName(), $b->message_to_them];
        }
        $ok = $this->send($b->email, "You're booked: {$this->typeName($b)} on {$when}", [
            'heading' => "You're booked, " . $this->firstName($b) . '.',
            'intro'   => "Here are the details. Add it to your calendar so it doesn't slip.",
            'rows'    => $rows,
            'cta'     => ['url' => $b->publicUrl(), 'label' => 'Reschedule or cancel'],
            'fine'    => 'Need to move it? The link above lets you pick a new time or cancel.',
        ], $this->ics($b));
        $b->logEvent($ok ? 'confirmation_sent' : 'email_failed', 'system', ['email' => $b->email, 'kind' => 'confirmation']);
        return $ok;
    }

    public function rescheduled(PlatformBooking $b, string $by): bool
    {
        if (! $b->email) return false;
        $when = $this->whenForBooker($b);
        $ok = $this->send($b->email, "Moved: {$this->typeName($b)} is now {$when}", [
            'heading' => 'Your call has a new time.',
            'intro'   => $by === 'admin'
                ? $this->hostName() . ' moved your call. The new details are below — sorry for the shuffle.'
                : 'Confirming the new time you picked.',
            'rows'    => $this->rows($b),
            'cta'     => ['url' => $b->publicUrl(), 'label' => 'Reschedule or cancel'],
            'fine'    => 'The calendar file attached replaces the earlier one.',
        ], $this->ics($b));
        $b->logEvent($ok ? 'rescheduled_email_sent' : 'email_failed', 'system', ['email' => $b->email, 'kind' => 'rescheduled']);
        return $ok;
    }

    public function cancelled(PlatformBooking $b, string $by): bool
    {
        if (! $b->email) return false;
        $rows = [['Was', $this->whenForBooker($b)]];
        if ($by === 'admin' && $b->cancel_message) {
            $rows[] = ['From ' . $this->hostName(), $b->cancel_message];
        }
        $type = $b->type;
        $cta  = $type && $type->isBookable()
            ? ['url' => $type->publicUrl(), 'label' => 'Pick a new time']
            : null;
        $ok = $this->send($b->email, "Cancelled: {$this->typeName($b)} on {$this->whenForBooker($b)}", [
            'heading' => 'Your call is cancelled.',
            'intro'   => $by === 'admin'
                ? $this->hostName() . " had to cancel. If you'd still like to talk, grab another time — no need to start over."
                : "Done — it's off the calendar. You're welcome back anytime.",
            'rows'    => $rows,
            'cta'     => $cta,
            'fine'    => null,
        ], null);
        $b->logEvent($ok ? 'cancelled_email_sent' : 'email_failed', 'system', ['email' => $b->email, 'kind' => 'cancelled']);
        return $ok;
    }

    public function reminder(PlatformBooking $b): bool
    {
        if (! $b->email) return false;
        $ok = $this->send($b->email, "Coming up: {$this->typeName($b)} at " . $b->startsForBooker()->format('g:i a'), [
            'heading' => 'Your call is coming up.',
            'intro'   => 'A quick reminder — details below.',
            'rows'    => $this->rows($b),
            'cta'     => $this->joinCta($b) ?: ['url' => $b->publicUrl(), 'label' => 'View or reschedule'],
            'fine'    => null,
        ], null);
        $b->logEvent($ok ? 'reminder_sent' : 'email_failed', 'system', ['email' => $b->email, 'kind' => 'reminder']);
        return $ok;
    }

    /** New / moved / cancelled by the booker — tells whoever runs the calendar. */
    public function notifyAdmin(PlatformBooking $b, string $event): bool
    {
        $to = PlatformBookingSetting::get('notify_email', '') ?: PlatformSettings::fromAddress();
        if (! $to) {
            Log::warning('MARKER-SCHED-PUBLIC no notify address — booking saved, nobody told', ['id' => $b->id]);
            return false;
        }
        $verb = match ($event) {
            'rescheduled' => 'moved their call to',
            'cancelled'   => 'cancelled their call on',
            default       => 'booked',
        };
        $when = $b->startsLocal()->format('D M j, g:i a') . ' ' . $b->startsLocal()->format('T');
        $rows = [
            ['Who',   $b->name . ($b->company ? ' — ' . $b->company : '')],
            ['Email', $b->email ?: '—'],
            ['When',  $when],
            ['Type',  $this->typeName($b)],
            ['From',  $b->source_url ?: ($b->source_kind === 'manual' ? 'added by hand' : 'public page')],
        ];
        foreach (($b->type?->questionList() ?? []) as $q) {
            $v = $b->answers[$q['key']] ?? '';
            if ($v !== '') $rows[] = [$q['label'], $v];
        }
        return $this->send($to, "{$b->name} {$verb} {$when}", [
            'heading' => ucfirst($verb === 'booked' ? "New booking: {$this->typeName($b)}" : "{$b->name} {$verb} {$b->startsLocal()->format('D M j')}"),
            'intro'   => null,
            'rows'    => $rows,
            'cta'     => ['url' => url('/admin/scheduling'), 'label' => 'Open the calendar'],
            'fine'    => null,
        ], $event === 'cancelled' ? null : $this->ics($b));
    }

    // ---- helpers -----------------------------------------------------

    private function send(string $to, string $subject, array $data, ?string $ics): bool
    {
        try {
            $html = view('emails.platform.booking', $data + ['subject' => $subject])->render();
            $from = PlatformSettings::fromAddress();
            $name = PlatformSettings::fromName() ?: 'Intake';
            // MARKER-PLATFORM-MAIL-LOG — free record so this send is answerable.
            $__mailLog = \App\Services\EmailLedger::platform((string) ($to ?? ''), 'platform_booking');
            Mail::send([], [], function ($m) use ($to, $subject, $html, $ics, $from, $name) {
                $m->to($to)->subject($subject)->html($html);
                if ($from) {
                    $m->from($from, $name);
                }
                if ($ics) {
                    $m->attachData($ics, 'invite.ics', ['mime' => 'text/calendar; charset=utf-8; method=REQUEST']);
                }
            });
            if (isset($__mailLog) && $__mailLog) \App\Services\EmailLedger::markSent($__mailLog);
            return true;
        } catch (\Throwable $e) {
            Log::error('MARKER-SCHED-PUBLIC mail failed', ['to' => $to, 'subject' => $subject, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function rows(PlatformBooking $b): array
    {
        $rows = [
            ['What',  $this->typeName($b) . ' with ' . $this->hostName()],
            ['When',  $this->whenForBooker($b) . ' (' . ($b->timezone ?: PlatformBookingSetting::get('timezone')) . ')'],
        ];
        $where = match ($b->location_mode) {
            'phone'     => $b->location_detail ? "Phone — we'll call you on {$b->location_detail}" : "Phone — we'll call you",
            'in_person' => 'In person' . ($b->location_detail ? " — {$b->location_detail}" : ''),
            default     => $b->location_detail ? "Video call — {$b->location_detail}" : 'Video call — link to follow',
        };
        $rows[] = ['Where', $where];
        return $rows;
    }

    private function joinCta(PlatformBooking $b): ?array
    {
        return ($b->location_mode === 'meet' && $b->location_detail && str_starts_with($b->location_detail, 'http'))
            ? ['url' => $b->location_detail, 'label' => 'Join the call']
            : null;
    }

    private function whenForBooker(PlatformBooking $b): string
    {
        $s = $b->startsForBooker();
        $e = $b->ends_at->copy()->setTimezone($s->getTimezone());
        return $s->format('l, F j') . ' · ' . $s->format('g:i') . '–' . $e->format('g:i a');
    }

    private function typeName(PlatformBooking $b): string
    {
        return $b->type?->name ?? 'Call';
    }

    private function hostName(): string
    {
        return PlatformBookingSetting::get('host_name', '') ?: (PlatformSettings::fromName() ?: 'Intake');
    }

    private function firstName(PlatformBooking $b): string
    {
        return trim(explode(' ', trim($b->name))[0] ?: $b->name);
    }

    /** iCalendar text for the booking. */
    public function ics(PlatformBooking $b): string
    {
        $esc = fn ($s) => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], (string) $s);
        $fmt = fn ($c) => $c->copy()->utc()->format('Ymd\THis\Z');
        $summary  = $this->typeName($b) . ' — ' . $this->hostName();
        $location = $b->location_detail ?: '';
        $desc     = "Booked via Intake. Reschedule or cancel: " . $b->publicUrl();
        $seq      = (int) $b->reschedule_count;
        $status   = $b->status === PlatformBooking::STATUS_CANCELLED ? 'CANCELLED' : 'CONFIRMED';
        return implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Intake//Scheduling//EN', 'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:booking-' . $b->token . '@intake.works',
            'SEQUENCE:' . $seq,
            'DTSTAMP:' . $fmt(now()),
            'DTSTART:' . $fmt($b->starts_at),
            'DTEND:' . $fmt($b->ends_at),
            'SUMMARY:' . $esc($summary),
            'DESCRIPTION:' . $esc($desc),
            'LOCATION:' . $esc($location),
            'URL:' . $esc($b->publicUrl()),
            'STATUS:' . $status,
            'END:VEVENT', 'END:VCALENDAR', '',
        ]);
    }
}
