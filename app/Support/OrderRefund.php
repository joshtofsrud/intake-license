<?php

namespace App\Support;

use App\Models\Tenant\TenantOrder;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSaleItem;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-ORDER-STEPS: cancel-and-refund for an online order. What can still come back,
 * how the money goes back (the way it came in), and the card refund itself, shared with
 * the register so both refund a card the same way.
 */
class OrderRefund
{
    /**
     * @return array{method:string, method_label:string, lines:array<int,array>}
     *   lines: sale_item_id, name, kind (product|charge), qty, left, each_cents (with tax), dropship, shipped
     */
    public static function plan(TenantOrder $order, TenantSale $sale): array
    {
        $refundIds = TenantSale::where('tenant_id', $sale->tenant_id)->where('refund_of_sale_id', $sale->id)->pluck('id');
        $done = [];
        if ($refundIds->isNotEmpty()) {
            foreach (TenantSaleItem::whereIn('sale_id', $refundIds)->get(['original_sale_item_id', 'quantity']) as $r) {
                if ($r->original_sale_item_id) {
                    $done[(string) $r->original_sale_item_id] = ($done[(string) $r->original_sale_item_id] ?? 0) + abs((float) $r->quantity);
                }
            }
        }

        // which order lines have already left in a shipment
        $shippedItems = [];
        if ($order->fulfillment_type === 'ship') {
            foreach ($order->shipments()->with('items.orderItem')->get() as $sh) {
                if (in_array($sh->status, ['shipped', 'out_for_delivery', 'delivered'], true)) {
                    foreach ($sh->items as $it) {
                        if ($it->orderItem?->inventory_item_id) {
                            $shippedItems[(string) $it->orderItem->inventory_item_id] = true;
                        }
                    }
                }
            }
        }

        $lines = [];
        foreach ($sale->items as $li) {
            $qty = abs((float) $li->quantity);
            if ($qty <= 0) continue;
            $left = round($qty - ($done[(string) $li->id] ?? 0), 4);
            $kind = ($li->type === 'product' && $li->inventory_item_id) ? 'product' : 'charge';
            $lines[] = [
                'sale_item_id' => (string) $li->id,
                'name'         => (string) $li->name_snapshot,
                'kind'         => $kind,
                'qty'          => $qty,
                'left'         => max(0, $left),
                'each_cents'   => (int) round(((int) $li->line_total_cents + (int) $li->tax_cents) / $qty),
                'dropship'     => ! empty(((array) $li->metadata)['dropship']),
                'shipped'      => $kind === 'product' && isset($shippedItems[(string) $li->inventory_item_id]),
            ];
        }

        [$method, $label] = self::method($sale);

        return ['method' => $method, 'method_label' => $label, 'lines' => $lines];
    }

    /** How the money goes back, by how it came in. [refund method, "to the card"…] */
    public static function method(TenantSale $sale): array
    {
        if ($sale->stripe_payment_intent_id) {
            return ['card', 'to the card'];
        }
        return match ((string) $sale->payment_method) {
            'cash'         => ['cash', 'in cash'],
            'check'        => ['check', 'by check'],
            'store_credit' => ['store_credit', 'to store credit'],
            'card'         => ['card', 'to the card (refund it on the card machine)'],
            default        => ['mark_paid', 'by ' . tender_label((string) $sale->payment_method) . ' (send it back yourself; this records it)'],
        };
    }

    public static function recordHistory(TenantOrder $order, bool $all, string $amount, string $how, ?string $by): void
    {
        OrderSteps::record($order, $all ? 'Cancelled' : 'Part refunded', $by, $amount . ' refunded ' . $how);
    }

    /**
     * The card refund for a refund row, through Stripe, when the original sale was paid
     * there. Null on success (or nothing to do); otherwise the error, and the refund row
     * stands so it can be retried. Moved here from the register so both use it.
     */
    public static function stripe(\App\Models\Tenant $tenant, TenantSale $refundRow): ?string
    {
        if (! $tenant->direct_payments_enabled) {
            return null;
        }
        $original = TenantSale::find($refundRow->refund_of_sale_id);
        if (! $original || ! $original->stripe_payment_intent_id) {
            return null;
        }

        try {
            $direct = new \App\Services\Tenant\DirectPaymentsService($tenant);
            $stripeRefund = $direct->refundCharge(
                $original->stripe_payment_intent_id,
                (int) abs($refundRow->total_cents),
                ['intake_refund_sale_id' => $refundRow->id, 'intake_original_sale_id' => $original->id]
            );
            $refundRow->stripe_refund_id = $stripeRefund->id;
            $refundRow->save();

            // MARKER-DESTINATION-TAX: tax Stripe Tax was told about comes back out of its report with the refund
            try {
                $ord = TenantOrder::query()->where('sale_id', $original->id)->first();
                $ti  = (array) (((array) ($ord?->metadata ?? []))['tax'] ?? []);
                if (($ti['source'] ?? null) === 'stripe' && ! empty($ti['transaction_id']) && (int) $refundRow->tax_cents !== 0) {
                    \App\Services\Tenant\StripeTax::reverse($tenant, $ti['transaction_id'], 'refund-' . $refundRow->id, abs((int) $refundRow->tax_cents));
                }
            } catch (\Throwable $e) {
                Log::error('stripe_tax.reverse_hook_failed', ['refund_sale_id' => $refundRow->id, 'error' => $e->getMessage()]);
            }
            return null;
        } catch (\Throwable $e) {
            Log::error('direct_payments.refund_failed', [
                'tenant_id' => $tenant->id, 'refund_sale_id' => $refundRow->id, 'original_sale_id' => $original->id,
                'pi' => $original->stripe_payment_intent_id, 'error' => $e->getMessage(),
            ]);
            return $e->getMessage();
        }
    }
}
