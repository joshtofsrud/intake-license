<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemLocation;
use App\Models\Tenant\TenantLayawayPlan;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSalePayment;
use App\Models\Tenant\TenantSpecialOrder;
use App\Support\LayawaySettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-LAYAWAY — open, pay, complete, cancel.
 *
 * Composes what already exists rather than duplicating it:
 *   SaleService::saveDraft      builds the sale and lines without touching stock
 *   ReservationService          holds in-stock units
 *   SpecialOrderService::create raises the shop-side order for out-of-stock units
 *   SalePaymentService          the only ledger writer
 *   InventoryService            the only stock-taking path, at handover
 */
class LayawayService
{
    public function __construct(
        protected SaleService $sales,
        protected SalePaymentService $payments,
        protected ReservationService $reservations,
        protected InventoryService $inventory,
    ) {}

    /**
     * Open a layaway from cart data (the same shape the register sends to
     * checkout). Reserves what is in stock, raises special orders for what is
     * not, and records the opening payment if one is given.
     *
     * @return array{plan: TenantLayawayPlan, sale: TenantSale, held: int, ordered: int}
     */
    public function open(Tenant $tenant, array $cart, ?int $openingPaymentCents, string $method, ?string $reference, ?string $userId): array
    {
        $policy = LayawaySettings::for($tenant);

        return DB::transaction(function () use ($tenant, $cart, $openingPaymentCents, $method, $reference, $userId, $policy) {
            $cart['tenant_id'] = $tenant->id;

            // MARKER-LAYAWAY-DRAFT — if the register already autosaved this
            // cart as a draft, CONVERT it. saveDraft() updates in place when
            // given an id and creates a new sale when not, so omitting it left
            // the original draft orphaned beside the layaway — same goods, two
            // rows, one of them resumable at the till.
            //
            // A layaway is a draft that grew up, not a new record beside one.
            $sale = $this->sales->saveDraft($cart);
            $sale->forceFill(['payment_status' => 'layaway'])->save();
            $sale->load('items');

            $minFirst = (int) round($sale->total_cents * ($policy['min_first_pct'] / 100));
            if ($openingPaymentCents !== null && $openingPaymentCents < $minFirst) {
                throw new SaleValidationException(
                    'Opening payment must be at least ' . number_format($minFirst / 100, 2)
                    . ' (' . $policy['min_first_pct'] . '% of the total).'
                );
            }

            $held = 0;
            $ordered = 0;

            foreach ($sale->items as $line) {
                if ($line->type !== 'product' || ! $line->inventory_item_id) {
                    continue;
                }

                $qty = (int) ceil((float) $line->quantity);
                $loc = TenantInventoryItemLocation::where('inventory_item_id', $line->inventory_item_id)
                    ->where('location_id', $sale->location_id)->first();
                $available = $loc ? $loc->available() : 0;

                if ($available >= $qty) {
                    $this->reservations->reserve($sale, $line, $sale->location_id, $qty);
                    $held += $qty;
                    continue;
                }

                // Hold what is here, order the rest. Both edges of the rule.
                if ($available > 0) {
                    $this->reservations->reserve($sale, $line, $sale->location_id, $available);
                    $held += $available;
                }

                $short = $qty - max(0, $available);
                $item = TenantInventoryItem::find($line->inventory_item_id);

                app(SpecialOrderService::class)->create([
                    'tenant_id'          => $tenant->id,
                    'inventory_item_id'  => $line->inventory_item_id,
                    'item_name_snapshot' => $item?->name ?: $line->name_snapshot,
                    'quantity'           => $short,
                    'customer_id'        => $sale->customer_id,
                    'sale_id'            => $sale->id,
                    'sale_item_id'       => $line->id,
                    'status'             => TenantSpecialOrder::STATUS_NEEDED,
                    // MARKER-LAYAWAY-SO-SOURCE — 'register' because that is
                    // where this was rung, and because created_from is an enum
                    // of register|appointment|item|manual|booking. It has no
                    // 'layaway' value, and inventing one truncated the insert.
                    // The tie back to the plan is sale_id / sale_item_id.
                    'created_from'       => 'register',
                ]);
                $ordered += $short;
            }

            $plan = TenantLayawayPlan::create([
                'tenant_id'   => $tenant->id,
                'sale_id'     => $sale->id,
                'customer_id' => $sale->customer_id,
                'location_id' => $sale->location_id,
                'status'      => TenantLayawayPlan::ACTIVE,
                'term_days'   => (int) $policy['term_days'],
                'frequency'   => $policy['frequency'],
                'grace_days'  => (int) $policy['grace_days'],
                'policy'      => $policy,
                'collect_by'  => now()->addDays((int) $policy['term_days'])->toDateString(),
            ]);

            if ($openingPaymentCents) {
                $this->pay($plan, $openingPaymentCents, $method, $reference, $userId);
                $plan->refresh();
            } else {
                $this->reschedule($plan);
            }

            return ['plan' => $plan, 'sale' => $sale, 'held' => $held, 'ordered' => $ordered];
        });
    }

