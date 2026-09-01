<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface; // MARKER-SCHED-CARBON-FIX
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

// MARKER-SCHED-FOUNDATION
class PlatformBooking extends Model
{
    public const STATUS_CONFIRMED   = 'confirmed';
    public const STATUS_RESCHEDULED = 'rescheduled';
    public const STATUS_CANCELLED   = 'cancelled';
    public const STATUS_COMPLETED   = 'completed';
    public const STATUS_NO_SHOW     = 'no_show';

    /** Statuses that still hold the slot. */
    public const ACTIVE_STATUSES = [self::STATUS_CONFIRMED, self::STATUS_RESCHEDULED];

    public const STATUS_LABELS = [
        self::STATUS_CONFIRMED   => 'Confirmed',
        self::STATUS_RESCHEDULED => 'Rescheduled',
        self::STATUS_CANCELLED   => 'Cancelled',
        self::STATUS_COMPLETED   => 'Completed',
        self::STATUS_NO_SHOW     => 'No-show',
    ];

    public const SOURCE_PUBLIC = 'public';
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'booking_type_id', 'name', 'email', 'phone', 'company', 'answers',
        'starts_at', 'ends_at', 'timezone', 'location_mode', 'location_detail',
        'status', 'source_kind', 'source_url', 'created_by',
        'message_to_them', 'notes_internal',
    ];

    protected $casts = [
        'answers'          => 'array',
        'starts_at'        => 'datetime',
        'ends_at'          => 'datetime',
        'reminder_sent_at' => 'datetime',
        'completed_at'     => 'datetime',
        'cancelled_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $b) {
            if (empty($b->token)) {
                $b->token = Str::random(48);
            }
        });
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(PlatformBookingType::class, 'booking_type_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PlatformBookingEvent::class, 'booking_id')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ---- scopes ------------------------------------------------------

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function scopeUpcoming(Builder $q): Builder
    {
        return $q->active()->where('starts_at', '>=', now())->orderBy('starts_at');
    }

    /** Bookings overlapping [$from, $to) in UTC. */
    public function scopeBetween(Builder $q, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $q->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    // ---- state -------------------------------------------------------

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst($this->status);
    }

    /** Start in the host (Josh's) timezone, for the admin calendar. */
    public function startsLocal(): Carbon
    {
        return $this->starts_at->copy()->setTimezone(PlatformBookingSetting::get('timezone'));
    }

    public function endsLocal(): Carbon
    {
        return $this->ends_at->copy()->setTimezone(PlatformBookingSetting::get('timezone'));
    }

    /** Start in the booker's timezone (falls back to host). */
    public function startsForBooker(): Carbon
    {
        return $this->starts_at->copy()->setTimezone($this->timezone ?: PlatformBookingSetting::get('timezone'));
    }

    public function publicUrl(): string
    {
        return url('/book/manage/' . $this->token);
    }

    public function logEvent(string $kind, string $actor = 'system', array $meta = []): PlatformBookingEvent
    {
        return $this->events()->create(['kind' => $kind, 'actor' => $actor, 'meta' => $meta ?: null]);
    }

    // ---- transitions (record only — mail is the public patch's job) ----

    public function reschedule(CarbonInterface $newStartUtc, string $actor = 'admin'): void
    {
        $length = (int) abs($this->starts_at->diffInMinutes($this->ends_at));
        $this->logEvent('rescheduled', $actor, [
            'from' => $this->starts_at->toIso8601String(),
            'to'   => $newStartUtc->toIso8601String(),
        ]);
        $this->forceFill([
            'starts_at'        => $newStartUtc,
            'ends_at'          => $newStartUtc->copy()->addMinutes($length),
            'status'           => self::STATUS_RESCHEDULED,
            'reschedule_count' => $this->reschedule_count + 1,
            'reminder_sent_at' => null, // a moved call gets a fresh reminder
        ])->save();
    }

    public function cancel(string $actor = 'admin', ?string $message = null): void
    {
        $this->forceFill([
            'status'         => self::STATUS_CANCELLED,
            'cancelled_at'   => now(),
            'cancelled_by'   => $actor,
            'cancel_message' => $message,
        ])->save();
        $this->logEvent('cancelled', $actor, array_filter(['message' => $message]));
    }

    public function markCompleted(): void
    {
        $this->forceFill(['status' => self::STATUS_COMPLETED, 'completed_at' => now()])->save();
        $this->logEvent('completed', 'admin');
    }

    public function markNoShow(): void
    {
        $this->forceFill(['status' => self::STATUS_NO_SHOW])->save();
        $this->logEvent('no_show', 'admin');
    }
}
