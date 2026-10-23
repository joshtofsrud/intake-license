<?php

namespace App\Services\Demo;

use App\Models\Tenant;
use App\Models\Tenant\TenantClassMembershipProduct;
use App\Models\Tenant\TenantClassPackProduct;
use App\Models\Tenant\TenantClassSchedule;
use App\Models\Tenant\TenantClassTemplate;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantResource;
use App\Services\Demo\Industries\IndustryDataContract;
use App\Services\ClassWaivers;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the class side of a demo studio around today, in the studio's own
 * timezone: a weekly timetable on repeating schedules running weeks back and
 * ahead, members, pack holders, intro-offer newcomers and drop-ins with
 * favourite classes, a booking history (check-ins, no-shows, late cancels,
 * waitlists), upcoming bookings, the sales behind it, waivers and settings.
 *
 * Re-runnable: wipe() clears only the class side of the tenant first.
 * MARKER-CLASSES-DEMO
 */
class ClassStudioDemo
{
    /** [template slug, weekdays (0 = Sunday), studio time, how popular 0..1.2] */
    private const TIMETABLE = [
        ['hiit-45',              [1, 3, 5], '06:00', 0.85],
        ['strength-foundations', [2, 4],    '06:00', 0.70],
        ['mat-pilates',          [1, 3, 5], '09:30', 0.60],
        ['conditioning-circuit', [2, 4],    '12:00', 0.45],
        ['power-yoga',           [1, 3],    '17:30', 1.00],
        ['barre-burn',           [2, 4],    '17:30', 1.15],
        ['vinyasa-flow',         [1, 2, 3, 4], '19:00', 0.75],
        ['hiit-45',              [6],       '08:00', 0.90],
        ['vinyasa-flow',         [6],       '09:30', 0.95],
        ['yin-restore',          [0],       '09:00', 0.80],
        ['yin-restore',          [0],       '17:00', 0.55],
    ];

    private const NOTES = [
        'mat-pilates'  => 'Mats and rings provided. Grip socks recommended.',
        'hiit-45'      => 'Bring water and a towel. Shoes on.',
        'barre-burn'   => 'Bare feet or grip socks.',
        'yin-restore'  => 'Bring layers; the room is kept cool for long holds.',
    ];

    private const WAIVER = "I understand that physical exercise carries a risk of injury. I confirm I am fit to take part, that I will tell the instructor about any injury or condition before class, and that I take part at my own risk.";

    private int $saleSeq = 1;
    private array $sales = [];
    private array $saleItems = [];
    private array $regs = [];

    public function __construct(private IndustryDataContract $industry, private ?Closure $logger = null) {}

    private function log(string $m): void
    {
        if ($this->logger) {
            ($this->logger)($m);
        }
    }

    public function seed(Tenant $tenant, int $weeksBack = 12, int $weeksAhead = 4): void
    {
        mt_srand(crc32($tenant->id));
        $tz = $tenant->timezone();
        $this->wipe($tenant);
        $tenant->forceFill(['classes_enabled' => true])->save();

        $resources = TenantResource::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('sort_order')->get()->values();
        $tpls = $this->templates($tenant, $resources);
        [$mProducts, $pProducts, $intro] = $this->products($tenant);
        $this->settings($tenant);

        $today = Carbon::now($tz)->startOfDay();
        $from  = $today->copy()->subWeeks($weeksBack)->startOfWeek();
        $to    = $today->copy()->addWeeks($weeksAhead)->endOfWeek();
        $sessions = $this->timetable($tenant, $tpls, $resources, $tz, $from, $to);
        $people   = $this->people($tenant, $today, $from, $mProducts, $pProducts, $intro);
        $this->bookings($tenant, $sessions, $people, $tz, $today, $pProducts, $intro);
        $this->memberships($tenant, $people, $tz, $today);
        foreach (array_chunk($this->regs, 500) as $chunk) {
            DB::table('tenant_class_registrations')->insert($chunk);
        }
        $this->waivers($tenant, $people, $today);
        $this->flushSales();

        $this->log('  Classes demo: ' . count($sessions) . ' classes, ' . count($people) . ' people, ' . count($this->sales) . ' class sales.');
    }

