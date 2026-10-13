<?php

namespace App\Support;

use App\Models\Addon;
use App\Models\BillingSettings;
use App\Models\SiteSettings;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-LIVE-PRICING: everything the public pricing page shows, read from
 * master admin each time it renders. Plan prices come from Plan prices
 * (PlanPricing), add-on prices from Add-ons (AddonPricing, dated), and the
 * plan descriptions and "basics" rows (team size, domain, ...) from Site
 * settings, edited on Billing > Pricing page. Nothing on the page is typed
 * into the page itself, so a price change in master admin is the change.
 *
 * Who-gets-what mirrors FeatureAccessService: a plan below an add-on's
 * min_plan_tier never has it, even when the add-on lists that plan as
 * included; otherwise "included" wins; otherwise it is for sale.
 */
class PricingCatalog
{
    public const TIERS = ['starter', 'branded', 'scale'];

    public const NAMES = ['starter' => 'Starter', 'branded' => 'Branded', 'scale' => 'Scale'];

    public const DEFAULT_BLURBS = [
        'starter' => 'Book, work and get paid. For one person running the show.',
        'branded' => 'Your name on everything, a team of three, and a register that knows your stock.',
        'scale'   => 'For busy shops and second locations, with a team of ten.',
    ];

    /** label, then one value per plan: "yes", "no", or the words to show. */
    public const DEFAULT_BASICS = [
        ['Team members', '1', 'Up to 3', 'Up to 10'],
        ['Online booking and work orders', 'yes', 'yes', 'yes'],
        ['Customer records', 'yes', 'yes', 'yes'],
        ['Website', 'On intake.works', 'Your own domain', 'Your own domain'],
        ['Intake branding removed', 'no', 'yes', 'yes'],
        ['Inventory items', 'no', '120', 'No cap'],
        ['Locations', '1', '1', '1, add more at the plan price'],
    ];

    /** Readable names for the add-on category column. */
    public const GROUPS = [
        'retail'        => 'Selling',
        'feature'       => 'Features',
        'operations'    => 'Running the shop',
        'communication' => 'Marketing and messages',
        'team'          => 'Team and locations',
        'onboarding'    => 'Getting started',
    ];

    private static function rank(?string $tier): int
    {
        return match ($tier) {
            'branded' => 1,
            'scale'   => 2,
            'custom'  => 3,
            default   => 0,
        };
    }

    /** @return array<int,array{k:string,name:string,cents:int,blurb:string}> */
    public static function plans(): array
    {
        $blurbs = self::blurbs();
        $out = [];
        foreach (self::TIERS as $t) {
            $out[] = [
                'k'     => $t,
                'name'  => self::NAMES[$t],
                'cents' => PlanPricing::for($t),
                'blurb' => $blurbs[$t] ?? '',
            ];
        }
        return $out;
    }

    public static function blurbs(): array
    {
        $saved = Schema::hasColumn('site_settings', 'pricing_blurbs') ? (array) (SiteSettings::current()->pricing_blurbs ?? []) : [];
        return array_merge(self::DEFAULT_BLURBS, array_filter($saved, fn ($v) => is_string($v) && trim($v) !== ''));
    }

    /**
     * Rows of [label, starter, branded, scale, phone]. phone = shown on the
     * card on phones before "+ more" (MARKER-PRICING-MOBILE-PRIORITY).
     * @return array<int,array{0:string,1:string,2:string,3:string,4:bool}>
     */
    public static function basics(): array
    {
        $saved = Schema::hasColumn('site_settings', 'pricing_basics') ? SiteSettings::current()->pricing_basics : null;
        if (! is_array($saved) || ! count($saved)) {
            $saved = self::DEFAULT_BASICS;
        }
        $rows = [];
        foreach ($saved as $r) {
            if (! is_array($r) || trim((string) ($r[0] ?? '')) === '') {
                continue;
            }
            $rows[] = [(string) $r[0], (string) ($r[1] ?? ''), (string) ($r[2] ?? ''), (string) ($r[3] ?? ''), ! empty($r[4])];
        }
        return $rows;
    }

    /** Add-on codes whose card line shows on phones before "+ more". */
    public static function mobileAddons(): array
    {
        if (! Schema::hasColumn('site_settings', 'pricing_mobile_addons')) {
            return [];
        }
        return array_values(array_filter((array) (SiteSettings::current()->pricing_mobile_addons ?? []), 'is_string'));
    }

    /**
     * One add-on on one plan.
     *  inc   included with the plan
     *  price for sale, s = the price text
     *  ask   for sale, but by conversation
     *  no    not available on this plan
     *  hide  would be for sale but has no price yet (kept off the public page)
     */
    public static function cell(Addon $a, string $tier): array
    {
        if (! empty($a->min_plan_tier) && self::rank($tier) < self::rank($a->min_plan_tier)) {
            return ['t' => 'no', 's' => ''];
        }
        if (in_array($tier, (array) ($a->included_in_plans ?? []), true)) {
            return ['t' => 'inc', 's' => 'Included'];
        }
        if (($a->visibility ?? Addon::VIS_SELF_SERVE) === Addon::VIS_HIDDEN) {
            return ['t' => 'no', 's' => ''];
        }
        $text = self::priceText($a);
        if ($text === null) {
            return ['t' => 'hide', 's' => 'No price set'];
        }
        if (($a->visibility ?? '') === Addon::VIS_ASK) {
            return ['t' => 'ask', 's' => 'Ask us', 'price' => $text];
        }
        return ['t' => 'price', 's' => $text];
    }

