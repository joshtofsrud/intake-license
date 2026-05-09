<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAppointmentPayment;
use App\Models\Tenant\TenantSale;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for writing rows into tenant_appointment_payments.
 *
 * Why a service: every payment write needs to (1) insert the ledger row,
 * (2) recompute the appointment's denormalized paid_cents cache, and
 * (3) recompute the appointment's payment_status. Doing all three in one
 * place prevents drift and keeps invariants tight.
 *
 * NOT a controller. Controllers and webhooks call into this. Tests stub it.
 */
class AppointmentPaymentService
{
    /**
     * Record a new inbound payment. Used by:
     *   - Register sale close (kind=balance, source=register_sale)
     *   - Manual deposit form (kind=deposit, source=manual_entry)
     *   - Stripe Connect webhook later (kind=deposit, source=booking_flow)
     *
     * Caller is responsible for being inside a transaction if other state
     * needs to flip atomically with the payment write. Internally we use
     * lockForUpdate on the appointment so concurrent writes don't race.
     *
     * @return TenantAppointmentPayment the freshly-inserted row
     */
    public function record(
        TenantAppointment $appointment,
        int $amountCents,
        string $kind,
        string $source,
        string $method,
        ?string $registerSaleId = null,
        ?string $externalReference = null,
        ?string $notes = null,
    ): TenantAppointmentPayment {
        if ($amountCents === 0) {
            throw new \InvalidArgumentException('Payment amount cannot be zero.');
        }
        if ($amountCents < 0 && !in_array($kind, [TenantAppointmentPayment::KIND_REFUND, TenantAppointmentPayment::KIND_OVERAGE_REFUND], true)) {
            throw new \InvalidArgumentException('Negative amounts only allowed for refund kinds.');
        }
        if ($amountCents > 0 && in_array($kind, [TenantAppointmentPayment::KIND_REFUND, TenantAppointmentPayment::KIND_OVERAGE_REFUND], true)) {
            throw new \InvalidArgumentException('Refund kinds require negative amounts.');
        }

        return DB::transaction(function () use ($appointment, $amountCents, $kind, $source, $method, $registerSaleId, $externalReference, $notes) {
            // Lock the appointment so concurrent payment writes serialize.
            $locked = TenantAppointment::where('id', $appointment->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("Appointment {$appointment->id} disappeared mid-payment.");
            }

            $payment = TenantAppointmentPayment::create([
                'tenant_id'           => $locked->tenant_id,
                'appointment_id'      => $locked->id,
                'amount_cents'        => $amountCents,
                'kind'                => $kind,
                'source'              => $source,
                'method'              => $method,
                'register_sale_id'    => $registerSaleId,
                'external_reference'  => $externalReference,
                'recorded_by_user_id' => Auth::guard('tenant')->id(),
                'recorded_at'         => now(),
                'notes'               => $notes,
            ]);

            $this->recalcCache($locked);

            return $payment;
        });
    }

    /**
     * Refund a previous payment. Inserts a negative amount row referencing
     * the original. Caller specifies amount; partial refunds supported.
     *
     * Method copies from the referenced payment by default — refund through
     * the same channel as the original. Override only when intentionally
     * doing cross-channel refund (e.g. cash refund of a Stripe charge after
     * Stripe-side dispute).
     */
    public function refund(
        TenantAppointmentPayment $original,
        int $amountCents,                 // pass POSITIVE; will be negated
        ?string $method = null,           // null = same as original
        ?string $registerSaleId = null,
        ?string $notes = null,
    ): TenantAppointmentPayment {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Refund amount must be positive (will be stored negative).');
        }
        if ($amountCents > $original->amount_cents) {
            throw new \InvalidArgumentException(
                "Refund {$amountCents} exceeds original payment {$original->amount_cents}."
            );
        }

