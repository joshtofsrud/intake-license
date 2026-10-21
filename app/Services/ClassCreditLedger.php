<?php

namespace App\Services;

use App\Models\Tenant\TenantClassCreditEntry;

/**
 * Writes the class credit ledger. Call it in the same transaction as the
 * change it records, so the ledger and the cached totals
 * (credits_remaining, classes_used_this_period) can't disagree.
 *
 * MARKER-CLASSES-P1
 */
class ClassCreditLedger
{
    /**
     * @param array{pack_id?:?string, membership_id?:?string, registration_id?:?string, sale_id?:?string} $refs
     */
    public static function record(string $tenantId, string $customerId, int $delta, string $reason, array $refs = [], ?string $note = null): void
    {
        $actor = null;
        try {
            $actor = auth('tenant')->id();
        } catch (\Throwable $e) {
            // console / queue: no staff member
        }

        TenantClassCreditEntry::create([
            'tenant_id'       => $tenantId,
            'customer_id'     => $customerId,
            'pack_id'         => $refs['pack_id'] ?? null,
            'membership_id'   => $refs['membership_id'] ?? null,
            'registration_id' => $refs['registration_id'] ?? null,
            'sale_id'         => $refs['sale_id'] ?? null,
            'delta'           => $delta,
            'reason'          => $reason,
            'note'            => $note !== null ? mb_substr($note, 0, 255) : null,
            'actor_user_id'   => $actor,
        ]);
    }
}

