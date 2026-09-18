<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** MARKER-PLATFORM-TEMPLATES */
class PlatformEmailTemplate extends Model
{
    protected $table        = 'platform_email_templates';
    protected $primaryKey   = 'key';
    public    $incrementing = false;
    protected $keyType      = 'string';

    protected $fillable = ['key', 'subject', 'body', 'enabled', 'updated_by'];
    protected $casts    = ['enabled' => 'boolean'];
}
