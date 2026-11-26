<?php

namespace App\Services\Pos;

use App\Models\Tenant;
use App\Models\Tenant\TenantPosConnection;
use App\Services\Pos\Adapters\PosAdapterRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-POS-SALES-HISTORY: brings a shop's past sales in from its Connected POS, and
 * (in 2 Way Data, when the shop asks) keeps new ones coming.
 *
 * What it does:
 *  - reads sales in steps, keeps its place, and can be stopped and resumed
 *  - stores finished sales and returns in tenant_pos_sales / tenant_pos_sale_lines.
 *    Nothing else is written: no stock, no cash drawer, no tax, no payment ledger,
 *    no Intake sale. Nothing is ever written to the Connected POS.
 *
 * What it leaves out:
 *  - sales Intake itself sent there (online orders and their refunds). Intake already
 *    has those as real sales; reading them back would count them twice.
 *  - sales that aren't finished, and ones that were voided or deleted (a sale voided
 *    after it was read is removed here too)
 *  - sales older than "how far back", and sales with no customer when the shop chose
 *    to skip those
 */
class PosSalesSync
{
    private const PAGES_PER_STEP = 5;

    public const RANGES = ['12m' => 'Last 12 months', '24m' => 'Last 2 years', 'all' => 'Everything'];

    public function __construct(private PosAdapterRegistry $registry, private PosConnectionService $conns) {}

    /** The shop's choices, with the defaults filled in. */
    public static function options(TenantPosConnection $conn): array
    {
        $o = (array) ($conn->sales_options ?? []);

        return [
            'range'       => isset(self::RANGES[$o['range'] ?? '']) ? $o['range'] : '24m',
            'no_customer' => ($o['no_customer'] ?? 'in') === 'skip' ? 'skip' : 'in',
        ];
    }

    /** The first day "how far back" reaches, or null for everything. */
    public static function since(string $range): ?Carbon
    {
        return match ($range) {
            '12m'   => now()->subMonths(12)->startOfDay(),
            '24m'   => now()->subMonths(24)->startOfDay(),
            default => null,
        };
    }

    private const COUNTERS = ['pages', 'seen', 'kept', 'lines', 'on_customers', 'linked_lines', 'ours', 'older', 'no_customer', 'voided', 'open'];

    /**
     * Begin a run. A full run reads from the start with fresh numbers. Otherwise it picks up
     * what changed since the last one and adds to the numbers already there, so the tab keeps
     * showing the whole history rather than the last minute's handful.
     */
    public function start(TenantPosConnection $conn, bool $full): void
    {
        $opt = self::options($conn);
        $st = (array) ($conn->sales_state ?? []);
        $st = array_merge($st, [
            'running' => true, 'stopped' => false, 'full' => $full, 'error' => null,
            'started_at' => now()->toIso8601String(), 'finished_at' => null,
        ]);
        if ($full) {
            $st = array_merge($st, array_fill_keys(self::COUNTERS, 0), ['latest' => null, 'first' => null, 'at' => null, 'cursor' => null]);
            $st['range'] = $opt['range'];
            $st['since'] = self::since($opt['range'])?->toIso8601String();
        }
        $st += array_fill_keys(self::COUNTERS, 0) + ['latest' => null, 'first' => null, 'at' => null, 'cursor' => null];
        $st['skip_no_customer'] = $opt['no_customer'] === 'skip';
        $conn->forceFill(['sales_state' => $st])->save();
    }

