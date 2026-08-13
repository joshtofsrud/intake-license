<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantGiftCard;
use App\Models\Tenant\TenantGiftCardTransaction;
use App\Models\Tenant\TenantSale;
use App\Services\Tenant\Exceptions\SaleValidationException;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-GIFTCARDS — internal gift card ledger. Every balance change writes
 * a transaction row; balance_cents is a cache the ledger can rebuild.
 *
 * All mutators expect to run INSIDE the caller's DB transaction (the sale
 * transaction for issue/redeem) and take their own lockForUpdate on the card
 * row, so two registers can't double-spend one card.
 */
class GiftCardService
{
    /** Generate a unique, unused code for this tenant: GC-####-####-####. */
    public function generateCode(string $tenantId): string
    {
        do {
            $code = sprintf('GC-%04d-%04d-%04d', random_int(0, 9999), random_int(0, 9999), random_int(0, 9999));
        } while (TenantGiftCard::where('tenant_id', $tenantId)->where('code', $code)->exists());

        return $code;
    }

    /** Balance lookup by code. Returns the card or null. Never leaks other tenants. */
    public function lookup(string $tenantId, string $code): ?TenantGiftCard
    {
        $norm = TenantGiftCard::normalizeCode($code);
        if ($norm === '') {
            return null;
        }

        return TenantGiftCard::where('tenant_id', $tenantId)->where('code', $norm)->first();
    }

    /**
     * Activate gift cards sold on a completed sale. Reads each gift_card
     * line's metadata (written at cart time), creates the card, writes the
     * issue ledger row, links the line, and queues e-gift delivery.
     *
     * Idempotent per line via the gift_card_id null check, so a replayed
     * commit can't double-issue.
     */
    public function issueForSale(TenantSale $sale): void
    {
        foreach ($sale->items as $line) {
            if ($line->type !== 'gift_card' || $line->gift_card_id !== null) {
                continue;
            }

            // MARKER-GIFTCARDS-GATE -- selling requires the addon. Checked at
            // activation so it holds for every path (register, drafts, quotes
            // committed later). Redemption is deliberately NOT gated.
            if (! \App\Models\Tenant::find($sale->tenant_id)?->gift_cards_enabled) {
                throw new SaleValidationException('Gift cards are not enabled for this shop.');
            }

            $meta = (array) ($line->metadata ?? []);
            $kind = ($meta['kind'] ?? 'physical') === 'egift' ? 'egift' : 'physical';
            $amount = (int) round($line->unit_price_cents * (float) $line->quantity);
            if ($amount < 1) {
                throw new SaleValidationException('Gift card amount must be at least $0.01.');
            }

            if ($kind === 'physical') {
                $code = TenantGiftCard::normalizeCode((string) ($meta['code'] ?? ''));
                if ($code === '') {
                    throw new SaleValidationException('Physical gift card is missing its card code.');
                }
                if (TenantGiftCard::where('tenant_id', $sale->tenant_id)->where('code', $code)->exists()) {
                    throw new SaleValidationException("Gift card code {$code} is already in use.");
                }
            } else {
                $code = $this->generateCode($sale->tenant_id);
                if (blank($meta['recipient_email'] ?? null)) {
                    throw new SaleValidationException('E-gift card is missing the recipient email.');
                }
            }

            $card = TenantGiftCard::create([
                'tenant_id'             => $sale->tenant_id,
                'code'                  => $code,
                'type'                  => $kind,
                'status'                => 'active',
                'original_cents'        => $amount,
                'balance_cents'         => $amount,
                'purchaser_customer_id' => $sale->customer_id,
                'recipient_name'        => $meta['recipient_name'] ?? null,
                'recipient_email'       => $meta['recipient_email'] ?? null,
                'gift_message'          => $meta['gift_message'] ?? null,
                'issued_sale_id'        => $sale->id,
                'issued_by_user_id'     => $sale->rang_up_by_user_id,
            ]);

            $this->ledger($card, 'issue', $amount, $sale->id, 'Sold on sale ' . ($sale->sale_number ?? $sale->id), $sale->rang_up_by_user_id);

            $line->update(['gift_card_id' => $card->id]);

            if ($kind === 'egift') {
                \App\Jobs\DeliverGiftCardJob::dispatch($card->id)->afterCommit();
            }
        }
    }

