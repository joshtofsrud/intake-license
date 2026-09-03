<?php
// MARKER-EMAIL-LEDGER

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per email accepted for sending, stamped with the rate it was
 * charged at. The row is written BEFORE the send and voided if the send
 * fails — written-after would mean a crash post-send sends free mail
 * that no reconciliation can ever find.
 */
class TenantEmailLedgerEntry extends Model
{
    protected $table = 'tenant_email_ledger';

    protected $fillable = [
        'tenant_id', 'kind', 'template_key', 'to_email',
        'rate', 'stream', 'status', 'campaign_id',
        // MARKER-SMS-METER — the SMS half of the same ledger
        'channel', 'segments', 'to_phone',
        'is_free', // MARKER-EMAIL-RATES
    ];

    protected $casts = [
        'rate' => 'decimal:5',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT    = 'sent';
    public const STATUS_VOIDED  = 'voided';
}