    /** One step. True when there is more to do. */
    public function step(TenantPosConnection $conn): bool
    {
        $st = (array) $conn->sales_state;
        $adapter = $this->registry->make($conn->adapter_code);
        $ctx = (array) ($conn->context ?? []);
        // MARKER-SALES-SINCE: "how far back", for adapters that can ask for it up front
        // instead of reading every older sale only to leave it out
        $ctx['sales_since'] = $st['since'] ?? null;
        $tz = Tenant::find($conn->tenant_id)?->timezone() ?? 'UTC';

        for ($i = 0; $i < self::PAGES_PER_STEP; $i++) {
            $page = $adapter->pullSales($this->conns->accessToken($conn), (string) $conn->external_account_id, $ctx, $st['cursor'] ?? null);
            $this->applyPage($conn, $page['sales'], $st, $tz);
            $st['seen'] += count($page['sales']);
            $st['cursor'] = $page['cursor'];
            $st['pages']++;

            // a Stop pressed while this page was being read is kept, with the place
            $now = (array) json_decode((string) DB::table('tenant_pos_connections')->where('id', $conn->id)->value('sales_state'), true);
            if (! empty($now['stopped'])) {
                $st['running'] = false;
                $st['stopped'] = true;
                $st['finished_at'] = now()->toIso8601String();
                $conn->forceFill(['sales_state' => $st])->save();

                return false;
            }
            if (! $page['more']) {
                $st['running'] = false;
                $st['finished_at'] = now()->toIso8601String();
                $st['last_done'] = $st['finished_at'];
                $conn->forceFill(['sales_state' => $st])->save();

                return false;
            }
            $conn->forceFill(['sales_state' => $st])->save();
        }

        return true;
    }

    /**
     * At Switch over: one last look for sales made in the Connected POS since the last
     * check, for a shop that keeps them coming. After it, new sales no longer come in.
     */
    public function lastLook(TenantPosConnection $conn): void
    {
        $st = (array) ($conn->sales_state ?? []);
        if (! $conn->sales_in || $conn->status !== 'connected' || empty($st['last_done']) || ! empty($st['running']) || ! empty($st['stopped'])) {
            return;
        }
        $this->start($conn, false);
        \App\Jobs\PosSalesSyncJob::dispatch($conn->id);
    }

    /**
     * How far a run has got, 0-100, by the dates of the sales read: the Connected POS hands
     * sales over oldest first and doesn't say how many there are.
     */
    public static function percent(array $st): int
    {
        if (empty($st['at'])) {
            return 0;
        }
        $from = Carbon::parse($st['since'] ?? $st['first'] ?? $st['at'])->timestamp;
        $to = Carbon::parse($st['started_at'] ?? 'now')->timestamp;
        $at = Carbon::parse($st['at'])->timestamp;

        return $to > $from ? max(0, min(100, (int) round(100 * ($at - $from) / ($to - $from)))) : 100;
    }

    /** "March 2025": the month the read has reached, in the shop's own time. */
    public static function reached(array $st, string $tz): ?string
    {
        return empty($st['at']) ? null : Carbon::parse($st['at'])->setTimezone($tz)->format('F Y');
    }

    public function fail(TenantPosConnection $conn, string $message): void
    {
        $st = (array) ($conn->sales_state ?? []);
        $st['running'] = false;
        $st['error'] = Str::limit($message, 400);
        $st['finished_at'] = now()->toIso8601String();
        $conn->forceFill(['sales_state' => $st])->save();
    }

    /** Remove every sale brought in. The Connected POS is not touched, and they can be read again. */
    public function clear(TenantPosConnection $conn): int
    {
        $n = DB::table('tenant_pos_sales')->where('tenant_id', $conn->tenant_id)->count();
        DB::table('tenant_pos_sale_lines')->where('tenant_id', $conn->tenant_id)->delete();
        DB::table('tenant_pos_sales')->where('tenant_id', $conn->tenant_id)->delete();
        $conn->forceFill(['sales_state' => null, 'sales_in' => false])->save();

        return $n;
    }