    /** "$19/mo", "$99 once", the override text, or null when there is no price. */
    public static function priceText(Addon $a): ?string
    {
        $override = trim((string) ($a->price_display_override ?? ''));
        if ($override !== '' && strcasecmp($override, 'Included') !== 0) {
            return $override;
        }
        $cents = $a->currentPriceCents();
        if ($cents <= 0) {
            return null;
        }
        $dollars = '$' . rtrim(rtrim(number_format($cents / 100, 2), '0'), '.');
        return $a->billing_cadence === 'one_time' ? $dollars . ' once' : $dollars . '/mo';
    }

    /** Add-ons the page can talk about: on sale now, and not purely internal. */
    public static function addons()
    {
        return Addon::query()->where('status', Addon::ACTIVE)->orderBy('sort_order')->orderBy('name')->get()
            ->filter(function (Addon $a) {
                $hidden = ($a->visibility ?? Addon::VIS_SELF_SERVE) === Addon::VIS_HIDDEN;
                return ! $hidden || count((array) ($a->included_in_plans ?? [])) > 0;
            })->values();
    }

    /**
     * Grouped rows for the plan-by-plan table.
     * $public drops cells nobody can buy yet; the preview keeps them.
     * @return array<string,array<int,array{addon:Addon,cells:array}>>
     */
    public static function groups(bool $public = true): array
    {
        $out = [];
        foreach (self::addons() as $a) {
            $cells = [];
            foreach (self::TIERS as $t) {
                $cells[$t] = self::cell($a, $t);
            }
            $types = array_column($cells, 't');
            if ($public && ! array_intersect($types, ['inc', 'price', 'ask'])) {
                continue;
            }
            if ($public) {
                foreach ($cells as $t => $c) {
                    if ($c['t'] === 'hide') {
                        $cells[$t] = ['t' => 'no', 's' => ''];
                    }
                }
            }
            $g = self::GROUPS[$a->category] ?? ucfirst((string) ($a->category ?: 'Other'));
            $out[$g][] = ['addon' => $a, 'cells' => $cells];
        }
        // keep the GROUPS order, unknown categories last
        $order = array_flip(array_values(self::GROUPS));
        uksort($out, fn ($x, $y) => ($order[$x] ?? 99) <=> ($order[$y] ?? 99) ?: strcmp($x, $y));
        return $out;
    }

    /**
     * What plan $i adds over the plan below it, for the card lists.
     * @return array<int,array{text:string,mobile:bool}>
     */
    public static function adds(int $i): array
    {
        $tier = self::TIERS[$i];
        $prev = $i > 0 ? self::TIERS[$i - 1] : null;
        $mobile = self::mobileAddons();
        $out = [];
        foreach (self::basics() as $b) {
            $v = trim((string) ($b[$i + 1] ?? ''));
            if ($v === '' || in_array(strtolower($v), ['no', '-', '—'], true)) {
                continue;
            }
            if ($prev !== null && $v === trim((string) ($b[$i] ?? ''))) {
                continue;
            }
            $out[] = ['text' => strtolower($v) === 'yes' ? $b[0] : $b[0] . ': ' . $v, 'mobile' => (bool) $b[4]];
        }
        foreach (self::addons() as $a) {
            $now = self::cell($a, $tier)['t'] === 'inc';
            $before = $prev !== null && self::cell($a, $prev)['t'] === 'inc';
            if ($now && ! $before) {
                $out[] = ['text' => $a->name, 'mobile' => in_array($a->code, $mobile, true)];
            }
        }
        return $out;
    }

    /**
     * Which lines of a card show on phones: ticked ones first, then the rest
     * in order, five in all. Nothing ticked = the first five.
     * @return array<int,bool> index => shown
     */
    public static function phoneLines(array $lines, int $cap = 5): array
    {
        $ticked   = array_keys(array_filter(array_map(fn ($l) => ! empty($l['mobile']), $lines)));
        $unticked = array_values(array_diff(array_keys($lines), $ticked));
        $pick     = array_slice(array_merge($ticked, $unticked), 0, $cap);
        $out = [];
        foreach (array_keys($lines) as $k) {
            $out[$k] = in_array($k, $pick, true);
        }
        return $out;
    }

    /** Things to settle before the page is right. [severity, title, detail, addon id|null] */
    public static function issues(): array
    {
        $out = [];
        foreach (self::addons() as $a) {
            foreach (self::TIERS as $t) {
                if (self::cell($a, $t)['t'] === 'hide') {
                    $out[] = ['warn', $a->name, 'has no price, so the public page leaves it out.', $a->id];
                    break;
                }
            }
            $inc = (array) ($a->included_in_plans ?? []);
            if (! empty($a->min_plan_tier)) {
                foreach ($inc as $t) {
                    if (self::rank($t) < self::rank($a->min_plan_tier)) {
                        $out[] = ['bad', $a->name, 'is marked included with ' . ucfirst($t) . ' but its lowest plan is ' . ucfirst($a->min_plan_tier) . ', so ' . ucfirst($t) . ' shops do not get it.', $a->id];
                        break;
                    }
                }
            }
        }
        $bs = BillingSettings::current();
        foreach (self::TIERS as $t) {
            if (PlanPricing::for($t) <= 0) {
                $out[] = ['bad', self::NAMES[$t], 'has no price in Plan prices.', null];
            }
            $col = 'stripe_price_' . $t . '_monthly';
            if (Schema::hasColumn($bs->getTable(), $col) && trim((string) ($bs->{$col} ?? '')) === '') {
                $out[] = ['bad', self::NAMES[$t], 'has no Stripe monthly price, so signup cannot bill it.', null];
            }
        }
        return $out;
    }
}
