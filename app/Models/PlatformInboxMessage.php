<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** MARKER-INBOX */
class PlatformInboxMessage extends Model
{
    use HasUuids;

    protected $table = 'platform_inbox_messages';

    protected $fillable = [
        'kind', 'status', 'tenant_id', 'name', 'email', 'phone', 'company',
        'subject', 'body', 'source_url', 'ref_id', 'meta',
        'read_at', 'replied_at', 'reply_body', 'ip',
    ];

    protected $casts = [
        'meta'       => 'array',
        'read_at'    => 'datetime',
        'replied_at' => 'datetime',
    ];

    public const KIND_CONTACT = 'contact';
    public const KIND_ACCESS  = 'access_request';
    public const KIND_SUPPORT = 'support';
    public const KIND_ALERT   = 'alert';

    /** Kinds that come from a person and expect a reply. */
    public const MESSAGE_KINDS = [self::KIND_CONTACT, self::KIND_ACCESS, self::KIND_SUPPORT];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isMessage(): bool
    {
        return in_array($this->kind, self::MESSAGE_KINDS, true);
    }

    public function scopeMessages($q)
    {
        return $q->whereIn('kind', self::MESSAGE_KINDS);
    }

    public function scopeAlerts($q)
    {
        return $q->where('kind', self::KIND_ALERT);
    }

    public function scopeUnread($q)
    {
        return $q->where('status', 'new');
    }
}
