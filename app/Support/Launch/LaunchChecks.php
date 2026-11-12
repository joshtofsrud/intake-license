<?php

namespace App\Support\Launch;

use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryItem;
use App\Services\Tenant\DirectPaymentsService;
use App\Services\Tenant\OnlineItems;
use App\Services\Tenant\OrderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-LAUNCH-CHECKLIST: the automatic checks a step can use. Each reads the
 * shop's own data and changes nothing. A new kind of check is added here; the
 * wording, order and level of a step are master admin's.
 *
 * A check returns ['s' => ok|warn|bad|na|hide, 'line' => what the shop reads].
 *   na   = not needed for this shop (counts as done)
 *   hide = the step does not apply to this shop at all (not shown, not counted)
 */
class LaunchChecks
{
    /** key => [label shown in master admin, module or null for both] */
    public static function catalog(): array
    {
        return [
            'stripe'          => ['Stripe connected with live keys', 'ecom'],
            'tax'             => ['Sales tax method saved', 'ecom'],
            'items_online'    => ['At least one item for sale online', 'ecom'],
            'item_quality'    => ['Online items have a photo and a price', 'ecom'],
            'weights'         => ['Shipped items have a weight', 'ecom'],
            'fulfil'          => ['Pickup, delivery or shipping is on', 'ecom'],
            'ship_prices'     => ['Shipping prices complete for the chosen method', 'ecom'],
            'warehouses'      => ['A supplier warehouse is on (when offered)', 'ecom'],
            'returns_page'    => ['A returns page is chosen and published', 'ecom'],
            'order_notify'    => ['Someone is told about new orders', 'ecom'],
            'feed'            => ['Google feed on', 'ecom'],
            'ga4'             => ['GA4 id saved and shop events on', 'ecom'],
            'test_order'      => ['An online order has been paid', 'ecom'],
            'fleet'           => ['A unit is ready to rent online', 'rent'],
            'unit_ids'        => ['Units have an identifier', 'rent'],
            'model_rates'     => ['Bookable models have a daily rate', 'rent'],
            'deposits'        => ['Models take a deposit', 'rent'],
            'rent_pay_choice' => ['How renters pay is saved', 'rent'],
            'rent_payments'   => ['Stripe connected when renters pay online', 'rent'],
            'agreement'       => ['A rental agreement is published', 'rent'],
            'late_fees'       => ['Late and overdue policy saved', 'rent'],
            'condition'       => ['A condition checklist exists', 'rent'],
            'rent_hours'      => ['Pickup and return hours have an open day', 'rent'],
            'booking_page'    => ['"Show rentals in my menu" is on', 'rent'],
            'test_booking'    => ['A rental has been returned', 'rent'],
        ];
    }

    /** Checks too slow for the 15-minute sweep. They run when the checklist is opened. */
    public const HEAVY = ['item_quality', 'weights', 'feed'];

    public static function run(string $check, Tenant $t): array
    {
        try {
            $fn = 'c_' . $check;
            if (! method_exists(self::class, $fn)) return ['s' => 'bad', 'line' => 'This check is not available.'];
            return self::$fn($t);
        } catch (\Throwable $e) {
            report($e);
            return ['s' => 'warn', 'line' => 'This could not be checked just now. It will try again.'];
        }
    }

    /** Where the setting that fixes a check lives. */
    public static function fixUrl(string $check, Tenant $t): ?string
    {
        $sf = fn (string $tab) => route('tenant.storefront.settings', ['tab' => $tab]);
        return match ($check) {
            'stripe', 'rent_payments' => route('tenant.settings.payments.index'),
            'tax'                     => $sf('tax'),
            'items_online', 'item_quality' => $sf('avail'),
            'weights', 'fulfil', 'ship_prices', 'warehouses' => $sf('ful'),
            'returns_page', 'order_notify' => $sf('msg'),
            'feed', 'ga4'             => $sf('goo'),
            'test_order'              => url('/shop'),
            'fleet', 'unit_ids', 'model_rates', 'deposits', 'condition' => route('tenant.rentals.fleet'),
            'rent_pay_choice', 'agreement', 'late_fees', 'rent_hours', 'booking_page' => route('tenant.rentals.settings'),
            'test_booking'            => url('/rentals'),
            default                   => null,
        };
    }

