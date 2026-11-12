<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-DESTINATION-TAX: sales tax for an order that ships, worked out by Stripe Tax from
 * the destination address, on the SHOP's own Stripe account. Stripe only charges in places
 * the shop has registered in its Stripe Tax settings; everywhere else the tax is zero.
 *
 * build() and parse() are pure (tested with sample payloads); the rest talks to Stripe.
 *
 * Line  ['ref' => string, 'amount' => int cents (after discount), 'code' => Stripe tax code]
 */
class StripeTax
{
    public const GOODS       = 'txcd_99999999';   // general tangible goods
    public const NONTAXABLE  = 'txcd_00000000';
    public const SHIPPING    = 'txcd_92010001';

    public static function build(array $lines, int $shippingCents, array $addr): array
    {
        $items = [];
        foreach ($lines as $l) {
            if ((int) $l['amount'] <= 0) continue;                 // nothing to tax on a free line
            $items[] = ['amount' => (int) $l['amount'], 'reference' => (string) $l['ref'], 'tax_behavior' => 'exclusive', 'tax_code' => $l['code'] ?: self::GOODS];
        }
        $a = array_filter([
            'line1'       => $addr['line1'] ?? null,
            'city'        => $addr['city'] ?? null,
            'state'       => $addr['state'] ?? null,
            'postal_code' => $addr['zip'] ?? null,
            'country'     => 'US',
        ], fn ($v) => $v !== null && $v !== '');

        $out = [
            'currency'         => 'usd',
            'line_items'       => $items,
            'customer_details' => ['address' => $a, 'address_source' => 'shipping'],
        ];
        if ($shippingCents > 0) {
            $out['shipping_cost'] = ['amount' => $shippingCents, 'tax_behavior' => 'exclusive', 'tax_code' => self::SHIPPING];
        }
        return $out;
    }

    /** @return array{calc_id:?string,tax_cents:int,lines:array<string,int>,shipping_tax:int,jurisdictions:array} */
    public static function parse(array $calc): array
    {
        $lines = [];
        foreach ((array) ($calc['line_items']['data'] ?? $calc['line_items'] ?? []) as $li) {
            $ref = (string) ($li['reference'] ?? '');
            if ($ref !== '') $lines[$ref] = (int) ($li['amount_tax'] ?? 0);
        }
        $ship = (int) ($calc['shipping_cost']['amount_tax'] ?? 0);
        $sum  = array_sum($lines) + $ship;
        $stripeTotal = (int) ($calc['tax_amount_exclusive'] ?? $sum);
        if ($stripeTotal !== $sum) {
            Log::warning('stripe_tax.parts_differ', ['calc' => $calc['id'] ?? null, 'parts' => $sum, 'total' => $stripeTotal]);
        }
        $jur = [];
        foreach ((array) ($calc['tax_breakdown'] ?? []) as $b) {
            $d = (array) ($b['tax_rate_details'] ?? []);
            $jur[] = trim(($d['display_name'] ?? 'Tax') . ' ' . ($d['percentage_decimal'] ?? '') . '%');
        }
        return ['calc_id' => $calc['id'] ?? null, 'tax_cents' => $sum, 'lines' => $lines, 'shipping_tax' => $ship, 'jurisdictions' => $jur];
    }

    /** @return array{ok:bool,error:?string}+parse() fields when ok */
    public static function calculate(Tenant $t, array $lines, int $shippingCents, array $addr): array
    {
        $payload = self::build($lines, $shippingCents, $addr);
        if (! $payload['line_items'] && $shippingCents <= 0) {
            return ['ok' => true, 'error' => null, 'calc_id' => null, 'tax_cents' => 0, 'lines' => [], 'shipping_tax' => 0, 'jurisdictions' => []];
        }
        $key = 'stripe_tax:' . md5(json_encode([$t->id, $payload]));
        if ($hit = Cache::get($key)) return $hit;

        try {
            $calc = (new DirectPaymentsService($t))->stripe()->tax->calculations->create($payload)->toArray();
            $res = ['ok' => true, 'error' => null] + self::parse($calc);
            Cache::put($key, $res, 600);
            return $res;
        } catch (\Throwable $e) {
            Log::warning('stripe_tax.calculate_failed', ['tenant' => $t->id, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Record a paid order's calculation as a tax transaction (what Stripe Tax reports and files from). */
    public static function record(Tenant $t, string $calcId, string $reference): ?string
    {
        try {
            $tx = (new DirectPaymentsService($t))->stripe()->tax->transactions->createFromCalculation(['calculation' => $calcId, 'reference' => $reference]);
            return $tx->id;
        } catch (\Throwable $e) {
            Log::error('stripe_tax.record_failed', ['tenant' => $t->id, 'calc' => $calcId, 'reference' => $reference, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** Take tax back out of the report when part of an order's tax is refunded. */
    public static function reverse(Tenant $t, string $txId, string $reference, int $taxCents): bool
    {
        if ($taxCents <= 0) return true;
        try {
            (new DirectPaymentsService($t))->stripe()->tax->transactions->createReversal([
                'original_transaction' => $txId, 'mode' => 'partial', 'flat_amount' => -$taxCents, 'reference' => $reference,
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::error('stripe_tax.reverse_failed', ['tenant' => $t->id, 'tx' => $txId, 'reference' => $reference, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** The settings-page check: a $100 sale to the shop's own ZIP, to see whether Stripe Tax answers. */
    public static function test(Tenant $t, string $zip, string $state): array
    {
        $r = self::calculate($t, [['ref' => 'test', 'amount' => 10000, 'code' => self::GOODS]], 0, ['zip' => $zip, 'state' => $state]);
        if (! $r['ok']) return ['ok' => false, 'message' => 'Stripe Tax did not answer: ' . $r['error']];
        return ['ok' => true, 'message' => 'Stripe Tax works. A $100.00 order to ' . $zip . ' would be taxed $' . number_format($r['tax_cents'] / 100, 2)
            . ($r['jurisdictions'] ? ' (' . implode(', ', $r['jurisdictions']) . ')' : '')
            . ($r['tax_cents'] === 0 ? '. Zero usually means no tax registration is added for that state in your Stripe Tax settings.' : '.')];
    }
}