    /** Clear the class side of the tenant (and only the sales this demo made). */
    public function wipe(Tenant $tenant): void
    {
        $t = $tenant->id;
        foreach (['tenant_class_registrations', 'tenant_class_credit_entries', 'tenant_class_waiver_signatures', 'tenant_class_waivers',
                  'tenant_class_online_checkouts', 'tenant_customer_packs', 'tenant_customer_memberships', 'tenant_class_sessions',
                  'tenant_class_schedules', 'tenant_class_pack_products', 'tenant_class_membership_products'] as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                DB::table($table)->where('tenant_id', $t)->delete();
            }
        }
        // MySQL stores JSON with a space after the colon, so match either spelling.
        $saleIds = DB::table('tenant_sales')->where('tenant_id', $t)
            ->where(fn ($q) => $q->where('metadata', 'like', '%"demo_classes":true%')->orWhere('metadata', 'like', '%"demo_classes": true%'))
            ->pluck('id');
        foreach ($saleIds->chunk(500) as $chunk) {
            DB::table('tenant_sale_items')->whereIn('sale_id', $chunk)->delete();
            DB::table('tenant_sales')->whereIn('id', $chunk)->delete();
        }
    }

    private function templates(Tenant $tenant, $resources): array
    {
        $out = [];
        foreach ($this->industry->classTemplates() as $d) {
            $res = $resources[$d['instructor_index'] ?? 0] ?? $resources->first();
            $tpl = TenantClassTemplate::where('tenant_id', $tenant->id)->where('slug', $d['slug'])->first()
                ?? new TenantClassTemplate(['tenant_id' => $tenant->id, 'slug' => $d['slug']]);
            $tpl->forceFill([
                'tenant_id'              => $tenant->id,
                'name'                   => $d['name'],
                'slug'                   => $d['slug'],
                'description'            => $d['description'] ?? null,
                'duration_minutes'       => $d['duration_minutes'],
                'default_capacity'       => $d['default_capacity'],
                'price_cents'            => $d['price_cents'],
                'instructor_resource_id' => $res?->id,
                'class_notes'            => self::NOTES[$d['slug']] ?? null,
                'is_active'              => true,
            ])->save();
            $out[$d['slug']] = $tpl;
        }
        return $out;
    }

    private function products(Tenant $tenant): array
    {
        $m = [];
        foreach ($this->industry->membershipProducts() as $d) {
            $m[] = TenantClassMembershipProduct::forceCreate([
                'tenant_id' => $tenant->id, 'name' => $d['name'], 'description' => $d['description'] ?? null,
                'type' => $d['type'], 'monthly_limit' => $d['monthly_limit'], 'price_cents' => $d['price_cents'],
                'is_active' => true, 'sell_online' => true,
            ]);
        }
        $p = [];
        foreach ($this->industry->packProducts() as $d) {
            $p[] = TenantClassPackProduct::forceCreate([
                'tenant_id' => $tenant->id, 'name' => $d['name'], 'description' => $d['description'] ?? null,
                'credit_count' => $d['credit_count'], 'expiry_days' => $d['expiry_days'], 'price_cents' => $d['price_cents'],
                'is_active' => true, 'sell_online' => $d['credit_count'] > 1, 'is_intro' => false,
            ]);
        }
        $intro = TenantClassPackProduct::forceCreate([
            'tenant_id' => $tenant->id, 'name' => 'Intro: 3 classes', 'description' => 'For your first visits. Use within 21 days.',
            'credit_count' => 3, 'expiry_days' => 21, 'price_cents' => 3900, 'is_active' => true, 'sell_online' => true, 'is_intro' => true,
        ]);
        return [$m, array_values(array_filter($p, fn ($x) => $x->credit_count > 1)), $intro];
    }

    private function settings(Tenant $tenant): void
    {
        $s = (array) ($tenant->settings ?? []);
        $s['class_rules'] = [
            'book_ahead_days' => 14, 'book_cutoff_minutes' => 0, 'cancel_cutoff_hours' => 12, 'after_cutoff' => 'keep',
            'online_refund' => 'card', 'waitlist_on' => true, 'waitlist_max' => 10, 'waitlist_stop_hours' => 2,
            'publish_weeks' => 4, 'reminder_hours' => 2, 'waiver_on' => true,
        ];
        $s['class_online_dropin'] = 'both';
        $tenant->forceFill(['settings' => $s])->save();
    }

    /** Repeating schedules and their classes, past (completed) and upcoming. */
    private function timetable(Tenant $tenant, array $tpls, $resources, string $tz, Carbon $from, Carbon $to): array
    {
        $rows = [];
        $now = now();
        $cancelledOne = false;
        foreach (self::TIMETABLE as $i => [$slug, $days, $time, $pop]) {
            $tpl = $tpls[$slug] ?? null;
            if (! $tpl) {
                continue;
            }
            $sch = TenantClassSchedule::create([
                'tenant_id' => $tenant->id, 'class_template_id' => $tpl->id, 'days' => $days, 'start_time' => $time,
                'capacity' => null, 'instructor_resource_id' => $tpl->instructor_resource_id, 'starts_on' => $from->toDateString(),
            ]);
            $instructor = $resources->firstWhere('id', $tpl->instructor_resource_id);
            $sub = $resources->first(fn ($r) => $r->id !== $tpl->instructor_resource_id);
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                if (! in_array($d->dayOfWeek, $days, true)) {
                    continue;
                }
                $start = Carbon::parse($d->toDateString() . ' ' . $time, $tz)->utc();
                $past  = $start->lt($now);
                $useSub = $sub && mt_rand(1, 100) <= 4;
                $status = $past ? 'completed' : 'confirmed';
                if ($past && ! $cancelledOne && $start->lt($now->copy()->subWeeks(3)) && $slug === 'conditioning-circuit') {
                    $status = 'cancelled';
                    $cancelledOne = true;
                }
                $id = (string) Str::uuid();
                $rows[] = [
                    'id' => $id, 'tenant_id' => $tenant->id, 'class_template_id' => $tpl->id, 'schedule_id' => $sch->id,
                    'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes($tpl->duration_minutes),
                    'instructor_resource_id' => ($useSub ? $sub : $instructor)?->id,
                    'instructor_snapshot' => ($useSub ? $sub : $instructor)?->name,
                    'capacity_snapshot' => $tpl->default_capacity, 'status' => $status,
                    'created_at' => $from, 'updated_at' => $now,
                    '_row' => $i, '_pop' => $pop, '_dow' => $d->dayOfWeek, '_time' => $time, '_price' => $tpl->price_cents, '_name' => $tpl->name,
                ];
            }
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('tenant_class_sessions')->insert(array_map(fn ($r) => array_filter($r, fn ($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY), $chunk));
        }
        usort($rows, fn ($a, $b) => $a['starts_at'] <=> $b['starts_at']);
        return $rows;
    }

    /** Who comes, how often, to which classes, and since when. */
    private function people(Tenant $tenant, Carbon $today, Carbon $from, array $mProducts, array $pProducts, $intro): array
    {
        $customers = TenantCustomer::where('tenant_id', $tenant->id)->whereNotNull('email')->inRandomOrder(mt_rand())->limit(170)->get()->values();
        $mix = [
            ['member', 0, 30, [2.6, 3.8]],  // unlimited
            ['member', 1, 20, [1.4, 2.0]],  // 8 a month
            ['member', 2, 10, [0.8, 1.1]],  // 4 a month
            ['pack',   null, 26, [0.7, 1.3]],
            ['intro',  null, 16, [1.0, 2.0]],
            ['dropin', null, 18, [0.15, 0.4]],
        ];
        $slots = count(self::TIMETABLE);
        $people = []; $i = 0; $members = 0;
        foreach ($mix as [$kind, $prod, $n, $freq]) {
            for ($k = 0; $k < $n && $i < $customers->count(); $k++, $i++) {
                $fav = [];
                $want = mt_rand(2, 4);
                while (count($fav) < $want) {
                    $fav[mt_rand(0, $slots - 1)] = true;
                }
                $p = [
                    'customer' => $customers[$i], 'kind' => $kind, 'fav' => array_keys($fav),
                    'freq' => mt_rand((int) ($freq[0] * 100), (int) ($freq[1] * 100)) / 100,
                    'since' => $from->copy()->subWeeks(mt_rand(0, 30)), 'until' => null,
                    'product' => $kind === 'member' ? ($mProducts[$prod] ?? $mProducts[0] ?? null) : null,
                    'pack' => null, 'packs' => [], 'visits' => [], 'unsigned' => mt_rand(1, 100) <= 8,
                ];
                if ($kind === 'intro') {
                    $p['since'] = $today->copy()->subDays(mt_rand(3, 75));
                    $p['convert'] = mt_rand(1, 100) <= 45;
                    $p['product'] = $p['convert'] ? $mProducts[1] ?? $mProducts[0] : null;
                }
                if ($kind === 'member') {
                    $members++;
                    if ($members <= 3) {
                        $p['until'] = $today->copy()->subDays(mt_rand(23, 40)); // stopped coming: at risk
                    } elseif ($members <= 5) {
                        $p['slowFrom'] = $today->copy()->subDays(21); // coming far less: at risk
                    } elseif ($k >= $n - 2) {
                        $p['since'] = $today->copy()->subDays(mt_rand(8, 18)); // new members
                    }
                }
                $people[] = $p;
            }
        }
        return $people;
    }

    private function bookings(Tenant $tenant, array $sessions, array &$people, string $tz, Carbon $today, array $pProducts, $intro): void
    {
        $byRow = [];
        foreach ($people as $pi => $p) {
            foreach ($p['fav'] as $r) {
                $byRow[$r][] = $pi;
            }
        }
        $now = now();
        $regs = [];
        foreach ($sessions as $s) {
            if ($s['status'] === 'cancelled') {
                continue;
            }
            $start = $s['starts_at'];
            $past  = $start->lt($now);
            $daysOut = $past ? 0 : $now->diffInDays($start);
            if (! $past && $daysOut > 14) {
                continue;
            }
            // How many want this class: its popularity, a little noise, and
            // for upcoming classes, fewer the further out they are.
            $want = (int) round($s['capacity_snapshot'] * $s['_pop'] * mt_rand(74, 100) / 100 * ($past ? 1 : max(0.3, 1 - max(0, $daysOut - 2) / 13)));
            // The busiest classes fill and run a short waitlist, held or soon.
            if ((! $past && $s['_pop'] >= 0.85 && $daysOut <= 3 && mt_rand(1, 100) <= 70)
                || ($past && $s['_pop'] >= 1.0 && $start->gt($now->copy()->subWeeks(5)) && mt_rand(1, 100) <= 55)) {
                $want = $s['capacity_snapshot'] + mt_rand(2, 6);
            }
            $local = $start->copy()->setTimezone($tz);
            $pool = [];
            foreach ($byRow[$s['_row']] ?? [] as $pi) {
                $p = $people[$pi];
                if ($local->lt($p['since']) || ($p['until'] && $local->gt($p['until']))) {
                    continue;
                }
                if ($p['kind'] === 'intro' && empty($p['convert']) && count($p['visits']) >= 3) {
                    continue; // used the intro offer and didn't join
                }
                $w = $p['freq'] * (! empty($p['slowFrom']) && $local->gt($p['slowFrom']) ? 0.1 : 1);
                $pool[$pi] = $w;
            }
            $picked = [];
            while (count($picked) < $want && $pool) {
                $sum = array_sum($pool);
                $r = mt_rand(1, 10000) / 10000 * $sum;
                foreach ($pool as $pi => $w) {
                    $r -= $w;
                    if ($r <= 0) {
                        $picked[] = $pi;
                        unset($pool[$pi]);
                        break;
                    }
                }
            }
            $cap = $s['capacity_snapshot'];
            $booked = array_slice($picked, 0, $cap);
            $wait = array_slice($picked, $cap, 8);
            foreach ($booked as $pi) {
                $regs[] = $this->registration($tenant, $s, $people[$pi], $pi, $people, $past, $tz, $pProducts, $intro);
            }
            foreach ($wait as $n => $pi) {
                $regs[] = $this->row($tenant, $s, $people[$pi]['customer']->id, 'waitlisted', 'per_class', ['waitlist_position' => $n + 1]);
            }
        }
        $this->regs = $regs; // inserted once the memberships they point at exist
        // Packs, with credits left as the bookings above used them.
        $packs = [];
        foreach ($people as $p) {
            foreach ($p['packs'] as $pk) {
                $packs[] = [
                    'id' => $pk['id'], 'tenant_id' => $tenant->id, 'customer_id' => $p['customer']->id, 'product_id' => $pk['product']->id,
                    'credits_total' => $pk['product']->credit_count, 'credits_remaining' => max(0, $pk['product']->credit_count - $pk['used']),
                    'expires_at' => $pk['bought']->copy()->addDays($pk['product']->expiry_days),
                    'status' => $pk['used'] >= $pk['product']->credit_count ? 'exhausted' : ($pk['bought']->copy()->addDays($pk['product']->expiry_days)->isPast() ? 'expired' : 'active'),
                    'created_at' => $pk['bought'], 'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($packs, 300) as $chunk) {
            DB::table('tenant_customer_packs')->insert($chunk);
        }
    }

    /** One booking, paid the way this person pays, and its status for a class already held. */
    private function registration(Tenant $tenant, array $s, array $p, int $pi, array &$people, bool $past, string $tz, array $pProducts, $intro): array
    {
        $extra = [];
        $local = $s['starts_at']->copy()->setTimezone($tz);
        if ($p['kind'] === 'member' || ($p['kind'] === 'intro' && ! empty($p['convert']) && count($p['visits']) >= 3)) {
            $method = 'membership';
            $extra['membership_id'] = $people[$pi]['membership_id'] ??= (string) Str::uuid();
            if ($p['kind'] === 'intro' && empty($people[$pi]['joined'])) {
                $people[$pi]['joined'] = $local->copy()->startOfDay();
            }
        } elseif ($p['kind'] === 'pack' || $p['kind'] === 'intro') {
            $method = 'pack';
            $cur = $people[$pi]['pack'];
            $cp = $cur !== null ? $people[$pi]['packs'][$cur] : null;
            if (! $cp || $cp['used'] >= $cp['product']->credit_count || $cp['bought']->copy()->addDays($cp['product']->expiry_days)->lt($local)) {
                $product = ($p['kind'] === 'intro' && ! $cp) ? $intro : $pProducts[mt_rand(0, count($pProducts) - 1)];
                $bought = $local->copy()->subDays(mt_rand(0, 3))->setTime(mt_rand(7, 20), mt_rand(0, 59));
                $people[$pi]['packs'][] = ['id' => (string) Str::uuid(), 'product' => $product, 'bought' => $bought, 'used' => 0];
                $people[$pi]['pack'] = $cur = count($people[$pi]['packs']) - 1;
                $this->sale($tenant, $p['customer']->id, $bought, [['class_pass', $product->name, $product->price_cents]], 'card');
            }
            $people[$pi]['packs'][$cur]['used']++;
            $extra['pack_id'] = $people[$pi]['packs'][$cur]['id'];
        } else {
            $method = ['online', 'cash', 'per_class'][mt_rand(0, 2)];
            if ($method !== 'per_class' || $past) {
                $this->sale($tenant, $p['customer']->id, $s['starts_at']->copy()->subHours(mt_rand(1, 30)),
                    [['open_item', 'Drop-in: ' . $s['_name'] . ' · ' . $local->format('M j, g:i A'), $s['_price']]], $method === 'cash' ? 'cash' : 'card');
            }
        }
        $people[$pi]['visits'][] = $local;

        $status = 'registered';
        if ($past) {
            $roll = mt_rand(1, 100);
            $status = $roll <= 89 ? 'checked_in' : ($roll <= 93 ? 'no_show' : 'cancelled');
            if ($status === 'cancelled') {
                $extra['cancelled_at'] = $s['starts_at']->copy()->subHours(mt_rand(2, 30));
                if ($s['starts_at']->diffInHours($extra['cancelled_at']) < 12) {
                    $extra['metadata'] = json_encode(['late_cancel' => true]);
                }
            }
        }
        return $this->row($tenant, $s, $p['customer']->id, $status, $method, $extra);
    }

    private function row(Tenant $tenant, array $s, string $customerId, string $status, string $method, array $extra = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'class_session_id' => $s['id'], 'customer_id' => $customerId,
            'status' => $status, 'payment_method' => $method, 'membership_id' => null, 'pack_id' => null, 'paid_cents' => 0,
            'waitlist_position' => null, 'registered_at' => $s['starts_at']->copy()->subDays(mt_rand(0, 6))->subHours(mt_rand(1, 20)),
            'cancelled_at' => null, 'metadata' => null, 'created_at' => now(), 'updated_at' => now(),
        ], $extra);
    }

    /** Membership rows for everyone who booked with one, and their monthly renewals. */
    private function memberships(Tenant $tenant, array $people, string $tz, Carbon $today): void
    {
        $rows = [];
        $brands = ['visa', 'mastercard', 'amex', 'visa'];
        foreach ($people as $n => $p) {
            if (empty($p['membership_id']) || ! $p['product']) {
                continue;
            }
            $joined = ($p['joined'] ?? $p['since'])->copy();
            $periodStart = $today->copy()->setDay(min(28, $joined->day));
            if ($periodStart->gt($today)) {
                $periodStart->subMonthNoOverflow();
            }
            $periodEnd = $periodStart->copy()->addMonthNoOverflow()->subDay();
            $mode = $n % 11 === 0 ? 'desk' : 'card';
            $status = ($n % 17 === 5) ? 'past_due' : 'active';
            $used = $p['product']->type === 'unlimited' ? 0
                : min((int) $p['product']->monthly_limit, count(array_filter($p['visits'], fn ($v) => $v->gte($periodStart) && $v->lte($periodEnd))));
            $rows[] = [
                'id' => $p['membership_id'], 'tenant_id' => $tenant->id, 'customer_id' => $p['customer']->id, 'product_id' => $p['product']->id,
                'status' => $status, 'billing_mode' => $mode, 'renewal_price_cents' => $p['product']->price_cents,
                'current_period_start' => $periodStart->toDateString(), 'current_period_end' => $periodEnd->toDateString(),
                'classes_used_this_period' => $used,
                'card_brand' => $mode === 'card' ? $brands[$n % 4] : null, 'card_last4' => $mode === 'card' ? str_pad((string) (1000 + $n * 37 % 9000), 4, '0') : null,
                'failed_attempts' => $status === 'past_due' ? 1 : 0,
                'next_retry_at' => $status === 'past_due' ? $today->copy()->addDays(2) : null,
                'last_charge_error' => $status === 'past_due' ? ($mode === 'card' ? 'Your card was declined.' : 'Pays at the desk') : null,
                'created_at' => Carbon::parse($joined->toDateString() . ' 10:00', $tz)->utc(), 'updated_at' => now(),
            ];
            // Monthly renewals inside the window, as paid sales.
            for ($m = $periodStart->copy(); $m->gte($joined) && $m->gte($today->copy()->subWeeks(14)); $m->subMonthNoOverflow()) {
                if ($status === 'past_due' && $m->equalTo($periodStart)) {
                    continue;
                }
                $this->sale($tenant, $p['customer']->id, Carbon::parse($m->toDateString() . ' 03:00', $tz),
                    [['class_pass', $p['product']->name . ' · renewal', $p['product']->price_cents]], $mode === 'card' ? 'card' : 'cash');
            }
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('tenant_customer_memberships')->insert($chunk);
        }
    }

    private function waivers(Tenant $tenant, array $people, Carbon $today): void
    {
        $w = ClassWaivers::saveText($tenant, self::WAIVER);
        if (! $w) {
            return;
        }
        $rows = [];
        foreach ($people as $p) {
            if ($p['unsigned'] || ! $p['visits']) {
                continue;
            }
            $rows[] = [
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'customer_id' => $p['customer']->id, 'waiver_id' => $w->id,
                'version' => $w->version, 'signer_name' => trim($p['customer']->first_name . ' ' . $p['customer']->last_name),
                'method' => ['online', 'online', 'kiosk', 'paper'][mt_rand(0, 3)], 'ip' => null, 'signed_by_user_id' => null,
                'signed_at' => $p['visits'][0]->copy()->subHours(2)->utc(), 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('tenant_class_waiver_signatures')->insert($chunk);
        }
    }

    private function sale(Tenant $tenant, string $customerId, Carbon $at, array $lines, string $method): void
    {
        $id = (string) Str::uuid();
        $at = $at->copy()->utc();
        $total = array_sum(array_column($lines, 2));
        $this->sales[] = [
            'id' => $id, 'tenant_id' => $tenant->id, 'sale_number' => 'C-' . str_pad((string) $this->saleSeq++, 5, '0', STR_PAD_LEFT),
            'sale_date' => $at->copy()->setTimezone($tenant->timezone())->toDateString(), 'status' => 'completed', 'payment_status' => 'paid',
            'customer_id' => $customerId, 'subtotal_cents' => $total, 'total_cents' => $total, 'tax_cents' => 0, 'payment_method' => $method,
            'paid_at' => $at, 'metadata' => json_encode(['demo_classes' => true]), 'created_at' => $at, 'updated_at' => $at,
        ];
        foreach ($lines as $i => [$type, $name, $cents]) {
            $this->saleItems[] = [
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'sale_id' => $id, 'type' => $type, 'name_snapshot' => $name,
                'quantity' => 1, 'unit_price_cents' => $cents, 'line_total_cents' => $cents, 'position' => $i, 'created_at' => $at, 'updated_at' => $at,
            ];
        }
    }

    private function flushSales(): void
    {
        foreach (array_chunk($this->sales, 300) as $chunk) {
            DB::table('tenant_sales')->insert($chunk);
        }
        foreach (array_chunk($this->saleItems, 300) as $chunk) {
            DB::table('tenant_sale_items')->insert($chunk);
        }
    }
}