    /** @param list<array> $sales */
    private function applyPage(TenantPosConnection $conn, array $sales, array &$st, string $tz): void
    {
        if (! $sales) {
            return;
        }
        $tid = $conn->tenant_id;
        $ids = array_column($sales, 'id');

        // what Intake itself sent there: already real sales here
        $ours = array_flip(DB::table('tenant_pos_pushes')->where('tenant_id', $tid)->whereIn('kind', ['sale', 'refund'])
            ->whereIn('external_id', $ids)->pluck('external_id')->map(fn ($v) => (string) $v)->all());
        $known = DB::table('tenant_pos_sales')->where('tenant_id', $tid)->whereIn('external_id', $ids)->pluck('id', 'external_id');
        $custIds = array_values(array_unique(array_filter(array_column($sales, 'customer_id'))));
        $customers = $custIds ? array_flip(DB::table('tenant_pos_customers')->where('tenant_id', $tid)->whereNotNull('customer_id')
            ->whereIn('external_id', $custIds)->pluck('external_id')->map(fn ($v) => (string) $v)->all()) : [];
        $prodIds = [];
        foreach ($sales as $s) {
            foreach ($s['lines'] as $l) {
                if ($l['product_id']) {
                    $prodIds[$l['product_id']] = true;
                }
            }
        }
        $products = $prodIds ? DB::table('tenant_pos_items')->where('tenant_id', $tid)->whereIn('external_id', array_map('strval', array_keys($prodIds)))
            ->get(['external_id', 'name', 'sku', 'inventory_item_id'])->keyBy('external_id') : collect();
        $since = ! empty($st['since']) ? Carbon::parse($st['since']) : null;

        foreach ($sales as $s) {
            $had = $known[$s['id']] ?? null;
            $drop = function (string $why) use (&$st, $had) {
                $st[$why] = ($st[$why] ?? 0) + 1;
                if ($had) {
                    DB::table('tenant_pos_sale_lines')->where('pos_sale_id', $had)->delete();
                    DB::table('tenant_pos_sales')->where('id', $had)->delete();
                }
            };

            if (isset($ours[$s['id']])) {
                $drop('ours');
                continue;
            }
            if ($s['state'] === 'void') {
                $drop('voided');
                continue;
            }
            if ($s['state'] !== 'done') {
                $st['open'] = ($st['open'] ?? 0) + 1; // may finish later; it comes back then
                continue;
            }
            try {
                $at = filled($s['sold_at']) ? Carbon::parse($s['sold_at'], 'UTC')->utc() : null;
            } catch (\Throwable $e) {
                $at = null;
            }
            if (! $at) {
                $st['open'] = ($st['open'] ?? 0) + 1; // no date to file it under
                continue;
            }
            // where the read has got to, by the dates of the sales themselves (for the progress line)
            $iso = $at->toIso8601String();
            if (empty($st['first']) || $iso < $st['first']) {
                $st['first'] = $iso;
            }
            if (empty($st['at']) || $iso > $st['at']) {
                $st['at'] = $iso;
            }
            if ($since && $at->lt($since)) {
                $drop('older');
                continue;
            }
            if (! $s['customer_id'] || ! isset($customers[$s['customer_id']])) {
                // no customer, or one that isn't in Intake (yet)
                if (! $s['customer_id'] && ! empty($st['skip_no_customer'])) {
                    $drop('no_customer');
                    continue;
                }
            } else {
                $st['on_customers']++;
            }

            DB::transaction(function () use ($tid, $s, $at, $tz, $had, $products, &$st) {
                $row = [
                    'number'               => $s['number'] ? Str::limit($s['number'], 60, '') : null,
                    'sold_at'              => $at->toDateTimeString(),
                    'sale_date'            => $at->copy()->setTimezone($tz)->toDateString(),
                    'external_outlet_id'   => $s['outlet_id'],
                    'external_customer_id' => $s['customer_id'],
                    'return_for_external_id' => $s['return_for'] ?? null, // MARKER-POS-RETURN-FOR
                    'subtotal_cents'       => (int) $s['subtotal_cents'],
                    'tax_cents'            => (int) $s['tax_cents'],
                    'total_cents'          => (int) $s['subtotal_cents'] + (int) $s['tax_cents'],
                    'updated_at'           => now(),
                ];
                if ($had) {
                    DB::table('tenant_pos_sales')->where('id', $had)->update($row);
                    DB::table('tenant_pos_sale_lines')->where('pos_sale_id', $had)->delete();
                    $saleId = $had;
                } else {
                    $saleId = DB::table('tenant_pos_sales')->insertGetId($row + ['tenant_id' => $tid, 'external_id' => $s['id'], 'created_at' => now()]);
                }
                $lines = [];
                foreach ($s['lines'] as $l) {
                    $p = $l['product_id'] ? ($products[$l['product_id']] ?? null) : null;
                    $lines[] = [
                        'tenant_id'           => $tid,
                        'pos_sale_id'         => $saleId,
                        'external_product_id' => $l['product_id'],
                        'name'                => Str::limit((string) ($l['name'] ?: ($p->name ?? '')), 250, '') ?: null,
                        'sku'                 => Str::limit((string) ($l['sku'] ?: ($p->sku ?? '')), 100, '') ?: null,
                        'quantity'            => round((float) $l['quantity'], 3),
                        'unit_price_cents'    => (int) $l['unit_price_cents'],
                        'total_cents'         => (int) $l['total_cents'],
                        'discount_cents'      => (int) $l['discount_cents'],
                        'tax_cents'           => (int) $l['tax_cents'],
                        'unit_cost_cents'     => $l['unit_cost_cents'] !== null ? (int) $l['unit_cost_cents'] : null,
                    ];
                    if ($p && $p->inventory_item_id) {
                        $st['linked_lines']++;
                    }
                }
                if ($lines) {
                    DB::table('tenant_pos_sale_lines')->insert($lines);
                }
                $st['lines'] += count($lines);
            });
            $st['kept']++;
            if (! $st['latest'] || $at->toIso8601String() > $st['latest']) {
                $st['latest'] = $at->toIso8601String();
            }
        }
    }

