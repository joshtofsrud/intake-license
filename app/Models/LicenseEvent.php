<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'license_id',
        'event_type',
        'site_url',
        'ip_address',
        'note',
        'plugin_version',
        'wp_version',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /**
     * Convenience: log an event against a license.
     */
    public static function log(
        License $license,
        string  $eventType,
        array   $context = []
    ): static {
        return static::create([
            'license_id'     => $license->id,
            'event_type'     => $eventType,
            'site_url'       => $context['site_url']       ?? null,
            'ip_address'     => $context['ip_address']     ?? null,
            'note'           => $context['note']           ?? null,
            'plugin_version' => $context['plugin_version'] ?? null,
            'wp_version'     => $context['wp_version']     ?? null,
            'created_at'     => now(),
        ]);
    }
}
