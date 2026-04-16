<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'license_id',
        'site_url',
        'site_name',
        'wp_version',
        'plugin_version',
        'type',
        'ip_address',
        'last_seen_at',
        'activated_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /**
     * Normalise a site URL for consistent storage and deduplication.
     * Strips trailing slashes, forces lowercase scheme+host.
     */
    public static function normaliseUrl(string $url): string
    {
        $url = trim($url);
        $parsed = parse_url($url);

        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host   = strtolower($parsed['host']   ?? $url);
        $path   = rtrim($parsed['path'] ?? '', '/');

        return $scheme . '://' . $host . $path;
    }
}
