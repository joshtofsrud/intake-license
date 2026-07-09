<?php
// MARKER-PATCH-618 — build peer-to-peer pay links for manual tenders.
// These open the customer's Venmo/Cash App to pay the tenant's handle. Neither
// has a payment API, so there is NO confirmation callback — the sale stays a
// manual "mark paid" tender. Money lands in the tenant's OWN Venmo/Cash App
// balance (not Stripe).

namespace App\Services\Tenant;

class ManualPaymentLink
{
    /** Enabled manual tenders for a tenant: ['venmo'=>bool, 'cash_app'=>bool]. */
    public static function enabled($tenant): array
    {
        $s = $tenant->settings ?? [];
        return [
            'venmo'    => (bool) ($s['venmo_enabled'] ?? false)   && !empty($s['venmo_handle']),
            'cash_app' => (bool) ($s['cashapp_enabled'] ?? false) && !empty($s['cashapp_cashtag']),
        ];
    }

    /**
     * Build the pay link for a method. $amount is in dollars (float|string|null).
     *   cash_app: https://cash.app/$cashtag/AMOUNT  (amount prefills)
     *   venmo:    https://venmo.com/u/handle        (amount prefill unreliable)
     * Returns null if the method isn't configured.
     */
    public static function for($tenant, string $method, $amount = null, ?string $note = null): ?string
    {
        $s = $tenant->settings ?? [];

        if ($method === 'cash_app') {
            $tag = ltrim(trim($s['cashapp_cashtag'] ?? ''), '$');
            if ($tag === '') return null;
            $url = 'https://cash.app/$' . rawurlencode($tag);
            if ($amount !== null && (float) $amount > 0) {
                $url .= '/' . number_format((float) $amount, 2, '.', '');
            }
            return $url;
        }

        if ($method === 'venmo') {
            $handle = ltrim(trim($s['venmo_handle'] ?? ''), '@');
            if ($handle === '') return null;
            // Profile link is the reliable one; the pay intent params are best-effort.
            $url = 'https://venmo.com/u/' . rawurlencode($handle);
            $params = [];
            if ($amount !== null && (float) $amount > 0) $params['amount'] = number_format((float) $amount, 2, '.', '');
            if ($note) $params['note'] = $note;
            if ($params) $url .= '?' . http_build_query($params);
            return $url;
        }

        return null;
    }

    /** Human label for a manual method. */
    public static function label(string $method): string
    {
        return ['venmo' => 'Venmo', 'cash_app' => 'Cash App'][$method] ?? ucfirst($method);
    }
}

