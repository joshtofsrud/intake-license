<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantClassSession;
use App\Models\Tenant\TenantClassRegistration;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantCustomerMembership;
use App\Models\Tenant\TenantCustomerPack;
use App\Support\MySQLLock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ClassRegistrationService
{
    /**
     * Register a customer for a class session.
     *
     * Payment resolution order (enforced here, never at the call site):
     *   1. Active membership with remaining capacity → payment_method = membership
     *   2. Non-expired pack with credits remaining, oldest expiry first → payment_method = pack
     *   3. No coverage → payment_method = per_class (Stripe via Connect, post-launch)
     *                  or cash (manual, admin-only)
     *
     * Lock scope: intake:{tenant}:class:{session} — one registration
     * at a time per session to prevent capacity races.
     */
    public function register(
        string $sessionId,
        string $customerId,
        string $tenantId,
        string $paymentMethod = 'per_class'
    ): TenantClassRegistration {
        $lockKey = "intake:{$tenantId}:class:{$sessionId}";

        $lock = app(MySQLLock::class);

        return $lock->withLock($lockKey, function () use (
            $sessionId, $customerId, $tenantId, $paymentMethod
        ) {
            return DB::transaction(function () use (
                $sessionId, $customerId, $tenantId, $paymentMethod
            ) {
                $session = TenantClassSession::where('tenant_id', $tenantId)
                    ->findOrFail($sessionId);

                // Guard: session must be bookable
                if (!$session->isBookable()) {
                    throw new RuntimeException('This class session is not available for registration.');
                }

                // Guard: no duplicate active registration
                $existing = TenantClassRegistration::where('class_session_id', $sessionId)
                    ->where('customer_id', $customerId)
                    ->whereIn('status', ['registered', 'checked_in', 'waitlisted'])
                    ->exists();

                if ($existing) {
                    throw new RuntimeException('Customer is already registered for this session.');
                }

                // Resolve payment method
                $resolved = $this->resolvePayment($customerId, $tenantId, $paymentMethod);

                // Determine spot vs waitlist
                $spotsRemaining = $session->spotsRemaining();
                $status         = $spotsRemaining > 0 ? 'registered' : 'waitlisted';
                $waitlistPos    = null;

                if ($status === 'waitlisted') {
                    $waitlistPos = TenantClassRegistration::where('class_session_id', $sessionId)
                        ->where('status', 'waitlisted')
                        ->max('waitlist_position') + 1;
                }

                // Consume the payment source before writing the registration
                // so a failed consume rolls back the whole transaction
                if ($status === 'registered') {
                    $this->consumePaymentSource($resolved, $tenantId);
                }

                return TenantClassRegistration::create([
                    'tenant_id'        => $tenantId,
                    'class_session_id' => $sessionId,
                    'customer_id'      => $customerId,
                    'status'           => $status,
                    'payment_method'   => $resolved['method'],
                    'membership_id'    => $resolved['membership_id'] ?? null,
                    'pack_id'          => $resolved['pack_id'] ?? null,
                    'paid_cents'       => $resolved['paid_cents'] ?? 0,
                    'waitlist_position'=> $waitlistPos,
                    'registered_at'    => now(),
                ]);
            });
        });
    }

    /**
     * Cancel a registration. If the session has a waitlist, promote
     * the next customer automatically.
     */
    public function cancel(string $registrationId, string $tenantId): void
    {
        DB::transaction(function () use ($registrationId, $tenantId) {
            $registration = TenantClassRegistration::where('tenant_id', $tenantId)
                ->findOrFail($registrationId);

            if (!$registration->isActive()) {
                throw new RuntimeException('Registration is not in a cancellable state.');
            }

            // Restore the payment source on the cancelled registration BEFORE
            // marking it cancelled. Pack credits go back, membership usage
            // counter ticks back. Per-class and cash never had pre-consumption,
            // so nothing to restore for those methods.
            $this->restorePaymentSource($registration, $tenantId);

            $registration->cancel();

            // Promote next waitlisted customer
            $next = TenantClassRegistration::where('class_session_id', $registration->class_session_id)
                ->where('status', 'waitlisted')
                ->orderBy('waitlist_position')
                ->first();

            if ($next) {
                $resolved = $this->resolvePayment(
                    $next->customer_id,
                    $tenantId,
                    $next->payment_method
                );

                $this->consumePaymentSource($resolved, $tenantId);

                $next->update([
                    'status'           => 'registered',
                    'waitlist_position'=> null,
                    'payment_method'   => $resolved['method'],
                    'membership_id'    => $resolved['membership_id'] ?? null,
                    'pack_id'          => $resolved['pack_id'] ?? null,
                ]);
            }
        });
    }

    /**
     * Check in a customer to a session.
     */
    public function checkIn(string $registrationId, string $tenantId): void
    {
        $registration = TenantClassRegistration::where('tenant_id', $tenantId)
            ->where('status', 'registered')
            ->findOrFail($registrationId);

        $registration->update(['status' => 'checked_in']);
    }

    /**
     * Mark a registered customer as no-show.
     */
    public function markNoShow(string $registrationId, string $tenantId): void
    {
        $registration = TenantClassRegistration::where('tenant_id', $tenantId)
            ->where('status', 'registered')
            ->findOrFail($registrationId);

        $registration->update(['status' => 'no_show']);
    }

    // ------------------------------------------------------------------
    // Payment resolution
    // ------------------------------------------------------------------

    /**
     * Resolve which payment source covers this registration.
     *
     * Returns an array with:
     *   method        — 'membership' | 'pack' | 'per_class' | 'cash'
     *   membership_id — uuid or null
     *   pack_id       — uuid or null
     *   paid_cents    — 0 unless per_class
     */
    private function resolvePayment(
        string $customerId,
        string $tenantId,
        string $requestedMethod
    ): array {
        // Look up coverage. Both lookups are cheap and we may need either
        // depending on what the caller requested.
        $membership = TenantCustomerMembership::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('status', 'active')
            ->with('product')
            ->first();

        $pack = TenantCustomerPack::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->consumptionOrder()
            ->first();

        // If admin explicitly picked membership or pack, honor that choice
        // strictly — do NOT silently fall back to per_class. A silent fallback
        // here means the admin thinks coverage was used when it wasn't, the
        // customer never gets billed, and the audit trail is wrong.
        if ($requestedMethod === 'membership') {
            if (!$membership || !$membership->canCoverClass()) {
                throw new RuntimeException(
                    'Customer has no active membership with capacity. Pick a different payment method.'
                );
            }
            return [
                'method'        => 'membership',
                'membership_id' => $membership->id,
                'pack_id'       => null,
                'paid_cents'    => 0,
                'source'        => $membership,
            ];
        }

        if ($requestedMethod === 'pack') {
            if (!$pack) {
                throw new RuntimeException(
                    'Customer has no usable pack. Pick a different payment method.'
                );
            }
            return [
                'method'        => 'pack',
                'membership_id' => null,
                'pack_id'       => $pack->id,
                'paid_cents'    => 0,
                'source'        => $pack,
            ];
        }

        // For per_class / cash / customer-portal flows where the requestedMethod
        // is permissive, prefer best-available coverage automatically: membership
        // first, then pack, then fall through to the requested cash/per_class.
        // This path is the one the customer self-service portal uses.
        if ($membership && $membership->canCoverClass()) {
            return [
                'method'        => 'membership',
                'membership_id' => $membership->id,
                'pack_id'       => null,
                'paid_cents'    => 0,
                'source'        => $membership,
            ];
        }

        if ($pack) {
            return [
                'method'        => 'pack',
                'membership_id' => null,
                'pack_id'       => $pack->id,
                'paid_cents'    => 0,
                'source'        => $pack,
            ];
        }

        return [
            'method'        => in_array($requestedMethod, ['per_class', 'cash'])
                                ? $requestedMethod
                                : 'per_class',
            'membership_id' => null,
            'pack_id'       => null,
            'paid_cents'    => 0,
        ];
    }

    /**
     * Consume the resolved payment source.
     * Called inside the DB transaction after capacity is confirmed.
     */
    private function consumePaymentSource(array $resolved, string $tenantId): void
    {
        match ($resolved['method']) {
            'membership' => $resolved['source']->increment('classes_used_this_period'),
            'pack'       => $resolved['source']->deductCredit(),
            default      => null, // per_class and cash: no pre-consumption
        };
    }

    /**
     * Restore the payment source for a cancelled registration. Reverses what
     * consumePaymentSource() did at registration time.
     *
     * Intentionally tolerant — if the source no longer exists (admin revoked
     * the pack/membership manually), or the registration was per_class/cash,
     * we silently no-op. The registration still gets cancelled either way.
     *
     * Edge case: membership usage shouldn't go negative. If the period rolled
     * over since registration (so classes_used_this_period was already reset
     * to 0), decrementing would underflow. Guard with a max(0, x-1) pattern.
     */
    private function restorePaymentSource(TenantClassRegistration $registration, string $tenantId): void
    {
        if ($registration->payment_method === 'pack' && $registration->pack_id) {
            $pack = TenantCustomerPack::where('tenant_id', $tenantId)
                ->find($registration->pack_id);
            if ($pack) {
                $pack->restoreCredit();
            }
        } elseif ($registration->payment_method === 'membership' && $registration->membership_id) {
            $membership = TenantCustomerMembership::where('tenant_id', $tenantId)
                ->find($registration->membership_id);
            if ($membership && $membership->classes_used_this_period > 0) {
                $membership->decrement('classes_used_this_period');
            }
        }
        // per_class, cash, or unrecognized methods: no pre-consumption to undo.
    }
}