    // ── what the screens read ───────────────────────────────────

    /** Totals for the Sales history tab. */
    public static function summary(string $tenantId): array
    {
        $q = DB::table('tenant_pos_sales')->where('tenant_id', $tenantId);
        $n = (clone $q)->count();

        return [
            'sales'        => $n,
            'first'        => $n ? (clone $q)->min('sale_date') : null,
            'last'         => $n ? (clone $q)->max('sale_date') : null,
            'on_customers' => $n ? (clone $q)->whereExists(fn ($e) => $e->selectRaw('1')->from('tenant_pos_customers as c')
                ->whereColumn('c.tenant_id', 'tenant_pos_sales.tenant_id')->whereColumn('c.external_id', 'tenant_pos_sales.external_customer_id')
                ->whereNotNull('c.customer_id'))->count() : 0,
        ];
    }

    /**
     * MARKER-POS-RETURN-FOR: web orders that were refunded in the Connected POS itself.
     * Intake sent the order there as a sale; a return read back that names that sale is a
     * refund the shop gave at its Connected POS. Nothing is written to the order: this is
     * looked up when a page shows it. (Refunds Intake sent there are never in the history.)
     *
     * @param  list<string>  $orderIds
     * @return array<string, array{cents: int, count: int, last: string, numbers: list<string>}> by order id
     */
    public static function refundedThere(string $tenantId, array $orderIds): array
    {
        if (! $orderIds) {
            return [];
        }
        $rows = DB::table('tenant_pos_pushes as p')
            ->join('tenant_pos_sales as s', function ($j) {
                $j->on('s.tenant_id', '=', 'p.tenant_id')->on('s.return_for_external_id', '=', 'p.external_id');
            })
            ->where('p.tenant_id', $tenantId)->where('p.kind', 'sale')->whereIn('p.ref_id', $orderIds)
            ->orderBy('s.sold_at')->get(['p.ref_id', 's.total_cents', 's.sale_date', 's.number']);
        $out = [];
        foreach ($rows as $r) {
            $o = $out[$r->ref_id] ?? ['cents' => 0, 'count' => 0, 'last' => null, 'numbers' => []];
            $o['cents'] += abs((int) $r->total_cents);
            $o['count']++;
            $o['last'] = substr((string) $r->sale_date, 0, 10);
            if ($r->number) {
                $o['numbers'][] = (string) $r->number;
            }
            $out[$r->ref_id] = $o;
        }

        return $out;
    }

    /**
     * Does this shop have any Connected POS sales history? One indexed lookup, asked fresh
     * each time: a remembered answer would go stale in a long-running worker.
     */
    public static function has(string $tenantId): bool
    {
        return DB::table('tenant_pos_sales')->where('tenant_id', $tenantId)->exists();
    }
}
