<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** MARKER-PLATFORM-SENDLOG — one row per platform email that isn't a campaign. */
class PlatformEmailSend extends Model
{
    use HasUuids;

    protected $table    = 'platform_email_sends';
    protected $fillable = [
        'kind', 'template_key', 'email', 'subject',
        'tenant_id', 'status', 'error', 'message_id',
    ];
}
