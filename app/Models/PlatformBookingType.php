<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// MARKER-SCHED-FOUNDATION
class PlatformBookingType extends Model
{
    public const KIND_PUBLIC   = 'public';
    public const KIND_INTERNAL = 'internal';

    public const LOCATION_MEET      = 'meet';
    public const LOCATION_PHONE     = 'phone';
    public const LOCATION_CHOICE    = 'choice';
    public const LOCATION_IN_PERSON = 'in_person';

    public const LOCATION_LABELS = [
        self::LOCATION_MEET      => 'Google Meet',
        self::LOCATION_PHONE     => 'Phone — they give a number',
        self::LOCATION_CHOICE    => 'Their choice: Meet or phone',
        self::LOCATION_IN_PERSON => 'In person',
    ];

    protected $fillable = [
        'slug', 'name', 'kind', 'length_min', 'location_mode', 'meet_url',
        'description', 'questions', 'reminder_minutes', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'questions' => 'array',
        'is_active' => 'boolean',
    ];

    public function bookings(): HasMany
    {
        return $this->hasMany(PlatformBooking::class, 'booking_type_id');
    }

    public function isPublic(): bool
    {
        return $this->kind === self::KIND_PUBLIC;
    }

    /** Offerable on a public page: public kind and switched on. */
    public function isBookable(): bool
    {
        return $this->isPublic() && $this->is_active;
    }

    public function publicUrl(): string
    {
        return url('/book/' . $this->slug);
    }

    /** Questions with defaults filled so views never null-check. */
    public function questionList(): array
    {
        return array_map(function (array $q) {
            return [
                'key'      => $q['key'] ?? \Illuminate\Support\Str::slug($q['label'] ?? 'q', '_'),
                'label'    => $q['label'] ?? '',
                'type'     => $q['type'] ?? 'text',
                'required' => (bool) ($q['required'] ?? false),
                'options'  => $q['options'] ?? [],
            ];
        }, $this->questions ?? []);
    }

    public static function activeOrdered()
    {
        return static::where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
