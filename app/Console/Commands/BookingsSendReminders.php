<?php

namespace App\Console\Commands;

use App\Models\PlatformBooking;
use App\Services\Platform\BookingMailer;
use Illuminate\Console\Command;

// MARKER-SCHED-PUBLIC — one reminder per booking, per the type's setting.
class BookingsSendReminders extends Command
{
    protected $signature   = 'bookings:send-reminders';
    protected $description = 'Email reminders for upcoming scheduling calls';

    public function handle(BookingMailer $mailer): int
    {
        $due = PlatformBooking::query()->active()->with('type')
            ->whereNull('reminder_sent_at')
            ->whereNotNull('email')
            ->where('starts_at', '>', now())
            ->whereHas('type', fn ($q) => $q->where('reminder_minutes', '>', 0))
            ->get()
            ->filter(fn ($b) => $b->starts_at->lte(now()->addMinutes($b->type->reminder_minutes)));

        $sent = 0;
        foreach ($due as $b) {
            // Stamp first so a slow mail server can't double-send on overlap.
            $b->forceFill(['reminder_sent_at' => now()])->save();
            if ($mailer->reminder($b)) {
                $sent++;
            }
        }
        $this->info("reminders sent: {$sent}");
        return self::SUCCESS;
    }
}