    public static function fixLabel(string $check): string
    {
        return match ($check) {
            'test_order' => 'Open your store',
            'test_booking' => 'Open your booking page',
            default => 'Fix this',
        };
    }

    // ------------------------------------------------------------ shared

    private static function s(Tenant $t): array
    {
        return (array) (($t->settings['storefront'] ?? []) ?: []);
    }

    private static function stripeLine(Tenant $t): array
    {
        $p = new DirectPaymentsService($t);
        if (! $p->isEnabled()) return ['s' => 'bad', 'line' => "Stripe isn't connected yet."];
        if ($p->mode() !== 'live') return ['s' => 'bad', 'line' => 'Stripe is in test mode, so real cards cannot be charged.'];
        return ['s' => 'ok', 'line' => 'Stripe connected · live card payments on'];
    }

    private static function plural(int $n, string $one, ?string $many = null): string
    {
        return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
    }

    /**
     * Online items with photo, price and weight facts. Counting them means
     * walking every online item, which is far too slow for a page load on a
     * big shop, so it only ever happens in LaunchItemFactsJob. A page reads
     * the saved count; null means it is still being counted (a job is queued).
     */
    private static function itemFacts(Tenant $t): ?array
    {
        $f = Cache::get(self::factsKey($t));
        if (is_array($f)) return $f;
        self::queueFacts($t);
        return null;
    }

    public static function factsKey(Tenant $t): string
    {
        return 'launch:items:' . $t->id;
    }

