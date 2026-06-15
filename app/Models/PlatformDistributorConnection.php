<?php
// MARKER-PATCH-HLC6

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-level distributor connection (master-admin). Holds the encrypted
 * platform API key used for the tier-1 catalog sync. firstOrCreate seeds from
 * the legacy {CODE}_API_KEY env value so HLC keeps working on first load.
 */
class PlatformDistributorConnection extends Model
{
    use HasUuids;

    protected $table = 'platform_distributor_connections';

    protected $fillable = [
        'distributor_code', 'api_key', 'region', 'auth_style', 'base_url',
        'is_active', 'last_tested_at', 'last_test_status', 'last_test_message',
    ];

    protected $casts = [
        'api_key'        => 'encrypted',
        'is_active'      => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    public static function forCode(string $code): self
    {
        $code = strtoupper($code);

        return static::firstOrCreate(
            ['distributor_code' => $code],
            [
                'api_key'    => env($code . '_API_KEY') ?: null,
                'region'     => 'us',
                'auth_style' => 'authorization_apikey',
                'is_active'  => true,
            ],
        );
    }
}