    /** Record a payment. Paying it off moves the plan to READY (or completes it if everything is in hand). */
    public function pay(TenantLayawayPlan $plan, int $amountCents, string $method, ?string $reference, ?string $userId): TenantSalePayment
    {
        if (! $plan->isOpen()) {
            throw new SaleValidationException('This layaway is ' . $plan->status . '.');
        }
        if ($amountCents <= 0) {
            throw new SaleValidationException('Payment must be positive.');
        }

        return DB::transaction(function () use ($plan, $amountCents, $method, $reference, $userId) {
            $sale = $plan->sale()->lockForUpdate()->first();
            $balance = $this->balanceCents($plan);

            if ($amountCents > $balance) {
                throw new SaleValidationException('That is more than the ' . number_format($balance / 100, 2) . ' owed.');
            }

            $payment = $this->payments->record(
                $sale, $amountCents,
                TenantSalePayment::KIND_PAYMENT,
                'register',
                $method,
                null, $reference, null
            );

            if ($this->balanceCents($plan) === 0) {
                $plan->forceFill(['status' => TenantLayawayPlan::READY, 'next_due_on' => null, 'scheduled_amount_cents' => null])->save();
            } else {
                $this->reschedule($plan);
            }

            return $payment;
        });
    }

    /**
     * Handover. The goods leave, the sale becomes a sale.
     * Requires paid in full and nothing still on order.
     */
    public function complete(TenantLayawayPlan $plan, ?string $userId): TenantSale
    {
        if ($plan->status !== TenantLayawayPlan::READY && $this->balanceCents($plan) > 0) {
            throw new SaleValidationException('Balance is not paid.');
        }
        if ($plan->awaitingArrival()) {
            throw new SaleValidationException('A special order on this layaway has not arrived yet.');
        }

        return DB::transaction(function () use ($plan, $userId) {
            $sale = $plan->sale()->with('items')->lockForUpdate()->first();

            // Same path every sale takes. It consumes the line's own hold
            // first, then decrements — one movement, not release-then-sale.
            foreach ($sale->items as $line) {
                if ($line->type === 'product') {
                    $this->inventory->decrementForSaleItem($sale, $line, $sale->location_id);
                }
            }

            // Arrived special orders are pulled at handover, here, not at the bench.
            TenantSpecialOrder::where('sale_id', $sale->id)
                ->where('status', TenantSpecialOrder::STATUS_ARRIVED)
                ->update(['status' => TenantSpecialOrder::STATUS_PULLED, 'updated_at' => now()]);

            $sale->forceFill([
                'payment_status' => 'paid',
                'status'         => 'completed',
                'paid_at'        => $sale->paid_at ?? now(),
                'sale_date'      => now(),   // the day it LEFT — the accounting split
            ])->save();

            $plan->forceFill(['status' => TenantLayawayPlan::COMPLETED, 'completed_at' => now()])->save();

            return $sale;
        });
    }

