<?php

namespace App\Services;

use App\Models\Tenant\TenantClassSession;
use App\Models\Tenant\TenantClassRegistration;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantCustomerMembership;
use App\Models\Tenant\TenantCustomerPack;
use App\Support\MySQLLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Class bookings: register, cancel, waitlist, session cancellation.
 *
 * Every change to a session's bookings takes the same lock,
 * intake:{tenant}:class:{session}, so a cancel and a booking for the last
 * spot can never interleave.
 *
 * $staff = true  -> a staff member is acting (admin screens, register hook).
 *                   Staff may book until the class ENDS and may cancel a
 *                   checked-in booking (correcting a mistake).
 * $staff = false -> the customer is acting (website, portal). Booking and
 *                   cancelling close when the class STARTS, and a checked-in
 *                   booking can't be cancelled (it would hand the credit back
 *                   for a class they attended).
 *
 * Every credit or membership use that moves is written to the class credit
 * ledger (ClassCreditLedger) in the same transaction.
 *
 * MARKER-CLASSES-P0 MARKER-CLASSES-P1
 */
class ClassRegistrationService
{
    private const ACTIVE = ['registered', 'checked_in'];

    // TODO(class-confirmation-emails): class registrations still send no
    // email. Phase 3 of the classes rework adds confirmation, reminder,
    // waitlist and cancellation messages (include class_notes and
    // session_notes_override in the confirmation).

    /**
     * Book a customer into a session, or onto its waitlist when it is full.
     *
     * Payment resolution (resolvePayment):
     *   explicit 'membership' / 'pack' -> that source or an error, never a
     *                                     silent fallback
     *   anything else, $allowCoverage   -> membership, then soonest-expiring
     *                                     pack, then the requested method
     *   $allowCoverage = false          -> the requested method only. Used
     *                                     for guests (who must never spend a
     *                                     matching customer's credits) and for
     *                                     drop-ins already paid at the register.
     */
    public function register(
        string $sessionId,
        string $customerId,
        string $tenantId,
        string $paymentMethod = 'per_class',
        bool $staff = false,
        bool $allowCoverage = true
    ): TenantClassRegistration {
        return $this->locked($tenantId, $sessionId, function () use (
            $sessionId, $customerId, $tenantId, $paymentMethod, $staff, $allowCoverage
        ) {
            $session = TenantClassSession::where('tenant_id', $tenantId)->findOrFail($sessionId);
            TenantCustomer::where('tenant_id', $tenantId)->findOrFail($customerId);

            if (! in_array($session->status, ['scheduled', 'confirmed'], true)) {
                throw new RuntimeException('This class is not taking bookings.');
            }
            if ($staff ? $session->ends_at->isPast() : $session->starts_at->isPast()) {
                throw new RuntimeException($staff
                    ? 'This class has already finished.'
                    : 'This class has already started.');
            }

            $existing = TenantClassRegistration::where('class_session_id', $sessionId)
                ->where('customer_id', $customerId)
                ->whereIn('status', ['registered', 'checked_in', 'waitlisted'])
                ->exists();
            if ($existing) {
                throw new RuntimeException('Customer is already booked into this class or on its waitlist.');
            }

            $resolved = $allowCoverage
                ? $this->resolvePayment($customerId, $tenantId, $paymentMethod, $session)
                : $this->requestedOnly($paymentMethod);

            // A full class goes to the waitlist. (Before this patch a full
            // class was refused outright, so nobody could ever join a waitlist.)
            $isFull      = $session->spotsRemaining() <= 0;
            $status      = $isFull ? 'waitlisted' : 'registered';
            $waitlistPos = null;
            if ($isFull) {
                $waitlistPos = (int) TenantClassRegistration::where('class_session_id', $sessionId)
                    ->where('status', 'waitlisted')
                    ->max('waitlist_position') + 1;
            } else {
                // Consume before writing the row so a failed consume rolls
                // the whole transaction back. Waitlisted people pay nothing
                // until they are moved in.
                $this->consumePaymentSource($resolved);
            }

            $registration = TenantClassRegistration::create([
                'tenant_id'         => $tenantId,
                'class_session_id'  => $sessionId,
                'customer_id'       => $customerId,
                'status'            => $status,
                'payment_method'    => $resolved['method'],
                'membership_id'     => $resolved['membership_id'] ?? null,
                'pack_id'           => $resolved['pack_id'] ?? null,
                'paid_cents'        => $resolved['paid_cents'] ?? 0,
                'waitlist_position' => $waitlistPos,
                'registered_at'     => now(),
            ]);

            if ($status === 'registered') {
                $this->ledgerConsumed($registration, $session);
            }

            return $registration;
        });
    }

