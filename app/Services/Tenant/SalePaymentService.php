<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSalePayment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-PATCH-175 — Owns all money writes against a sale.
 *
 * Mirrors AppointmentPaymentService. The sale's payment ledger
 * (tenant_sale_payments) is the source of truth; tenant_sales.payment_status
 * is a derived cache recomputed by recalcStatus() after every write.
 *
 * Patch-175 only DEFINES this service (additive). Writers are repointed at it
 * in patch-176; until then nothing calls it in production.
 */
class SalePaymentService
{
    /**
     * Record a payment (or refund) against a sale.
     *
     * @param  int  $amountCents  positive = money in; negative ONLY for refund kinds
     */
    public function record(
        TenantSale $sale,
        int $amountCents,
        string $kind,
        string $source,
        string $method,
        ?string $referencePaymentId = null,
        ?string $externalReference = null,
        ?string $notes = null,
        ?\DateTimeInterface $recordedAt = null,
    ): TenantSalePayment {
        if ($amountCents === 0) {
            throw new \InvalidArgumentException('Payment amount cannot be zero.');
        }
        $isRefundKind = in_array($kind, [TenantSalePayment::KIND_REFUND, TenantSalePayment::KIND_OVERAGE_REFUND], true);
        if ($amountCents < 0 && !$isRefundKind) {
            throw new \InvalidArgumentException('Negative amounts only allowed for refund kinds.');
        }
        if ($amountCents > 0 && $isRefundKind) {
            throw new \InvalidArgumentException('Refund kinds require negative amounts.');
        }

        return DB::transaction(function () use ($sale, $amountCents, $kind, $source, $method, $referencePaymentId, $externalReference, $notes, $recordedAt) {
            // Lock the sale so concurrent payment writes serialize.
            $locked = TenantSale::where('id', $sale->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("Sale {$sale->id} disappeared mid-payment.");
            }

            $payment = TenantSalePayment::create([
                'tenant_id'            => $locked->tenant_id,
                'sale_id'              => $locked->id,
                'amount_cents'         => $amountCents,
                'kind'                 => $kind,
                'source'               => $source,
                'method'               => $method,
                'reference_payment_id' => $referencePaymentId,
                'external_reference'   => $externalReference,
                'recorded_by_user_id'  => Auth::guard('tenant')->id(),
                'recorded_at'          => $recordedAt ?? now(),
                'notes'                => $notes,
            ]);

            $this->recalcStatus($locked);

            return $payment;
        });
    }

    /**
     * Refund a prior payment (records a negative row referencing it) and
     * recomputes the sale's status.
     */
    public function refund(
        TenantSale $sale,
        int $amountCents,
        string $method,
        ?string $referencePaymentId = null,
        ?string $externalReference = null,
        ?string $notes = null,
    ): TenantSalePayment {
        $magnitude = abs($amountCents);
        return $this->record(
            $sale,
            -$magnitude,
            TenantSalePayment::KIND_REFUND,
            TenantSalePayment::SOURCE_REGISTER,
            $method,
            $referencePaymentId,
            $externalReference,
            $notes,
        );
    }

    /**
     * Net amount paid on a sale (sum of the ledger, refunds included).
     */
    public function paidCents(TenantSale $sale): int
    {
        return (int) TenantSalePayment::where('sale_id', $sale->id)->sum('amount_cents');
    }

    /**
     * MARKER-PATCH-177 — Record a standalone refund (no sale attached).
     *
     * Always carries a customer; sale_id stays null. Stored as a negative
     * 'refund' row so it nets into money-out reporting like any other refund.
     * Uncapped by design — there is no sale total to cap against.
     */
    public function recordStandaloneRefund(
        string $tenantId,
        string $customerId,
        int $amountCents,
        string $method,
        string $reason,
    ): TenantSalePayment {
        $magnitude = abs($amountCents);
        if ($magnitude === 0) {
            throw new \InvalidArgumentException('Refund amount cannot be zero.');
        }

        return TenantSalePayment::create([
            'tenant_id'            => $tenantId,
            'sale_id'              => null,
            'customer_id'          => $customerId,
            'amount_cents'         => -$magnitude,
            'kind'                 => TenantSalePayment::KIND_REFUND,
            'source'               => TenantSalePayment::SOURCE_MANUAL_ENTRY,
            'method'               => $method,
            'reference_payment_id' => null,
            'external_reference'   => null,
            'recorded_by_user_id'  => \Illuminate\Support\Facades\Auth::guard('tenant')->id(),
            'recorded_at'          => now(),
            'notes'                => 'Standalone refund (no sale): ' . $reason,
        ]);
    }

