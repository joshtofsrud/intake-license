<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-SCHED-FOUNDATION — key/value, same shape as RaiseSetting.
class PlatformBookingSetting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = ['key', 'value'];

    public const DEFAULTS = [
        'timezone'         => 'America/Los_Angeles',
        'min_notice_hours' => '24',
        'buffer_minutes'   => '15',
        'max_per_day'      => '4',
        'window_weeks'     => '3',
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        $row = static::find($key);
        if ($row && $row->value !== null && $row->value !== '') {
            return $row->value;
        }
        return $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function getJson(string $key, array $default = []): array
    {
        $raw = static::get($key);
        if ($raw === null) return $default;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }

    public static function putJson(string $key, array $value): void
    {
        static::put($key, json_encode($value));
    }

    /** Everything the availability service needs, in one read. */
    public static function rules(): array
    {
        return [
            'timezone'         => static::get('timezone'),
            'hours'            => static::getJson('hours', [
                'mon' => [], 'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => [],
            ]),
            'min_notice_hours' => (int) static::get('min_notice_hours'),
            'buffer_minutes'   => (int) static::get('buffer_minutes'),
            'max_per_day'      => (int) static::get('max_per_day'),
            'window_weeks'     => (int) static::get('window_weeks'),
            'blocked_dates'    => static::getJson('blocked_dates', []),
        ];
    }
}
