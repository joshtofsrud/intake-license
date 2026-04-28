<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantNotificationLog extends Model
{
    use HasUuids;

    protected $table = 'tenant_notification_log';

    protected $fillable = [
        'tenant_id',
        'event_type',
        'channel',
        'recipient',
        'related_type',
        'related_id',
        'status',
        'error_message',
        'template_key',
    ];

    /**
     * Convenience: log a single notification attempt. Centralizes the write
     * so callers don't have to remember the column shape.
     */
    public static function record(array $data): self
    {
        return self::create(array_merge([
            'status' => 'sent',
        ], $data));
    }
}
