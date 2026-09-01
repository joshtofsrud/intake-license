<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// MARKER-SCHED-FOUNDATION
class PlatformBookingEvent extends Model
{
    protected $fillable = ['booking_id', 'kind', 'actor', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(PlatformBooking::class, 'booking_id');
    }

    public function label(): string
    {
        return match ($this->kind) {
            'created'           => 'Booked',
            'confirmation_sent' => 'Confirmation email sent',
            'reminder_sent'     => 'Reminder sent',
            'rescheduled'       => 'Rescheduled',
            'cancelled'         => 'Cancelled',
            'completed'         => 'Marked completed',
            'no_show'           => 'Marked no-show',
            'note'              => 'Note',
            default             => ucfirst(str_replace('_', ' ', $this->kind)),
        };
    }
}