    public static function queueFacts(Tenant $t): void
    {
        try {
            if (Cache::add('launch:items:queued:' . $t->id, 1, 900)) {
                \App\Jobs\LaunchItemFactsJob::dispatch((string) $t->id);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** The slow walk. Called by the job only. */
    public static function countFacts(Tenant $t): array
    {
        $out = ['online' => 0, 'no_photo' => 0, 'no_price' => 0, 'no_weight' => 0, 'feed_blocked' => 0, 'at' => now()->toIso8601String()];
        \App\Services\Feeds\GoogleFeed::walk($t, function ($item, array $verdict) use (&$out) {
            $out['online']++;
            $reasons = (array) ($verdict['reasons'] ?? []);
            if (in_array('No photo', $reasons, true)) $out['no_photo']++;
            if (in_array('No price', $reasons, true)) $out['no_price']++;
            if ($reasons) $out['feed_blocked']++;
            if ((($verdict['attrs'] ?? [])['shipping_weight'] ?? null) === null && \App\Services\Shipping\ItemShipSpec::for($item)['oz'] === null) $out['no_weight']++;
        });
        Cache::put(self::factsKey($t), $out, now()->addHours(6));
        Cache::forget('launch:items:queued:' . $t->id);
        return $out;
    }

    /** "Check again": throw away the saved count and count again in the background. */
    public static function forget(Tenant $t): void
    {
        Cache::forget(self::factsKey($t));
        Cache::forget('launch:items:queued:' . $t->id);
        self::queueFacts($t);
    }

    private static function counting(): array
    {
        return ['s' => 'warn', 'line' => 'Counting your online items. This updates within a few minutes.', 'stale' => true];
    }

    // ------------------------------------------------------------ e-commerce

    private static function c_stripe(Tenant $t): array
    {
        return self::stripeLine($t);
    }

    private static function c_tax(Tenant $t): array
    {
        $cfg = OrderService::forTenant($t)->config();
        if ($cfg['tax_mode'] === 'stripe') return ['s' => 'ok', 'line' => 'Worked out from the shipping address by Stripe'];
        $rate = (float) ($t->default_tax_rate ?? 0);
        if ($rate > 0) return ['s' => 'ok', 'line' => rtrim(rtrim(number_format($rate, 3), '0'), '.') . '% on every order'];
        return ['s' => 'bad', 'line' => 'No tax rate is saved and Stripe is not working it out.'];
    }

    private static function c_items_online(Tenant $t): array
    {
        $n = OnlineItems::query($t)->count();
        return $n > 0
            ? ['s' => 'ok', 'line' => self::plural($n, 'item') . ' for sale online']
            : ['s' => 'bad', 'line' => 'No items are for sale online yet.'];
    }

    private static function c_item_quality(Tenant $t): array
    {
        $f = self::itemFacts($t);
        if ($f === null) return self::counting();
        if ($f['online'] === 0) return ['s' => 'na', 'line' => 'No items online yet'];
        $miss = [];
        if ($f['no_photo']) $miss[] = self::plural($f['no_photo'], 'item') . ' with no photo';
        if ($f['no_price']) $miss[] = self::plural($f['no_price'], 'item') . ' with no price';
        return $miss
            ? ['s' => 'warn', 'line' => implode(' · ', $miss) . ' of ' . $f['online']]
            : ['s' => 'ok', 'line' => 'All ' . self::plural($f['online'], 'online item') . ' have a photo and a price'];
    }

    private static function c_weights(Tenant $t): array
    {
        $cfg = OrderService::forTenant($t)->config();
        $mode = \App\Services\Shipping\ShippingRates::config($t)['mode'];
        if (! $cfg['ship_enabled'] || $mode === 'flat') return ['s' => 'na', 'line' => 'Not needed: shipping is not priced by weight'];
        $f = self::itemFacts($t);
        if ($f === null) return self::counting();
        if ($f['no_weight'] === 0) return ['s' => 'ok', 'line' => 'Every online item has a weight'];
        $def = rtrim(rtrim(number_format(\App\Services\Shipping\ShippingRates::config($t)['default_oz'] / 16, 2), '0'), '.');
        return ['s' => 'warn', 'line' => self::plural($f['no_weight'], 'item') . ' with no weight, so ' . $def . ' lb is assumed'];
    }

    private static function c_fulfil(Tenant $t): array
    {
        $cfg = OrderService::forTenant($t)->config();
        $on = array_keys(array_filter(['pickup' => $cfg['pickup_enabled'], 'local delivery' => $cfg['local_delivery'], 'shipping' => $cfg['ship_enabled']]));
        return $on
            ? ['s' => 'ok', 'line' => ucfirst(implode(', ', $on)) . (count($on) > 1 ? ' are on' : ' is on')]
            : ['s' => 'bad', 'line' => 'No way to receive an order is turned on.'];
    }

    private static function c_ship_prices(Tenant $t): array
    {
        $cfg = OrderService::forTenant($t)->config();
        if (! $cfg['ship_enabled']) return ['s' => 'na', 'line' => 'Not needed: you do not ship'];
        $rc = \App\Services\Shipping\ShippingRates::config($t);
        $from = (string) ((self::s($t))['ship_from_zip'] ?? '') ?: (string) ($t->defaultLocation?->postal_code ?? '');
        return match ($rc['mode']) {
            'flat' => $cfg['ship_fee_cents'] !== null
                ? ['s' => 'ok', 'line' => 'Flat $' . number_format($cfg['ship_fee_cents'] / 100, 2) . ' per order']
                : ['s' => 'bad', 'line' => 'Shipping is on but no flat price is set.'],
            'weight' => ! $rc['table']
                ? ['s' => 'bad', 'line' => 'Shipping is priced by weight but the table is empty.']
                : ($from === '' ? ['s' => 'bad', 'line' => 'No ship-from ZIP is set.'] : ['s' => 'ok', 'line' => 'Weight table · ' . self::plural(count($rc['table']), 'row') . ' · ships from ' . $from]),
            default => (function () use ($rc, $from) {
                $on = array_keys(array_filter($rc['carriers'], fn ($c) => ! empty($c['enabled'])));
                if (! $on) return ['s' => 'bad', 'line' => 'Live carrier prices are chosen but no carrier is turned on.'];
                if ($from === '') return ['s' => 'bad', 'line' => 'No ship-from ZIP is set.'];
                return ['s' => 'ok', 'line' => 'Live prices from ' . strtoupper(implode(', ', $on)) . ' · ships from ' . $from];
            })(),
        };
    }

    private static function c_warehouses(Tenant $t): array
    {
        $access = \App\Services\Distributors\WarehouseRegistry::access($t);
        if ($access !== 'on') return ['s' => 'hide', 'line' => ''];
        $on = array_values(array_filter(\App\Services\Distributors\WarehouseRegistry::forShop($t), fn ($w) => ! empty($w['enabled'])));
        return $on
            ? ['s' => 'ok', 'line' => implode(', ', array_slice(array_map(fn ($w) => $w['label'] ?? $w['name'] ?? 'Warehouse', $on), 0, 3)) . (count($on) > 3 ? ' and more' : '') . (count($on) === 1 ? ' is on' : ' are on')]
            : ['s' => 'bad', 'line' => 'No supplier warehouse is on.'];
    }

    private static function c_returns_page(Tenant $t): array
    {
        $page = \App\Support\Launch\ShopPolicies::returnsPage($t);
        if ($page) return ['s' => 'ok', 'line' => 'Page "' . $page->title . '" is published'];
        $id = (self::s($t))['returns_page_id'] ?? null;
        return ['s' => 'bad', 'line' => $id ? 'The chosen returns page is not published.' : 'No returns page is chosen yet.'];
    }

    private static function c_order_notify(Tenant $t): array
    {
        $people = \App\Support\Launch\ShopPolicies::orderNotifyPeople($t);
        return $people
            ? ['s' => 'ok', 'line' => implode(', ', array_map(fn ($u) => $u->name ?: $u->email, $people))]
            : ['s' => 'bad', 'line' => 'Nobody is set to be told.'];
    }

    private static function c_feed(Tenant $t): array
    {
        if (! (bool) ((self::s($t))['feed_on'] ?? false)) return ['s' => 'bad', 'line' => 'The Google feed is off.'];
        $f = self::itemFacts($t);
        if ($f === null) return self::counting();
        $ok = $f['online'] - $f['feed_blocked'];
        return $f['feed_blocked']
            ? ['s' => 'warn', 'line' => 'Feed on · ' . $ok . ' of ' . $f['online'] . ' items accepted']
            : ['s' => 'ok', 'line' => 'Feed on · all ' . self::plural($f['online'], 'item') . ' accepted'];
    }

    private static function c_ga4(Tenant $t): array
    {
        $id = \App\Support\ShopGa4::id($t);
        if (! $id) return ['s' => 'bad', 'line' => 'No measurement id is saved.'];
        $on = (bool) (($t->settings ?? [])['analytics_ecom_on'] ?? true);
        return $on ? ['s' => 'ok', 'line' => 'GA4 ' . $id . ' · shop events on'] : ['s' => 'bad', 'line' => 'Shop events are turned off.'];
    }

    private static function c_test_order(Tenant $t): array
    {
        $o = DB::table('tenant_orders')->where('tenant_id', $t->id)
            ->whereIn('status', ['paid', 'fulfilling', 'fulfilled', 'completed'])
            ->orderBy('paid_at')->first(['order_number', 'paid_at']);
        return $o
            ? ['s' => 'ok', 'line' => 'Order ' . $o->order_number . ' was paid']
            : ['s' => 'bad', 'line' => 'No online order has been paid yet.'];
    }

    // ------------------------------------------------------------ rentals

    private static function units(Tenant $t)
    {
        return \App\Models\Tenant\TenantRentalUnit::where('tenant_id', $t->id)->whereNull('archived_at')->where('available_for_rent', true);
    }

    private static function c_fleet(Tenant $t): array
    {
        $ready = self::units($t)->where('online_booking', true)->where('status', 'available')->count();
        if (! $ready) return ['s' => 'bad', 'line' => 'No unit is ready to rent online.'];
        $models = \App\Models\Tenant\TenantRentalModel::where('tenant_id', $t->id)->whereNull('archived_at')->count();
        $cats = DB::table('tenant_rental_categories')->where('tenant_id', $t->id)->count();
        return ['s' => 'ok', 'line' => self::plural($cats, 'category', 'categories') . ' · ' . self::plural($models, 'model') . ' · ' . self::plural($ready, 'unit') . ' ready'];
    }

    private static function c_unit_ids(Tenant $t): array
    {
        $all = self::units($t)->get(['id', 'identifier', 'identifier_values']);
        if ($all->isEmpty()) return ['s' => 'na', 'line' => 'No units yet'];
        $miss = $all->filter(fn ($u) => trim((string) $u->identifier) === '' && ! array_filter((array) ($u->identifier_values ?? [])))->count();
        return $miss ? ['s' => 'warn', 'line' => self::plural($miss, 'unit') . ' with no identifier'] : ['s' => 'ok', 'line' => 'All ' . self::plural($all->count(), 'unit') . ' have one'];
    }

    private static function c_model_rates(Tenant $t): array
    {
        $units = self::units($t)->where('online_booking', true)->with('model')->get();
        if ($units->isEmpty()) return ['s' => 'bad', 'line' => 'No units are bookable online yet.'];
        $missing = $units->filter(fn ($u) => ! $u->effectiveDailyCents())->map(fn ($u) => $u->model?->name ?? $u->name)->unique()->values();
        return $missing->isEmpty()
            ? ['s' => 'ok', 'line' => 'Every bookable model has a daily rate']
            : ['s' => 'bad', 'line' => self::plural($missing->count(), 'model') . ' with no daily rate: ' . $missing->take(3)->implode(', ') . ($missing->count() > 3 ? '…' : '')];
    }

    private static function c_deposits(Tenant $t): array
    {
        $models = \App\Models\Tenant\TenantRentalModel::where('tenant_id', $t->id)->whereNull('archived_at')->get(['id', 'deposit_cents']);
        if ($models->isEmpty()) return ['s' => 'na', 'line' => 'No models yet'];
        $none = $models->where('deposit_cents', '<=', 0)->count();
        return $none ? ['s' => 'warn', 'line' => self::plural($none, 'model') . ($none === 1 ? ' takes' : ' take') . ' no deposit'] : ['s' => 'ok', 'line' => 'Every model takes a deposit'];
    }

    private static function c_rent_pay_choice(Tenant $t): array
    {
        $m = $t->settings['rental_pay_mode'] ?? null;
        if (! $m) return ['s' => 'bad', 'line' => 'Not chosen yet.'];
        return ['s' => 'ok', 'line' => RentalRules::payModeLabel((string) $m)];
    }

    private static function c_rent_payments(Tenant $t): array
    {
        if (RentalRules::payMode($t) === 'pickup') return ['s' => 'na', 'line' => 'Not needed: renters pay at pickup'];
        return self::stripeLine($t);
    }

    private static function c_agreement(Tenant $t): array
    {
        $v = \App\Models\Tenant\TenantRentalAgreementTemplate::where('tenant_id', $t->id)->orderByDesc('version')->first(['version']);
        return $v ? ['s' => 'ok', 'line' => 'Agreement v' . $v->version . ' · signed at pickup'] : ['s' => 'bad', 'line' => 'No agreement is published.'];
    }

    private static function c_late_fees(Tenant $t): array
    {
        $s = (array) ($t->settings ?? []);
        if (! array_key_exists('rental_late_fee_cents_per_hour', $s)) return ['s' => 'bad', 'line' => 'Late and overdue policy not saved yet.'];
        $fee = (int) $s['rental_late_fee_cents_per_hour'];
        return ['s' => 'ok', 'line' => ($fee ? '$' . number_format($fee / 100, 2) . ' an hour' : 'No late fee') . ' after ' . (int) ($s['rental_late_grace_minutes'] ?? 30) . ' minutes'];
    }

    private static function c_condition(Tenant $t): array
    {
        if (! Schema::hasTable('tenant_rental_condition_templates')) return ['s' => 'na', 'line' => ''];
        $n = DB::table('tenant_rental_condition_templates')->where('tenant_id', $t->id)->count();
        return $n ? ['s' => 'ok', 'line' => self::plural($n, 'checklist') . ' set up'] : ['s' => 'warn', 'line' => 'No condition checklist yet'];
    }

    private static function c_rent_hours(Tenant $t): array
    {
        $h = RentalRules::hours($t);
        $open = array_filter($h['days'], fn ($d) => $d['open']);
        if (! $open) return ['s' => 'bad', 'line' => 'No pickup hours set.'];
        return ['s' => 'ok', 'line' => RentalRules::summary($h)];
    }

    private static function c_booking_page(Tenant $t): array
    {
        return $t->rentals_visible ? ['s' => 'ok', 'line' => 'On at /rentals'] : ['s' => 'bad', 'line' => '"Show rentals in my menu" is off.'];
    }

    private static function c_test_booking(Tenant $t): array
    {
        $r = DB::table('tenant_rentals')->where('tenant_id', $t->id)->where('status', 'returned')->orderBy('updated_at')->first(['rental_number', 'updated_at']);
        return $r ? ['s' => 'ok', 'line' => 'Rental ' . $r->rental_number . ' was returned'] : ['s' => 'bad', 'line' => 'No rental has been returned yet.'];
    }
}