    /**
     * Cancel one booking or waitlist place.
     *
     *  - waitlisted: leaves the waitlist; nothing was consumed, nobody moves.
     *  - registered / checked_in: the credit or membership use goes back and
     *    the first waitlisted person who can pay is moved in.
     */
    public function cancel(string $registrationId, string $tenantId, bool $staff = false): void
    {
        $sessionId = TenantClassRegistration::where('tenant_id', $tenantId)
            ->findOrFail($registrationId)->class_session_id;

        $this->locked($tenantId, $sessionId, function () use ($registrationId, $tenantId, $staff) {
            $registration = TenantClassRegistration::where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($registrationId);
            $session = TenantClassSession::where('tenant_id', $tenantId)
                ->findOrFail($registration->class_session_id);

            if ($registration->status === 'waitlisted') {
                $registration->update([
                    'status'            => 'cancelled',
                    'cancelled_at'      => now(),
                    'waitlist_position' => null,
                ]);
                return;
            }

            if (! in_array($registration->status, self::ACTIVE, true)) {
                throw new RuntimeException('This booking is already cancelled.');
            }
            if (! $staff && $registration->status === 'checked_in') {
                throw new RuntimeException("You've already been checked in to this class.");
            }
            if (! $staff && $session->starts_at->isPast()) {
                throw new RuntimeException('This class has already started.');
            }

            $this->restorePaymentSource($registration, $tenantId, $staff ? 'Removed by staff' : 'Cancelled by the customer');
            $registration->cancel();

            if ($session->starts_at->isFuture()
                && in_array($session->status, ['scheduled', 'confirmed'], true)) {
                $this->promoteFromWaitlist($session, $tenantId);
            }
        });
    }

    /**
     * Cancel every booking on a session that is itself being cancelled.
     * Credits and membership uses go back; waitlist places are cleared.
     * Nobody is promoted. Returns counts for the staff message.
     *
     * @return array{cancelled:int, restored:int, waitlist:int, paid_at_desk:int}
     */
    public function cancelSession(string $sessionId, string $tenantId): array
    {
        return $this->locked($tenantId, $sessionId, function () use ($sessionId, $tenantId) {
            $counts = ['cancelled' => 0, 'restored' => 0, 'waitlist' => 0, 'paid_at_desk' => 0];

            $rows = TenantClassRegistration::where('tenant_id', $tenantId)
                ->where('class_session_id', $sessionId)
                ->whereIn('status', ['registered', 'checked_in', 'waitlisted'])
                ->lockForUpdate()
                ->get();

            foreach ($rows as $r) {
                if ($r->status === 'waitlisted') {
                    $counts['waitlist']++;
                } else {
                    $counts['cancelled']++;
                    if (in_array($r->payment_method, ['pack', 'membership'], true)) {
                        $this->restorePaymentSource($r, $tenantId, 'Class cancelled by the studio');
                        $counts['restored']++;
                    } elseif ($r->payment_method === 'cash') {
                        $counts['paid_at_desk']++;
                    }
                }
                $r->metadata = array_merge($r->metadata ?? [], ['cancel_reason' => 'session_cancelled']);
                $r->status            = 'cancelled';
                $r->cancelled_at      = now();
                $r->waitlist_position = null;
                $r->save();
            }

            return $counts;
        });
    }

    public function checkIn(string $registrationId, string $tenantId): void
    {
        $registration = TenantClassRegistration::where('tenant_id', $tenantId)
            ->where('status', 'registered')
            ->findOrFail($registrationId);

        $registration->update(['status' => 'checked_in']);
    }

