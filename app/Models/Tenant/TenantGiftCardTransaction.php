<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// MARKER-GIFTCARDS
class TenantGiftCardTransaction extends Model
{
    use HasUuids;

    protected $table = 'tenant_gift_card_transactions';

    protected $fillable = [
        'tenant_id', 'gift_card_id', 'kind', 'amount_cents',
        'balance_after_cents', 'sale_id', 'note', 'user_id',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(TenantGiftCard::class, 'gift_card_id');
    }
}
