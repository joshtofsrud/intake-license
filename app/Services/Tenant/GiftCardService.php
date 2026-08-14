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
    /**
     * MARKER-GC-SETTINGS -- normalized gift card configuration. Every surface
     * (register modal, public buy page, and the validation behind both) reads
     * this, so a default lives in exactly one place. Values are clamped here
     * rather than trusted: settings rows predate the validation that now
     * writes them, and a bad max would otherwise reject every sale.
     */
    public static function config(\App\Models\Tenant $tenant): array
    {
        $s = (array) ($tenant->settings ?? []);

        $presets = array_values(array_filter(array_map(
            fn ($v) => (int) $v,
            (array) ($s['gift_card_presets'] ?? [2500, 5000, 10000, 15000])
        ), fn ($v) => $v > 0));
        $presets = array_slice($presets, 0, 4);

        $min = (int) ($s['gift_card_min_cents'] ?? 500);
        $max = (int) ($s['gift_card_max_cents'] ?? 200000);
        $min = max(100, $min);
        $max = max($min, $max);

        return [
            'presets'          => $presets,
            'min_cents'        => $min,
            'max_cents'        => $max,
            'online_egift'     => (bool) ($s['gift_card_online_egift'] ?? true),
            'online_physical'  => (bool) ($s['gift_card_online_physical'] ?? true),
            'refund_to_card'   => (bool) ($s['gift_card_refund_to_card'] ?? false),
            'pending_days'     => (int) ($s['gift_card_pending_retention_days'] ?? 7),
            'default_message'  => (string) ($s['gift_card_default_message'] ?? ''),
            'policy_line'      => (string) ($s['gift_card_policy_line'] ?? 'Never expires. Redeemable in store and online.'),
        ];
    }

    /** MARKER-GC-SETTINGS -- shared amount check for every sell path. */
    public static function assertAmountAllowed(\App\Models\Tenant $tenant, int $cents): void
    {
        $cfg = self::config($tenant);
        if ($cents < $cfg['min_cents'] || $cents > $cfg['max_cents']) {
            throw new SaleValidationException(sprintf(
                'Gift card amounts must be between $%s and $%s.',
                number_format($cfg['min_cents'] / 100, 2),
                number_format($cfg['max_cents'] / 100, 2)
            ));
        }
    }

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
            $sellTenant = \App\Models\Tenant::find($sale->tenant_id);
            if (! $sellTenant?->gift_cards_enabled) {
                throw new SaleValidationException('Gift cards are not enabled for this shop.');
            }

            // MARKER-GC-SETTINGS -- the configured floor/ceiling, enforced at
            // activation so it also covers a draft rung up before the limits
            // changed rather than only the modal that created the line.
            self::assertAmountAllowed($sellTenant, (int) round($line->unit_price_cents * (float) $line->quantity));

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

    /**
     * MARKER-GC-FUNCTIONS -- put a refund onto a gift card.
     *
     * With a code: credits that card (must exist and not be deactivated; a
     * fully-used card comes back to life, which is the point of handing it
     * back). Without one: issues a new physical card for the refund amount.
     * Returns the card so the register can show or print its code.
     *
     * Runs inside the caller's transaction: if anything downstream of the
     * refund fails, the credit rolls back with it rather than leaving money
     * on a card for goods that were never returned.
     */
    public function refundToCard(TenantSale $refund, ?string $code, ?string $userId = null): TenantGiftCard
    {
        $tenant = \App\Models\Tenant::find($refund->tenant_id);
        $amount = abs((int) $refund->total_cents);

        if ($amount < 1) {
            throw new SaleValidationException('A gift card refund needs an amount.');
        }
        if (! $tenant || ! self::config($tenant)['refund_to_card']) {
            throw new SaleValidationException('Refunding to a gift card is turned off for this shop.');
        }

        $note = 'Refund from ' . ($refund->sale_number ?? $refund->id);

        if (filled($code)) {
            $norm = TenantGiftCard::normalizeCode((string) $code);
            $card = TenantGiftCard::where('tenant_id', $refund->tenant_id)
                ->where('code', $norm)
                ->lockForUpdate()
                ->first();

            if (! $card) {
                throw new SaleValidationException('Gift card ' . ($norm ?: '(blank)') . ' was not found.');
            }
            if ($card->status === 'deactivated') {
                throw new SaleValidationException('Gift card ' . $card->maskedCode() . ' is deactivated — issue a new one instead.');
            }
            if ($card->status === 'pending') {
                throw new SaleValidationException('Gift card ' . $card->maskedCode() . ' has not been paid for yet.');
            }

            $card->balance_cents += $amount;
            $card->status = 'active'; // a used card is spendable again once credited
            $card->save();

            $this->ledger($card, 'refund', $amount, $refund->id, $note, $userId);

            return $card;
        }

        $card = TenantGiftCard::create([
            'tenant_id'       => $refund->tenant_id,
            'code'            => $this->generateCode($refund->tenant_id),
            'type'            => 'physical',
            'status'          => 'active',
            'original_cents'  => $amount,
            'balance_cents'   => $amount,
            'issued_sale_id'  => $refund->id,
        ]);

        $this->ledger($card, 'issue', $amount, $refund->id, $note, $userId);

        return $card;
    }

    /**
     * MARKER-GC-FUNCTIONS -- bind a preprinted card to an online physical
     * purchase, replacing the generated code at pickup. Balance and history
     * are untouched; the swap itself is recorded, because a code changing
     * with no trace is exactly the kind of thing a manager needs to be able
     * to explain later.
     */
    public function bindPrintedCode(TenantGiftCard $card, string $printedCode, ?string $userId): void
    {
        $norm = TenantGiftCard::normalizeCode($printedCode);

        if ($norm === '') {
            throw new SaleValidationException('Enter the code printed on the card.');
        }
        if ($card->type !== 'physical') {
            throw new SaleValidationException('Only a physical card can be bound to a printed code.');
        }
        if ($card->status === 'deactivated') {
            throw new SaleValidationException('This card is deactivated.');
        }

        $taken = TenantGiftCard::where('tenant_id', $card->tenant_id)
            ->where('code', $norm)
            ->where('id', '!=', $card->id)
            ->exists();
        if ($taken) {
            throw new SaleValidationException('That code is already in use on another card.');
        }

        DB::transaction(function () use ($card, $norm, $userId) {
            $locked = TenantGiftCard::whereKey($card->id)->lockForUpdate()->first();
            $was = $locked->maskedCode();
            $locked->update(['code' => $norm]);

            $this->ledger($locked, 'adjust', 0, null, 'Code bound to printed card (was ' . $was . ')', $userId);
        });
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