    /**
     * Redeem gift-card tenders for a sale, inside the sale transaction.
     * Reads either payments[] entries with method=gift_card (reference = code)
     * or a single payment_method=gift_card with payment_reference = code.
     * Throws SaleValidationException (→ 422, transaction rolls back) on any
     * unknown code, inactive card, or short balance.
     */
    public function redeemTenders(TenantSale $sale, array $data): void
    {
        $tenders = [];
        foreach ((array) ($data['payments'] ?? []) as $p) {
            if (($p['method'] ?? null) === 'gift_card') {
                $tenders[] = ['code' => (string) ($p['reference'] ?? ''), 'amount' => (int) $p['amount_cents']];
            }
        }
        if (empty($tenders) && ($data['payment_method'] ?? null) === 'gift_card') {
            $tenders[] = ['code' => (string) ($data['payment_reference'] ?? ''), 'amount' => (int) $sale->total_cents];
        }

        foreach ($tenders as $t) {
            $this->redeem($sale, $t['code'], $t['amount']);
        }
    }

    protected function redeem(TenantSale $sale, string $code, int $amountCents): void
    {
        $norm = TenantGiftCard::normalizeCode($code);
        $card = TenantGiftCard::where('tenant_id', $sale->tenant_id)
            ->where('code', $norm)
            ->lockForUpdate()
            ->first();

        if (! $card) {
            throw new SaleValidationException('Gift card ' . ($norm ?: '(blank)') . ' was not found.');
        }
        if ($card->status !== 'active') {
            throw new SaleValidationException('Gift card ' . $card->maskedCode() . ' is not active.');
        }
        if ($amountCents < 1) {
            throw new SaleValidationException('Gift card redemption amount must be positive.');
        }
        if ($card->balance_cents < $amountCents) {
            throw new SaleValidationException(sprintf(
                'Gift card %s has $%s left — the sale asked for $%s. Apply it as a split payment.',
                $card->maskedCode(),
                number_format($card->balance_cents / 100, 2),
                number_format($amountCents / 100, 2)
            ));
        }

        $card->balance_cents -= $amountCents;
        if ($card->balance_cents === 0) {
            $card->status = 'used';
        }
        $card->save();

        $this->ledger($card, 'redeem', -$amountCents, $sale->id, 'Tender on sale ' . ($sale->sale_number ?? $sale->id), $sale->rang_up_by_user_id);
    }

    /** Manual balance adjustment (± cents). Requires a reason. */
    public function adjust(TenantGiftCard $card, int $deltaCents, string $reason, ?string $userId): void
    {
        DB::transaction(function () use ($card, $deltaCents, $reason, $userId) {
            $locked = TenantGiftCard::whereKey($card->id)->lockForUpdate()->first();

            if ($deltaCents === 0) {
                throw new \InvalidArgumentException('Adjustment cannot be zero.');
            }
            if ($locked->balance_cents + $deltaCents < 0) {
                throw new \InvalidArgumentException('Adjustment would take the balance below zero.');
            }

            $locked->balance_cents += $deltaCents;
            $locked->status = $locked->balance_cents === 0 ? 'used'
                : ($locked->status === 'used' ? 'active' : $locked->status);
            $locked->save();

            $this->ledger($locked, 'adjust', $deltaCents, null, $reason, $userId);
        });
    }

    /** Deactivate a card (lost/stolen). Balance is preserved for the record. */
    public function deactivate(TenantGiftCard $card, string $reason, ?string $userId): void
    {
        DB::transaction(function () use ($card, $reason, $userId) {
            $locked = TenantGiftCard::whereKey($card->id)->lockForUpdate()->first();
            if ($locked->status === 'deactivated') {
                return;
            }
            $locked->update([
                'status'             => 'deactivated',
                'deactivated_at'     => now(),
                'deactivated_reason' => $reason,
            ]);
            $this->ledger($locked, 'deactivate', 0, null, $reason, $userId);
        });
    }

    protected function ledger(TenantGiftCard $card, string $kind, int $amountCents, ?string $saleId, ?string $note, ?string $userId): void
    {
        TenantGiftCardTransaction::create([
            'tenant_id'           => $card->tenant_id,
            'gift_card_id'        => $card->id,
            'kind'                => $kind,
            'amount_cents'        => $amountCents,
            'balance_after_cents' => $card->balance_cents,
            'sale_id'             => $saleId,
            'note'                => $note,
            'user_id'             => $userId,
        ]);
    }
}