    /**
     * Recompute tenant_sales.payment_status + paid_at from the ledger.
     *
     * draft/quote are intentionally NOT overwritten — those are pre-money
     * states the register manages explicitly; a payment landing on them will
     * have already moved them out of draft/quote at the call site.
     */
    public function recalcStatus(TenantSale $sale): void
    {
        $paid  = $this->paidCents($sale);
        $total = (int) $sale->total_cents;

        // MARKER-PATCH-219B — sales-as-money cascade for rentals. Runs on
        // every payment write AND the delete-payment correction tool, before
        // the draft early-return, so a linked rental's paid cache can never
        // go stale. (Appointments do this at call sites for legacy reasons;
        // rentals get it centrally.)
        if (!empty($sale->rental_id)) {
            $rentalPaid = (int) TenantSalePayment::query()
                ->whereIn('sale_id', TenantSale::where('rental_id', $sale->rental_id)->select('id'))
                ->sum('amount_cents');
            DB::table('tenant_rentals')
                ->where('id', $sale->rental_id)
                ->update(['paid_cents' => max(0, $rentalPaid), 'updated_at' => now()]);
        }

        // MARKER-PATCH-219C — appointment cascade, centralized. Replaces
        // the recomputes that lived at 6 call sites with 3 logic variants;
        // this is the complete superset (full up/down payment_status,
        // including the 'unpaid' downgrade from the patch-198 tool). The
        // ONE site that must keep a manual recompute is deleteSale — the
        // sale row is gone there, so this method can never run for it.
        if (!empty($sale->appointment_id)) {
            $appt = \App\Models\Tenant\TenantAppointment::find($sale->appointment_id);
            if ($appt) {
                $appt->paid_cents = (int) $appt->payments()->sum('tenant_sale_payments.amount_cents');
                $apptTotal = (int) $appt->total_cents;
                if ($apptTotal > 0 && $appt->paid_cents >= $apptTotal) {
                    $appt->payment_status = ($appt->paid_cents > $apptTotal) ? 'overage' : 'paid';
                } elseif ($appt->paid_cents > 0) {
                    $appt->payment_status = 'partial';
                } else {
                    $appt->payment_status = 'unpaid';
                }
                $appt->save();
            }
        }

        if (in_array($sale->payment_status, ['draft', 'quote'], true)) {
            return;
        }

        $status = $sale->payment_status;
        $paidAt = $sale->paid_at;

        if ($paid <= 0) {
            $status = 'unpaid';
            $paidAt = null;
        } elseif ($paid < $total) {
            $status = 'partial';
        } elseif ($paid >= $total && $total > 0) {
            // Fully covered. Refund rows can pull it back to 'refunded'/'partial'
            // — but a positive net >= total means paid.
            $status = 'paid';
            $paidAt = $paidAt ?? now();
        }

        // Refund handling: if the ledger has refund rows that bring net below
        // total after having been paid, reflect partial/refunded.
        $hasRefund = TenantSalePayment::where('sale_id', $sale->id)
            ->whereIn('kind', [TenantSalePayment::KIND_REFUND, TenantSalePayment::KIND_OVERAGE_REFUND])
            ->exists();
        if ($hasRefund) {
            if ($paid <= 0) {
                $status = 'refunded';
                $paidAt = null;
            } elseif ($paid < $total) {
                $status = 'partial';
            }
        }

        if ($status !== $sale->payment_status || $paidAt != $sale->paid_at) {
            $sale->payment_status = $status;
            $sale->paid_at = $paidAt;
            $sale->save();
        }
    }
}
