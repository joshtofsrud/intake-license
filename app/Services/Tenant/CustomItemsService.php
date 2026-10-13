<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantCustomItemResolution;
use App\Models\Tenant\TenantSaleItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * MARKER-CUSTOM-ITEMS: register lines rung up as a custom item, grouped by the
 * name as typed and the price, with what the shop decided about each group.
 * Reads paid, non-refund sales from the last 180 days. Never edits a sale.
 */
class CustomItemsService
{
    public const WINDOW_DAYS = 180;

    public function __construct(private readonly Tenant $tenant) {}

    public static function nameKey(?string $name): string
    {
        return mb_substr(mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $name))), 0, 191);
    }

    /** "price|name" (the form value for one group). */
    public static function groupKey(string $nameKey, int $priceCents): string
    {
        return $priceCents . '|' . $nameKey;
    }

    /** @return array{0:string,1:int}|null [name_key, price_cents] */
    public static function parseKey(?string $key): ?array
    {
        $key = (string) $key;
        $bar = strpos($key, '|');
        if ($bar === false || ! ctype_digit(substr($key, 0, $bar))) {
            return null;
        }
        $name = self::nameKey(substr($key, $bar + 1));
        return $name === '' ? null : [$name, (int) substr($key, 0, $bar)];
    }

    private function tz(): string
    {
        return method_exists($this->tenant, 'timezone') ? $this->tenant->timezone() : 'UTC';
    }

    /** Start of the shop's today, in UTC. */
    public function todayStart(): Carbon
    {
        return Carbon::now($this->tz())->startOfDay()->utc();
    }

    private function lines(): Collection
    {
        return TenantSaleItem::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('type', 'open_item')
            ->whereHas('sale', function ($s) {
                $s->where('tenant_id', $this->tenant->id)
                  ->whereNotNull('paid_at')
                  ->whereNull('refund_of_sale_id')
                  ->where('paid_at', '>=', now()->subDays(self::WINDOW_DAYS));
            })
            ->with(['sale:id,sale_number,paid_at,rang_up_by_user_id', 'sale.rangUpBy'])
            ->get(['id', 'sale_id', 'name_snapshot', 'unit_price_cents', 'quantity', 'created_at']);
    }

    private function resolutions(): Collection
    {
        return TenantCustomItemResolution::with('item')->where('tenant_id', $this->tenant->id)->get();
    }

    /**
     * Every group, newest first.
     * open = lines nobody has decided on yet (rung up after the group's decision, or never decided).
     */
    public function groups(): Collection
    {
        $res = $this->resolutions();
        $ignored = $res->filter(fn ($r) => $r->ignore_always)->pluck('name_key')->flip();
        $byKey = $res->filter(fn ($r) => $r->price_cents !== null)->keyBy(fn ($r) => self::groupKey($r->name_key, (int) $r->price_cents));
        $todayStart = $this->todayStart();

        return $this->lines()
            ->groupBy(fn ($l) => self::groupKey(self::nameKey($l->name_snapshot), (int) $l->unit_price_cents))
            ->map(function (Collection $ls, string $key) use ($byKey, $ignored, $todayStart) {
                $ls = $ls->sortByDesc(fn ($l) => optional($l->sale)->paid_at ?? $l->created_at)->values();
                $last = $ls->first();
                [$nameKey, $price] = self::parseKey($key);
                $r = $byKey->get($key);
                $isIgnored = isset($ignored[$nameKey]);
                $open = $isIgnored ? collect() : $ls->filter(fn ($l) => ! $r || ($r->resolved_at && $l->created_at > $r->resolved_at));
                $paid = fn ($l) => optional($l->sale)->paid_at ?? $l->created_at;
                $staff = optional(optional($last->sale)->rangUpBy);

                return [
                    'key'         => $key,
                    'name'        => trim((string) $last->name_snapshot),
                    'price_cents' => $price,
                    'times'       => $ls->count(),
                    'open'        => $open->count(),
                    'open_today'  => $open->filter(fn ($l) => $paid($l) >= $todayStart)->count(),
                    'last_at'     => $paid($last),
                    'last_staff'  => trim(($staff->first_name ?? '') . ' ' . mb_substr((string) ($staff->last_name ?? ''), 0, 1)),
                    'sales'       => $ls->pluck('sale')->filter()->unique('id')->take(6)->map(fn ($s) => ['id' => $s->id, 'number' => $s->sale_number])->values()->all(),
                    'status'      => $isIgnored ? 'skipped' : ($r->status ?? null),
                    'resolution'  => $r,
                    'ignored'     => $isIgnored,
                    'lines'       => $ls,
                ];
            })
            ->sortByDesc('last_at')
            ->values();
    }

    /** For the dashboard and the tab badge. */
    public function counts(?Collection $groups = null): array
    {
        $groups = $groups ?? $this->groups();
        $today = (int) $groups->sum('open_today');
        $all   = (int) $groups->sum('open');
        return ['today' => $today, 'earlier' => $all - $today, 'open' => $all];
    }

    public function resolve(string $key, string $status, ?string $itemId, ?string $reason, bool $ignoreAlways, ?string $displayName, ?string $userId): void
    {
        [$nameKey, $price] = self::parseKey($key) ?? [null, null];
        if ($nameKey === null) {
            return;
        }
        TenantCustomItemResolution::updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'name_key' => $nameKey, 'price_cents' => $price],
            ['status' => $status, 'inventory_item_id' => $itemId, 'reason' => $reason, 'ignore_always' => false,
             'display_name' => $displayName, 'resolved_by_user_id' => $userId, 'resolved_at' => now()]
        );
        if ($ignoreAlways) {
            TenantCustomItemResolution::updateOrCreate(
                ['tenant_id' => $this->tenant->id, 'name_key' => $nameKey, 'price_cents' => null],
                ['status' => 'skipped', 'reason' => $reason, 'ignore_always' => true,
                 'display_name' => $displayName, 'resolved_by_user_id' => $userId, 'resolved_at' => now()]
            );
        }
    }

    public function undo(string $key): void
    {
        [$nameKey, $price] = self::parseKey($key) ?? [null, null];
        if ($nameKey === null) {
            return;
        }
        TenantCustomItemResolution::where('tenant_id', $this->tenant->id)->where('name_key', $nameKey)
            ->where(fn ($q) => $q->where('price_cents', $price)->orWhereNull('price_cents'))
            ->delete();
    }
}
