<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Addon - catalog entry for everything Intake sells or gates.
 *
 * Not tenant-scoped; this is the master list. Read mostly; written via seeder
 * or rare manual master-admin edits (future).
 */
class Addon extends Model
{
    protected $table = 'addons';

    protected $fillable = [
        'code',
        'name',
        'category',
        'description',
        'tooltip',
        'price_cents',
        'billing_cadence',
        'price_display_override',
        'included_in_plans',
        'min_plan_tier', // MARKER-LIVE-PRICING
        'stripe_product_id',
        'stripe_price_id_monthly',
        'stripe_price_id_annual',
        'stripe_price_id_onetime',
        'sort_order',
        'status',
        'is_self_serve',
        'visibility',
        'is_new',
    ];

    protected $casts = [
        'included_in_plans' => 'array',
        'is_self_serve' => 'boolean',
        'is_new' => 'boolean',
        'price_cents' => 'integer',
        'sort_order' => 'integer',
    ];

    public function getDisplayPriceAttribute(): string
    {
        if ($this->price_display_override) {
            return $this->price_display_override;
        }

        if ($this->billing_cadence === 'one_time') {
            return '$' . number_format($this->price_cents / 100, 0) . ' once';
        }

        $dollars = number_format($this->price_cents / 100, 0);
        return "\${$dollars}/mo";
    }

    public function scopeSelfServe($query)
    {
        return $query->where('is_self_serve', true);
    }

    /**
     * the three states.
     *
     * ACTIVE   offered to everyone, billed as normal.
     * CLOSED   not offered to new shops; shops that already have it keep it and
     *          keep being billed. This is how something is retired without
     *          taking a feature away from someone paying for it.
     * RETIRED  off for everyone; existing activations stop.
     */
    // 'deprecated' is the closed-to-new state,
    // because FeatureAccessService already treats it as one. Inventing a
    // synonym ('closed') meant an add-on matched neither branch of that query
    // and vanished for shops already paying for it.
    public const ACTIVE     = 'active';
    public const DEPRECATED = 'deprecated';
    public const RETIRED    = 'retired';

    /**
     * what a shop sees.
     *
     * SELF_SERVE  visible, and they can switch it on.
     * ASK         visible, but the button starts a conversation instead. For
     *             anything that needs setup, a contract, or a chat about price.
     * HIDDEN      not shown. You enable it for them.
     */
    public const VIS_SELF_SERVE = 'self_serve';
    public const VIS_ASK        = 'ask';
    public const VIS_HIDDEN     = 'hidden';

    public const VISIBILITIES = [
        self::VIS_SELF_SERVE => 'Shops can add it themselves',
        self::VIS_ASK        => 'Shops can see it and ask about it',
        self::VIS_HIDDEN     => 'Shops cannot see it — you enable it',
    ];

    public function isVisibleToShops(): bool
    {
        return ($this->visibility ?? self::VIS_SELF_SERVE) !== self::VIS_HIDDEN;
    }

    public const STATUSES = [
        self::ACTIVE     => 'Active — anyone can add it',
        self::DEPRECATED => 'Closed — no new shops, existing keep it and are still billed',
        self::RETIRED    => 'Retired — off for everyone, existing activations stop',
    ];

    /** today's price, which may be newer than the column. */
    public function currentPriceCents(): int
    {
        return \App\Support\AddonPricing::for($this->code);
    }

    /** Can a shop that does not have this add it? */
    public function isAvailableToNew(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Closed to new shops, but still live and billed for those who have it. */
    public function isClosedToNew(): bool
    {
        return $this->status === self::DEPRECATED;
    }

    /** Does a shop that already has it keep working? */
    public function stillWorksForExisting(): bool
    {
        return $this->status !== self::RETIRED;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    // ── MARKER-ADDON-SAFETY ───────────────────────────────────────────

    /** Fields whose change alters who gets what. Every change to them is logged. */
    public const WATCHED = ['status', 'included_in_plans', 'min_plan_tier', 'visibility', 'price_cents', 'name'];

    protected static function booted(): void
    {
        // Oct 13, 2026: extended reports was set to Retired with no record of
        // who or why, and every shop lost full reports for two weeks. Now each
        // change says who made it and what it was before (audit log, kept a year).
        static::updated(function (Addon $a) {
            $changes = [];
            foreach (self::WATCHED as $f) {
                if (! $a->wasChanged($f)) continue;
                $was = $a->getOriginal($f);
                $now = $a->getAttribute($f);
                if (is_array($was) || is_array($now)) {
                    $was = is_array($was) ? $was : (json_decode((string) $was, true) ?: []);
                    $now = is_array($now) ? $now : [];
                    sort($was); sort($now);
                    if ($was === $now) continue;
                }
                $changes[$f] = ['from' => $was, 'to' => $now];
            }
            if (! $changes) return;
            $text = collect($changes)->map(fn ($c, $f) => $f . ' ' . self::show($c['from']) . ' → ' . self::show($c['to']))->implode('; ');
            $who = auth('web')->user()?->name ?: (app()->runningInConsole() ? 'a console command' : 'someone');
            try {
                debug_log()->audit('addon.changed', $who . ' changed ' . $a->name . ': ' . $text, $a, $changes);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('addon change not audited', ['addon' => $a->code, 'error' => $e->getMessage()]);
            }
        });
    }

    private static function show($v): string
    {
        if (is_array($v)) return $v ? '[' . implode(', ', $v) . ']' : 'none';
        return $v === null || $v === '' ? 'none' : (string) $v;
    }

    /**
     * How many shops have this add-on working right now: granted to them, or
     * included in their plan, less any switched off for that shop. What a
     * status change would take away.
     */
    public function shopsWithItCount(): int
    {
        $plans = array_values((array) ($this->included_in_plans ?? []));
        $suppressed = \Illuminate\Support\Facades\DB::table('tenant_addon_suppressions')
            ->where('addon_code', $this->code)->whereNull('lifted_at')->pluck('tenant_id')->all();

        $ids = \Illuminate\Support\Facades\DB::table('tenant_feature_addons')
            ->where('addon_code', $this->code)->whereIn('status', ['active', 'canceling', 'failed_payment'])
            ->pluck('tenant_id')->all();
        if ($plans) {
            $q = \App\Models\Tenant::query()->whereIn('plan_tier', $plans);
            if ($this->min_plan_tier) $q->whereIn('plan_tier', $this->tiersFrom($this->min_plan_tier));
            $ids = array_merge($ids, $q->pluck('id')->all());
        }

        return count(array_diff(array_unique($ids), $suppressed));
    }

    private function tiersFrom(string $min): array
    {
        $order = ['starter', 'branded', 'scale', 'custom'];
        $at = array_search($min, $order, true);

        return $at === false ? $order : array_slice($order, $at);
    }
}
