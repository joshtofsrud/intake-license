<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** MARKER-PLATFORM-EMAIL — rules over a source, never a fixed list of people. */
class PlatformAudience extends Model
{
    use HasUuids;

    protected $table    = 'platform_audiences';
    protected $fillable = ['name', 'source', 'rules'];
    protected $casts    = ['rules' => 'array'];

    /** The only sources that exist. Shop customers are absent on purpose. */
    public const SOURCES = [
        'tenants'       => 'Tenants',
        'tenant_owners' => 'Tenant owners',
        'prospects'     => 'Prospects',
        'wrote_in'      => 'Wrote in',
        'reps'          => 'Reps & agencies',
    ];
}
