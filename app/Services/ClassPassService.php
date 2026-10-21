<?php

namespace App\Services;

use App\Models\Tenant\TenantClassMembershipProduct;
use App\Models\Tenant\TenantClassPackProduct;
use App\Models\Tenant\TenantCustomerMembership;
use App\Models\Tenant\TenantCustomerPack;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSaleItem;
use App\Services\Tenant\SaleValidationException;
use Illuminate\Support\Facades\Log;

/**
 * Class passes (packs and memberships) as things the register sells.
 *
 * A 'class_pass' sale line carries metadata {kind: pack|membership,
 * product_id, book_session_id?}. When the sale is PAID, issueForSale()
 * creates the pack or membership for the sale's customer, records it in
 * the credit ledger and, when the line asks for it, books the customer
 * into that class with the new pass. Idempotent per line: the pass row
 * carries sale_item_id (unique), so a replayed commit or a second
 * "now paid" event creates nothing new.
 *
 * Refunding the line voids what is left on the pass (reverseForRefund).
 *
 * MARKER-CLASSES-P1
 */
class ClassPassService
{
    public const KINDS = ['pack', 'membership'];

    /** The product a class_pass line names, scoped to the shop. */
    public static function product(string $tenantId, string $kind, ?string $productId): TenantClassPackProduct|TenantClassMembershipProduct|null
    {
        if (! $productId || ! in_array($kind, self::KINDS, true)) {
            return null;
        }
        $model = $kind === 'pack' ? TenantClassPackProduct::class : TenantClassMembershipProduct::class;
        return $model::where('tenant_id', $tenantId)->find($productId);
    }

    public function issueForSale(TenantSale $sale): void
    {
        $lines = $sale->relationLoaded('items') ? $sale->items : $sale->items()->get();

        foreach ($lines as $line) {
            if ($line->type !== 'class_pass') {
                continue;
            }
            if (TenantCustomerPack::where('sale_item_id', $line->id)->exists()
                || TenantCustomerMembership::where('sale_item_id', $line->id)->exists()) {
                continue; // already issued
            }

            $meta    = (array) ($line->metadata ?? []);
            $kind    = (string) ($meta['kind'] ?? '');
            $product = self::product($sale->tenant_id, $kind, $meta['product_id'] ?? null);
            if (! $product) {
                throw new SaleValidationException('A class pass on this sale no longer exists. Remove the line and add it again.');
            }
            if (! $sale->customer_id) {
                throw new SaleValidationException('Choose the customer first: a class pass belongs to someone.');
            }
            $tenant = \App\Models\Tenant::find($sale->tenant_id);
            if (! $tenant?->classes_active) {
                throw new SaleValidationException('Classes are not turned on for this shop.');
            }

            $count = max(1, (int) round((float) $line->quantity));
            $first = null;
            for ($n = 0; $n < $count; $n++) {
                // Quantity above 1 makes separate passes; only the first keeps
                // the sale_item_id link (it's the idempotency key).
                $pass = $kind === 'pack'
                    ? $this->issuePack($sale, $line, $product, $n === 0)
                    : $this->issueMembership($sale, $line, $product, $n === 0);
                $first ??= $pass;
            }

            $sessionId = $meta['book_session_id'] ?? null;
            if ($sessionId && $first) {
                app(ClassRegistrationService::class)->register(
                    $sessionId, $sale->customer_id, $sale->tenant_id, $kind, true, true
                );
            }
        }
    }

    private function issuePack(TenantSale $sale, TenantSaleItem $line, TenantClassPackProduct $product, bool $link): TenantCustomerPack
    {
        $tz      = \App\Models\Tenant::find($sale->tenant_id)?->timezone() ?? config('app.timezone');
        $credits = (int) $product->credit_count;

        $pack = TenantCustomerPack::create([
            'tenant_id'         => $sale->tenant_id,
            'customer_id'       => $sale->customer_id,
            'product_id'        => $product->id,
            'sale_item_id'      => $link ? $line->id : null,
            'credits_total'     => $credits,
            'credits_remaining' => $credits,
            'expires_at'        => now($tz)->startOfDay()->addDays((int) $product->expiry_days),
            'status'            => 'active',
            'metadata'          => ['sold_on_sale' => $sale->id],
        ]);

        ClassCreditLedger::record($sale->tenant_id, $sale->customer_id, $credits, 'purchased',
            ['pack_id' => $pack->id, 'sale_id' => $sale->id],
            $product->name . ' · sale ' . ($sale->sale_number ?? $sale->id));

        return $pack;
    }

    private function issueMembership(TenantSale $sale, TenantSaleItem $line, TenantClassMembershipProduct $product, bool $link): TenantCustomerMembership
    {
        $tz    = \App\Models\Tenant::find($sale->tenant_id)?->timezone() ?? config('app.timezone');
        $start = now($tz)->startOfDay();

        $m = TenantCustomerMembership::create([
            'tenant_id'                => $sale->tenant_id,
            'customer_id'              => $sale->customer_id,
            'product_id'               => $product->id,
            'sale_item_id'             => $link ? $line->id : null,
            'status'                   => 'active',
            'current_period_start'     => $start,
            'current_period_end'       => $start->copy()->addMonth()->subDay(),
            'classes_used_this_period' => 0,
            'metadata'                 => ['sold_on_sale' => $sale->id],
        ]);

        ClassCreditLedger::record($sale->tenant_id, $sale->customer_id, 0, 'purchased',
            ['membership_id' => $m->id, 'sale_id' => $sale->id],
            $product->name . ' · sale ' . ($sale->sale_number ?? $sale->id));

        return $m;
    }

    /**
     * A class_pass line is being refunded: whatever is left on the pass it
     * created stops being usable. Credits already used stay used (the
     * refund amount is staff's call). Bookings already made with it stand.
     */
    public function reverseForRefund(TenantSaleItem $originalLine, TenantSale $refund): void
    {
        $pack = TenantCustomerPack::where('tenant_id', $originalLine->tenant_id)
            ->where('sale_item_id', $originalLine->id)->first();
        if ($pack && in_array($pack->status, ['active', 'exhausted'], true)) {
            $left = (int) $pack->credits_remaining;
            $pack->update(['status' => 'cancelled']);
            ClassCreditLedger::record($pack->tenant_id, $pack->customer_id, -$left, 'refunded',
                ['pack_id' => $pack->id, 'sale_id' => $refund->id],
                'Refund ' . ($refund->sale_number ?? $refund->id) . ($left ? " · {$left} unused credit(s) voided" : ''));
            return;
        }

        $m = TenantCustomerMembership::where('tenant_id', $originalLine->tenant_id)
            ->where('sale_item_id', $originalLine->id)->first();
        if ($m && $m->status === 'active') {
            $m->update(['status' => 'cancelled']);
            ClassCreditLedger::record($m->tenant_id, $m->customer_id, 0, 'refunded',
                ['membership_id' => $m->id, 'sale_id' => $refund->id],
                'Refund ' . ($refund->sale_number ?? $refund->id) . ' · membership ended');
            return;
        }

        if (! $pack && ! $m) {
            Log::info('classes: refunded class_pass line had no pass to void', ['sale_item_id' => $originalLine->id]);
        }
    }
}