    public function markNoShow(string $registrationId, string $tenantId): void
    {
        $registration = TenantClassRegistration::where('tenant_id', $tenantId)
            ->where('status', 'registered')
            ->findOrFail($registrationId);

        $registration->update(['status' => 'no_show']);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function locked(string $tenantId, string $sessionId, callable $work): mixed
    {
        return app(MySQLLock::class)->withLock(
            "intake:{$tenantId}:class:{$sessionId}",
            fn () => DB::transaction($work)
        );
    }

    /**
     * Move the first waitlisted person who can still pay into the class.
     * Each person keeps the payment method they joined with. Someone whose
     * pack or membership no longer covers it stays on the waitlist and the
     * next person is tried; one empty pack must never block a cancellation.
     */
    private function promoteFromWaitlist(TenantClassSession $session, string $tenantId): void
    {
        if ($session->spotsRemaining() <= 0) {
            return;
        }

        $waiting = TenantClassRegistration::where('class_session_id', $session->id)
            ->where('status', 'waitlisted')
            ->orderBy('waitlist_position')
            ->get();

        foreach ($waiting as $next) {
            try {
                $resolved = $this->resolvePayment($next->customer_id, $tenantId, $next->payment_method, $session);
            } catch (RuntimeException $e) {
                Log::info('classes: waitlist promotion skipped, no coverage', [
                    'registration_id' => $next->id,
                    'reason'          => $e->getMessage(),
                ]);
                continue;
            }

            $this->consumePaymentSource($resolved);
            $next->update([
                'status'            => 'registered',
                'waitlist_position' => null,
                'payment_method'    => $resolved['method'],
                'membership_id'     => $resolved['membership_id'] ?? null,
                'pack_id'           => $resolved['pack_id'] ?? null,
            ]);
            $this->ledgerConsumed($next->fresh(), $session, 'Moved in from the waitlist');
            return;
        }
    }

    private function ledgerConsumed(TenantClassRegistration $r, TenantClassSession $session, ?string $why = null): void
    {
        if (! in_array($r->payment_method, ['pack', 'membership'], true)) {
            return;
        }
        $label = optional($session->template)->name ?? 'Class';
        ClassCreditLedger::record($r->tenant_id, $r->customer_id, -1, 'booked', [
            'pack_id'         => $r->pack_id,
            'membership_id'   => $r->membership_id,
            'registration_id' => $r->id,
        ], trim($label . ' · ' . $session->starts_at->format('M j g:ia') . ($why ? ' · ' . $why : '')));
    }

    private function requestedOnly(string $requestedMethod): array
    {
        return [
            'method'        => in_array($requestedMethod, ['per_class', 'cash'], true) ? $requestedMethod : 'per_class',
            'membership_id' => null,
            'pack_id'       => null,
            'paid_cents'    => 0,
        ];
    }

    /**
     * Which of the customer's memberships and packs can pay for this class.
     * A product with covers = NULL covers every class; otherwise only the
     * class types listed. Memberships before packs; an unlimited membership
     * before a capped one; packs soonest-expiring first.
     */
    private function resolvePayment(string $customerId, string $tenantId, string $requestedMethod, ?TenantClassSession $session = null): array
    {
        $templateId = $session?->class_template_id;
        $covers = function ($product) use ($templateId): bool {
            if (! $product) {
                return false;
            }
            $list = $product->covers;
            return empty($list) || $templateId === null || in_array($templateId, (array) $list, true);
        };

        $membership = TenantCustomerMembership::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->whereIn('status', ['active', 'past_due']) // MARKER-CLASSES-P2
            ->with('product')
            ->get()
            ->filter(fn ($m) => $m->canCoverClass() && $covers($m->product))
            ->sortBy(fn ($m) => $m->product->isUnlimited() ? 0 : 1)
            ->first();

        $pack = TenantCustomerPack::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->consumptionOrder()
            ->with('product')
            ->get()
            ->first(fn ($p) => $covers($p->product));

        // Explicit choice: honour it strictly. A silent fallback would let
        // staff believe coverage was used when it wasn't.
        if ($requestedMethod === 'membership') {
            if (! $membership) {
                throw new RuntimeException('Customer has no active membership with classes left that covers this class. Pick a different payment method.');
            }
            return ['method' => 'membership', 'membership_id' => $membership->id, 'pack_id' => null, 'paid_cents' => 0, 'source' => $membership];
        }
        if ($requestedMethod === 'pack') {
            if (! $pack) {
                throw new RuntimeException('Customer has no usable pack that covers this class. Pick a different payment method.');
            }
            return ['method' => 'pack', 'membership_id' => null, 'pack_id' => $pack->id, 'paid_cents' => 0, 'source' => $pack];
        }

        if ($membership) {
            return ['method' => 'membership', 'membership_id' => $membership->id, 'pack_id' => null, 'paid_cents' => 0, 'source' => $membership];
        }
        if ($pack) {
            return ['method' => 'pack', 'membership_id' => null, 'pack_id' => $pack->id, 'paid_cents' => 0, 'source' => $pack];
        }

        return $this->requestedOnly($requestedMethod);
    }

    private function consumePaymentSource(array $resolved): void
    {
        match ($resolved['method']) {
            'membership' => $resolved['source']->increment('classes_used_this_period'),
            'pack'       => $resolved['source']->deductCredit(),
            default      => null,
        };
    }

    /**
     * Undo consumePaymentSource(). Tolerant: a revoked pack or membership,
     * or a per_class / cash booking, is a no-op. Membership use never goes
     * below zero (the period may have rolled over since booking).
     */
    private function restorePaymentSource(TenantClassRegistration $registration, string $tenantId, ?string $why = null): void
    {
        $refs = ['registration_id' => $registration->id];
        if ($registration->payment_method === 'pack' && $registration->pack_id) {
            $pack = TenantCustomerPack::where('tenant_id', $tenantId)->find($registration->pack_id);
            if ($pack) {
                $pack->restoreCredit();
                ClassCreditLedger::record($tenantId, $registration->customer_id, 1, 'restored', $refs + ['pack_id' => $pack->id], $why);
            }
        } elseif ($registration->payment_method === 'membership' && $registration->membership_id) {
            $membership = TenantCustomerMembership::where('tenant_id', $tenantId)->find($registration->membership_id);
            if ($membership && $membership->classes_used_this_period > 0) {
                $membership->decrement('classes_used_this_period');
                ClassCreditLedger::record($tenantId, $registration->customer_id, 1, 'restored', $refs + ['membership_id' => $membership->id], $why);
            }
        }
    }
}

