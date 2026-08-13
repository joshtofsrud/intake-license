<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// MARKER-GIFTCARDS
class TenantGiftCard extends Model
{
    use HasUuids;

    protected $table = 'tenant_gift_cards';

    protected $fillable = [
        'tenant_id', 'code', 'type', 'status',
        'original_cents', 'balance_cents',
        'purchaser_customer_id', 'purchaser_name', 'purchaser_email',
        'recipient_name', 'recipient_email', 'gift_message',
        'deliver_on', 'delivered_at',
        'issued_sale_id', 'issued_by_user_id', 'stripe_payment_intent_id',
        'deactivated_at', 'deactivated_reason',
    ];

    protected $casts = [
        'deliver_on'     => 'date',
        'delivered_at'   => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function purchaser()
    {
        return $this->belongsTo(TenantCustomer::class, 'purchaser_customer_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(TenantGiftCardTransaction::class, 'gift_card_id')->orderByDesc('created_at');
    }

    /** Normalize a typed/scanned code for lookup and storage. */
    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', trim($code)));
    }

    public function maskedCode(): string
    {
        $c = (string) $this->code;
        $tail = substr($c, -4);
        return 'GC-••••-••••-' . $tail;
    }
}
