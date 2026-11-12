<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MARKER-SHOP-GA4: a shop's own Google Analytics 4 ecommerce events.
 * Pages send the browsing events themselves (view item, add to cart, checkout, purchase).
 * The two moments no page sees (a hand-confirmed payment, a refund) go from the server through
 * the Measurement Protocol, using the shop's own API secret. The pure builders here are tested.
 *
 * item_id is the item's own id, the same id the Google Merchant feed uses, so Google can tie
 * Analytics, Ads and Merchant Center to one product.
 */
class ShopGa4
{
    public static function id(Tenant $t): string
    {
        $id = trim((string) (($t->settings ?? [])['analytics_ga4_id'] ?? ''));
        return preg_match('/^G-[A-Z0-9]{4,20}$/i', $id) ? $id : '';
    }

    /** Shop events are on once there is a measurement ID, unless the shop switched them off. */
    public static function on(Tenant $t): bool
    {
        return self::id($t) !== '' && (bool) (($t->settings ?? [])['analytics_ecom_on'] ?? true);
    }

    public static function secret(Tenant $t): ?string
    {
        $raw = (string) (($t->settings ?? [])['analytics_ga4_secret'] ?? '');
        if ($raw === '') return null;
        try { return Crypt::decryptString($raw); } catch (\Throwable) { return null; }
    }

    /** One product line in GA4's shape. $f: id, name, brand, category, price_cents, qty. */
    public static function item(array $f): array
    {
        $i = [
            'item_id'   => (string) $f['id'],
            'item_name' => (string) $f['name'],
            'price'     => round(((int) ($f['price_cents'] ?? 0)) / 100, 2),
            'quantity'  => max(1, (int) ($f['qty'] ?? 1)),
        ];
        if (trim((string) ($f['brand'] ?? '')) !== '')    $i['item_brand'] = trim((string) $f['brand']);
        if (trim((string) ($f['category'] ?? '')) !== '') $i['item_category'] = trim((string) $f['category']);
        return $i;
    }

    /** An item model (brand and category already loaded) in GA4's shape. */
    public static function forItem($item, int $qty = 1): array
    {
        return self::item([
            'id' => $item->id, 'name' => $item->name,
            'brand' => $item->shop_brand ?: $item->distributorCatalog?->manufacturer,
            'category' => $item->category?->name,
            'price_cents' => $item->effectiveSellPriceCents() ?? 0, 'qty' => $qty,
        ]);
    }

    /** A cart or order line (a snapshot of what was in the cart) in GA4's shape. */
    public static function forLine($line): array
    {
        return self::item(['id' => $line->inventory_item_id ?: $line->id, 'name' => $line->name_snapshot,
            'price_cents' => $line->unit_price_cents, 'qty' => $line->quantity]);
    }

    /**
     * The purchase event. value is what the goods earned (after any discount); tax and shipping
     * travel separately, as Google expects.
     * $o: number, subtotal_cents, discount_cents, tax_cents, shipping_cents, currency, coupon, items[] (item facts)
     */
    public static function purchase(array $o): array
    {
        $p = [
            'transaction_id' => (string) $o['number'],
            'value'    => round(max(0, (int) $o['subtotal_cents'] - (int) ($o['discount_cents'] ?? 0)) / 100, 2),
            'tax'      => round(((int) ($o['tax_cents'] ?? 0)) / 100, 2),
            'shipping' => round(((int) ($o['shipping_cents'] ?? 0)) / 100, 2),
            'currency' => strtoupper((string) ($o['currency'] ?: 'USD')),
            'items'    => array_map([self::class, 'item'], (array) ($o['items'] ?? [])),
        ];
        if (trim((string) ($o['coupon'] ?? '')) !== '') $p['coupon'] = trim((string) $o['coupon']);
        return $p;
    }

    /** The visitor's own GA ids, so a later server-side event is credited to their visit. */
    public static function clientFromCookies(string $measurementId): ?array
    {
        $c = (string) ($_COOKIE['_ga'] ?? '');
        if (! preg_match('/^GA\d\.\d+\.(\d+\.\d+)$/', $c, $m)) return null;
        $out = ['client_id' => $m[1]];
        $s = (string) ($_COOKIE['_ga_' . strtoupper(substr($measurementId, 2))] ?? '');
        if (preg_match('/^GS1\.\d+\.(\d+)\./', $s, $x) || preg_match('/^GS2\.\d+\.s(\d+)/', $s, $x)) $out['session_id'] = $x[1];
        return $out;
    }

    /** Send one event from the server. Never throws; false when it could not or should not be sent. */
    public static function send(Tenant $t, string $event, array $params, ?array $ga): bool
    {
        try {
            $id = self::id($t);
            $secret = self::secret($t);
            if (! self::on($t) || ! $secret || ! $ga || empty($ga['client_id'])) return false;
            if (! empty($ga['session_id'])) $params['session_id'] = $ga['session_id'];
            $params['engagement_time_msec'] = 1;
            $r = Http::timeout(4)->asJson()->post(
                'https://www.google-analytics.com/mp/collect?measurement_id=' . urlencode($id) . '&api_secret=' . urlencode($secret),
                ['client_id' => $ga['client_id'], 'events' => [['name' => $event, 'params' => $params]]]
            );
            if (! $r->successful()) Log::warning('shop GA4 event not accepted', ['event' => $event, 'status' => $r->status(), 'tenant' => $t->id]);
            return $r->successful();
        } catch (\Throwable $e) {
            Log::warning('shop GA4 event failed', ['event' => $event, 'error' => $e->getMessage(), 'tenant' => $t->id]);
            return false;
        }
    }
}
