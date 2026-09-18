<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** MARKER-PLATFORM-INBOUND — one turn of a platform conversation. */
class PlatformInboxReply extends Model
{
    use HasUuids;

    protected $table    = 'platform_inbox_replies';
    protected $fillable = ['message_id', 'direction', 'from_email', 'body', 'external_id'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(PlatformInboxMessage::class, 'message_id');
    }
}
