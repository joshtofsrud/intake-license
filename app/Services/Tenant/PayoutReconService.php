<?php
// MARKER-PATCH-635 — fetch Stripe payouts + their charges and match each
// charge to the sales-as-money ledger by PaymentIntent id
// (tenant_sale_payments.external_reference holds the PI for card rows).

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantSalePayment;
use App\Models\Tenant\TenantStripePayout;
use Stripe\StripeClient;

class PayoutReconService
{
    public function __construct(protected Tenant $tenant) {}

    public function available(): bool
    {
        return (new DirectPaymentsService($this->tenant))->activeSecretKey() !== null;
    }

    /** Fetch payouts arriving in [$fromUtc, $toUtc) and cache with matching. */
    public function refreshRange(\Carbon\CarbonInterface $fromUtc, \Carbon\CarbonInterface $toUtc): int
    {
        $secret = (new DirectPaymentsService($this->tenant))->activeSecretKey();
        if (! $secret) return 0;

        $stripe = new StripeClient($secret);
        $payouts = $stripe->payouts->all([
            'arrival_date' => ['gte' => $fromUtc->timestamp, 'lt' => $toUtc->timestamp],
            'limit'        => 50,
        ]);

        $count = 0;
        foreach ($payouts->data as $po) {
            $items = [];
            $gross = $fees = 0;

            $txns = $stripe->balanceTransactions->all([
                'payout' => $po->id,
                'type'   => 'charge',
                'limit'  => 100,
                'expand' => ['data.source'],
            ]);
            foreach ($txns->autoPagingIterator() as $bt) {
                $pi = is_object($bt->source) ? ($bt->source->payment_intent ?? null) : null;
                $items[] = [
                    'charge'  => is_object($bt->source) ? $bt->source->id : (string) $bt->source,
                    'pi'      => $pi,
                    'amount'  => (int) $bt->amount,
                    'fee'     => (int) $bt->fee,
                    'created' => $bt->created,
                    'matched' => false,
                ];
                $gross += (int) $bt->amount;
                $fees  += (int) $bt->fee;
            }

            // match by PI against the ledger
            $pis = array_values(array_filter(array_column($items, 'pi')));
            $found = $pis ? TenantSalePayment::where('tenant_id', $this->tenant->id)
                ->whereIn('external_reference', $pis)
                ->pluck('external_reference')->all() : [];
            $foundSet = array_flip($found);
            $unmatched = 0;
            foreach ($items as &$it) {
                $it['matched'] = $it['pi'] !== null && isset($foundSet[$it['pi']]);
                if (! $it['matched']) $unmatched++;
            }
            unset($it);

            TenantStripePayout::updateOrCreate(
                ['tenant_id' => $this->tenant->id, 'payout_id' => $po->id],
                [
                    'arrived_on'      => \Carbon\Carbon::createFromTimestamp($po->arrival_date, 'UTC')->toDateString(),
                    'gross_cents'     => $gross,
                    'fee_cents'       => $fees,
                    'net_cents'       => (int) $po->amount,
                    'charge_count'    => count($items),
                    'unmatched_count' => $unmatched,
                    'items'           => $items,
                    'fetched_at'      => now(),
                ]
            );
            $count++;
        }

        return $count;
    }
}