    /**
     * Cancel. Releases every hold, applies the fee from the policy snapshot to
     * the lines that were HELD, records the refund.
     */
    public function cancel(TenantLayawayPlan $plan, string $reason, string $method, ?string $userId): array
    {
        if (! $plan->isOpen()) {
            throw new SaleValidationException('This layaway is already ' . $plan->status . '.');
        }

        return DB::transaction(function () use ($plan, $reason, $method, $userId) {
            $sale = $plan->sale()->with('items')->lockForUpdate()->first();
            $preview = $this->cancelPreview($plan);

            $this->reservations->releaseAllForSale($sale, 'layaway_cancelled');

            // Special orders stay open — the shop decides, per the mockup.
            // Their link to this sale is kept so the decision has context.

            if ($preview['refund_cents'] > 0 && $preview['mode'] !== 'store_credit') {
                // refund(sale, amount, method, referencePaymentId, externalReference, notes)
                // — verified against the signature, there is no source arg.
                $this->payments->refund($sale, $preview['refund_cents'], $method, null, null, 'Layaway cancelled: ' . $reason);
            }

            $sale->forceFill(['status' => 'cancelled'])->save();

            $plan->forceFill([
                'status'               => TenantLayawayPlan::CANCELLED,
                'cancelled_at'         => now(),
                'cancel_reason'        => $reason,
                'restocking_fee_cents' => $preview['fee_cents'],
                'refund_cents'         => $preview['refund_cents'],
                'next_due_on'          => null,
            ])->save();

            return $preview;
        });
    }

    /** What cancelling would do, for the confirm screen. Reads only. */
    public function cancelPreview(TenantLayawayPlan $plan): array
    {
        $policy = $plan->policy ?: LayawaySettings::for($plan->sale->tenant);
        $paid   = $this->paidCents($plan);

        // Fee applies to what was actually held, never to what was merely promised.
        $heldValue = 0;
        foreach ($plan->reservations()->where('status', 'active')->get() as $res) {
            $line = $plan->sale->items->firstWhere('id', $res->sale_item_id);
            if ($line) {
                $heldValue += (int) round($res->quantity * $line->unit_price_cents);
            }
        }

        $mode = $policy['cancel_refund'] ?? 'less_fee';
        $fee  = $mode === 'less_fee' ? (int) round($heldValue * (($policy['restock_fee_pct'] ?? 0) / 100)) : 0;
        $fee  = min($fee, $paid);

        return [
            'mode'         => $mode,
            'paid_cents'   => $paid,
            'held_value'   => $heldValue,
            'fee_cents'    => $fee,
            'refund_cents' => max(0, $paid - $fee),
            'open_special_orders' => $plan->specialOrders()->whereIn('status', TenantSpecialOrder::STATUSES_OPEN)->count(),
        ];
    }

    public function paidCents(TenantLayawayPlan $plan): int
    {
        return $this->payments->paidCents($plan->sale);
    }

    public function balanceCents(TenantLayawayPlan $plan): int
    {
        return max(0, (int) $plan->sale->total_cents - $this->paidCents($plan));
    }

    /**
     * Next due date and amount: the balance spread evenly over the
     * instalments left before collect_by, at the plan's frequency.
     */
    public function reschedule(TenantLayawayPlan $plan): void
    {
        $balance = $this->balanceCents($plan);

        if ($balance === 0 || $plan->frequency === 'none') {
            $plan->forceFill(['next_due_on' => null, 'scheduled_amount_cents' => null])->save();
            return;
        }

        $step = match ($plan->frequency) {
            'weekly'  => 7,
            'monthly' => 30,
            default   => 14,
        };

        $next = Carbon::today()->addDays($step);
        $end  = $plan->collect_by ? Carbon::parse($plan->collect_by) : Carbon::today()->addDays($plan->term_days);
        $slots = max(1, (int) floor($next->diffInDays($end, false) / $step) + 1);

        $plan->forceFill([
            'next_due_on'            => min($next, $end)->toDateString(),
            'scheduled_amount_cents' => (int) ceil($balance / $slots),
        ])->save();
    }
}
