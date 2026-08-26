<?php
// MARKER-CAMPAIGNS-CORE

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class TenantEmailCampaign extends Model
{
    protected $table = 'tenant_email_campaigns';

    protected $fillable = [
        'tenant_id', 'name', 'status', 'subject',
        'body_blocks', 'segment', 'scheduled_for', 'sent_at',
        'recipients_count', 'created_by',
    ];

    protected $casts = [
        'body_blocks'   => 'array',
        'segment'       => 'array',
        'scheduled_for' => 'datetime',
        'sent_at'       => 'datetime',
    ];

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING   = 'sending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_CANCELED  = 'canceled';

    /** Editable = anything that hasn't gone (or started going) out. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_CANCELED], true);
    }
}