        return DB::transaction(function () use ($original, $amountCents, $method, $registerSaleId, $notes) {
            $appointment = TenantAppointment::where('id', $original->appointment_id)
                ->lockForUpdate()->first();
            if (!$appointment) {
                throw new \RuntimeException("Appointment {$original->appointment_id} not found.");
            }

            $row = TenantAppointmentPayment::create([
                'tenant_id'            => $original->tenant_id,
                'appointment_id'       => $original->appointment_id,
                'amount_cents'         => -$amountCents,
                'kind'                 => TenantAppointmentPayment::KIND_REFUND,
                'source'               => TenantAppointmentPayment::SOURCE_REGISTER_SALE,
                'method'               => $method ?? $original->method,
                'register_sale_id'     => $registerSaleId,
                'reference_payment_id' => $original->id,
                'external_reference'   => $original->external_reference,
                'recorded_by_user_id'  => Auth::guard('tenant')->id(),
                'recorded_at'          => now(),
                'notes'                => $notes,
            ]);

            $this->recalcCache($appointment);
            return $row;
        });
    }

    /**
     * Void all rows tied to a particular register sale. Used when a draft
     * sale gets voided (staff clicked Edit and we're unwinding the sale).
     *
     * This is a hard delete because nothing money-real ever happened — the
     * sale never closed. Different from refund(), which is the right path
     * if money actually moved.
     */
    public function voidForSale(TenantSale $sale): int
    {
        return DB::transaction(function () use ($sale) {
            $appointmentId = $sale->appointment_id;
            if (!$appointmentId) return 0;

            $deleted = TenantAppointmentPayment::where('register_sale_id', $sale->id)
                ->delete();

            if ($deleted > 0) {
                $appointment = TenantAppointment::where('id', $appointmentId)
                    ->lockForUpdate()->first();
                if ($appointment) {
                    $this->recalcCache($appointment);
                }
            }

            return $deleted;
        });
    }

    /**
     * Recompute paid_cents cache + payment_status from ledger truth.
     * Called automatically by record/refund/voidForSale. Public so other
     * code paths (e.g. data backfill, admin tools) can re-sync.
     *
     * Status logic:
     *   sum <= 0 + has refund rows  → 'refunded'
     *   sum == 0 (no refunds)        → 'unpaid'
     *   sum >= total                 → 'paid'
     *   sum > 0 and sum < total      → 'partial' or 'pending_balance'
     *                                  ('pending_balance' if there's an
     *                                  active register draft for the
     *                                  outstanding balance; else 'partial')
     *   sum > total                  → 'overage'
     */
    public function recalcCache(TenantAppointment $appointment): void
    {
        $sum   = (int) $appointment->payments()->sum('amount_cents');
        $hasRefund = $appointment->payments()
            ->whereIn('kind', [
                TenantAppointmentPayment::KIND_REFUND,
                TenantAppointmentPayment::KIND_OVERAGE_REFUND,
            ])
            ->exists();
        $total = (int) $appointment->total_cents;

        $status = $this->computeStatus($sum, $total, $hasRefund, $appointment);

        $appointment->update([
            'paid_cents'     => max(0, $sum), // unsigned column; refunds shouldn't push it negative
            'payment_status' => $status,
        ]);
    }

    private function computeStatus(int $sum, int $total, bool $hasRefund, TenantAppointment $appointment): string
    {
        // Refunded if any refund exists and net is back to zero or below.
        if ($hasRefund && $sum <= 0) {
            return 'refunded';
        }
        if ($sum <= 0) {
            return 'unpaid';
        }
        if ($total === 0) {
            // Edge: appointment with no line items but has payments. Treat
            // as overage so staff can see it and refund.
            return $sum > 0 ? 'overage' : 'unpaid';
        }
        if ($sum >= $total) {
            return $sum > $total ? 'overage' : 'paid';
        }

        // sum > 0 and sum < total: partial OR pending_balance.
        // pending_balance = there's an active register draft for the balance.
        $hasOpenSale = $appointment->sales()
            ->whereNotIn('status', ['cancelled', 'closed'])
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->exists();

        return $hasOpenSale ? 'pending_balance' : 'partial';
    }
}
