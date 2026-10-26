<?php

namespace App\Support\Dashboard;

use App\Models\DashboardBlock;
use App\Models\Tenant;
use App\Support\AppointmentStatus;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-DASH-BUILDER: blocks made in master admin (Dashboards › Blocks).
 *
 * Three kinds:
 *   data    reads one source at the shop it's on, keeps only what matches its
 *           rules, and shows a count, a total, an average or the records
 *   action  a button to a page the app already has (New appointment, …)
 *   note    a heading and a few lines of text from master admin
 *
 * Stored on dashboard_blocks (kind, name, config). Every query is scoped to
 * one tenant_id; there is no way to read another shop. Results are cached for
 * five minutes per shop and block; editing a block changes its config, which
 * changes the cache key, so an edit shows on the next load.
 *
 * Config:
 *   data:   source, rules [[field, op, value]], measure count|sum|avg|list,
 *           field, period, compare, display number|trend|bars|progress|rows|table,
 *           goal (progress only), line
 *   action: action, line
 *   note:   text, until (Y-m-d or null)
 *
 * A source is one table: how it's scoped (base), which date the period applies
 * to (date: null for a "right now" snapshot), its fields, and how a record is
 * drawn (rows). 'area' is the Roles & access area its data comes from; that
 * area's add-on gate (Retail, Rentals, Classes…) also decides which shops can
 * have a block from it.
 */
class CustomBlocks
{
    public const KEY_PREFIX = 'x';

    public const PERIODS = [
        'now' => 'Right now', 'today' => 'Today', 'next_7' => 'Next 7 days', 'this_week' => 'This week', 'last_7' => 'Last 7 days',
        'last_30' => 'Last 30 days', 'this_month' => 'This month', 'all_time' => 'All time',
    ];

    private const DATED = ['today', 'this_week', 'last_7', 'last_30', 'this_month', 'all_time'];
    private const AHEAD = ['today', 'next_7', 'this_week', 'last_7', 'last_30', 'this_month', 'all_time'];

    public const MEASURES = ['count' => 'How many', 'sum' => 'Total of', 'avg' => 'Average of', 'list' => 'The records'];

    public const DISPLAYS = ['number' => 'Number', 'trend' => 'Number + trend', 'bars' => 'Bars by day', 'progress' => 'Progress to a goal', 'rows' => 'Rows', 'table' => 'Table'];

    /** Buttons an action block can show: pages that already exist. */
    public const ACTIONS = [
        'new_appointment' => ['label' => 'New appointment', 'route' => 'tenant.appointments.index', 'params' => ['new' => 1]],
        'register'        => ['label' => 'Open register', 'route' => 'tenant.register.index', 'params' => []],
        'walk_in'         => ['label' => 'Start a walk-in', 'route' => 'tenant.register.walk-in', 'params' => []],
        'special_orders'  => ['label' => 'Special orders', 'route' => 'tenant.special-orders.index', 'params' => []],
        'customers'       => ['label' => 'Find a customer', 'route' => 'tenant.customers.index', 'params' => []],
        'class_checkin'   => ['label' => 'Class check-in', 'route' => 'tenant.classes.checkin', 'params' => []],
    ];

    private static ?array $defs = null;
    private static ?array $sources = null;

    // ---- the sources -----------------------------------------------------------

    /** A rule on a plain column: is / is not a value (a NULL counts as "not"). */
    private static function col(string $col): \Closure
    {
        return fn (Builder $q, $v, bool $not) => $not
            ? $q->where(fn ($w) => $w->where($col, '!=', $v)->orWhereNull($col))
            : $q->where($col, $v);
    }

    /** A yes/no rule: $yes narrows to "yes"; "no" is everything else. */
    private static function flag(\Closure $yes): \Closure
    {
        return function (Builder $q, $v, bool $not) use ($yes) {
            if (($v === 'yes') !== $not) {
                $yes($q);
            } else {
                $q->whereNot(fn ($w) => $yes($w));
            }
        };
    }

    private static function name(?string $first, ?string $last, string $fallback = 'No name'): string
    {
        return trim(($first ?? '') . ' ' . ($last ?? '')) ?: $fallback;
    }

    public static function sources(): array
    {
        if (self::$sources !== null) return self::$sources;
        $yesNo = fn (string $yes, string $no) => ['yes' => $yes, 'no' => $no];

        return self::$sources = [
            'appointments' => [
                'label' => 'Appointments', 'desc' => 'Bookings and jobs, by their appointment date', 'area' => 'schedule',
                'opens' => 'tenant.appointments.index', 'opens_label' => 'Appointments', 'unit' => ['booking', 'bookings'],
                'base' => fn (Builder $q) => $q,
                'date' => ['col' => 'tenant_appointments.appointment_date', 'utc' => false], 'periods' => self::AHEAD,
                'fields' => [
                    'status'  => ['label' => 'Status', 'type' => 'enum',
                        'options' => ['awaiting' => 'Waiting to confirm', 'scheduled' => 'Confirmed', 'active' => 'In progress', 'done' => 'Done', 'cancelled' => 'Cancelled'],
                        'apply' => fn (Builder $q, $v, bool $not) => $q->{$not ? 'whereNotIn' : 'whereIn'}('tenant_appointments.status', AppointmentStatus::statusesForRole($v))],
                    'payment' => ['label' => 'Payment', 'type' => 'enum', 'options' => ['paid' => 'Paid', 'unpaid' => 'Unpaid', 'partial' => 'Part paid', 'refunded' => 'Refunded'],
                        'apply' => self::col('tenant_appointments.payment_status')],
                    'amount'  => ['label' => 'Amount', 'type' => 'money', 'sql' => 'tenant_appointments.total_cents'],
                    'owed'    => ['label' => 'Still owed', 'type' => 'money', 'sql' => '(tenant_appointments.total_cents - tenant_appointments.paid_cents)'],
                ],
                'cols' => ['Customer', 'When', 'Amount'],
                'rows' => fn (Builder $q, $money, string $tz) => $q->orderBy('appointment_date')->orderBy('appointment_time')
                    ->get(['id', 'customer_first_name', 'customer_last_name', 'appointment_date', 'appointment_time', 'status', 'total_cents'])
                    ->map(fn ($r) => [self::name($r->customer_first_name, $r->customer_last_name),
                        Carbon::parse($r->appointment_date)->format('D M j') . ($r->appointment_time ? ' · ' . Carbon::parse($r->appointment_time)->format('g:i A') : '')
                            . ' · ' . (AppointmentStatus::LABELS[$r->status] ?? Str::headline((string) $r->status)),
                        $money($r->total_cents), 'tenant.appointments.show', $r->id])->all(),
            ],
            'sales' => [
                'label' => 'Sales', 'desc' => 'Completed register and online sales, by when they were paid (Reports counts payments received, so the two can differ a little)', 'area' => 'reports',
                'opens' => 'tenant.reports.index', 'opens_label' => 'Reports', 'unit' => ['sale', 'sales'],
                'base' => fn (Builder $q) => $q->where('tenant_sales.status', 'completed')->whereNull('tenant_sales.refund_of_sale_id')->whereNotNull('tenant_sales.paid_at'),
                'date' => ['col' => 'tenant_sales.paid_at', 'utc' => true], 'periods' => self::DATED,
                'fields' => [
                    'amount'   => ['label' => 'Amount', 'type' => 'money', 'sql' => 'tenant_sales.total_cents'],
                    'discount' => ['label' => 'Discount given', 'type' => 'money', 'sql' => '(tenant_sales.discount_cents + tenant_sales.sale_discount_cents)'],
                    'tip'      => ['label' => 'Tip', 'type' => 'money', 'sql' => 'tenant_sales.tip_cents'],
                    'customer' => ['label' => 'Customer', 'type' => 'enum', 'options' => $yesNo('Has a customer', 'No customer (walk-in)'),
                        'apply' => self::flag(fn ($q) => $q->whereNotNull('tenant_sales.customer_id'))],
                ],
                'cols' => ['Customer', 'Sale', 'Amount'],
                'rows' => fn (Builder $q, $money, string $tz) => $q->leftJoin('tenant_customers', 'tenant_customers.id', '=', 'tenant_sales.customer_id')
                    ->orderByDesc('tenant_sales.paid_at')
                    ->get(['tenant_sales.id', 'tenant_sales.sale_number', 'tenant_sales.paid_at', 'tenant_sales.total_cents', 'tenant_customers.first_name', 'tenant_customers.last_name'])
                    ->map(fn ($r) => [self::name($r->first_name, $r->last_name, 'Walk-in'),
                        '#' . $r->sale_number . ' · ' . Carbon::parse($r->paid_at, 'UTC')->setTimezone($tz)->format('M j, g:i A'),
                        $money($r->total_cents), 'tenant.register.sales.receipt', $r->id])->all(),
            ],
            'customers' => [
                'label' => 'Customers', 'desc' => 'People and businesses, by when they were added', 'area' => 'customers',
                'opens' => 'tenant.customers.index', 'opens_label' => 'Customers', 'unit' => ['customer', 'customers'],
                'base' => fn (Builder $q) => $q->whereNull('tenant_customers.erased_at'),
                'date' => ['col' => 'tenant_customers.created_at', 'utc' => true], 'periods' => self::DATED,
                'fields' => [
                    'vip'   => ['label' => 'VIP', 'type' => 'enum', 'options' => $yesNo('VIP', 'Not VIP'), 'apply' => self::flag(fn ($q) => $q->where('tenant_customers.is_vip', 1))],
                    'type'  => ['label' => 'Kind', 'type' => 'enum', 'options' => ['individual' => 'Person', 'business' => 'Business'], 'apply' => self::col('tenant_customers.customer_type')],
                    'email' => ['label' => 'Email', 'type' => 'enum', 'options' => $yesNo('Has an email', 'No email'),
                        'apply' => self::flag(fn ($q) => $q->whereNotNull('tenant_customers.email')->where('tenant_customers.email', '!=', ''))],
                ],
                'cols' => ['Customer', 'Contact', 'Added'],
                'rows' => fn (Builder $q, $money, string $tz) => $q->orderByDesc('tenant_customers.created_at')
                    ->get(['id', 'first_name', 'last_name', 'business_name', 'email', 'phone', 'created_at'])
                    ->map(fn ($r) => [self::name($r->first_name, $r->last_name, $r->business_name ?: 'No name'),
                        $r->email ?: ($r->phone ?: 'No contact details'),
                        'Added ' . Carbon::parse($r->created_at, 'UTC')->setTimezone($tz)->format('M j'), 'tenant.customers.show', $r->id])->all(),
            ],
            'inventory' => [
                'label' => 'Inventory items', 'desc' => 'Active items and their stock, as they are right now', 'area' => 'inventory',
                'opens' => 'tenant.inventory.index', 'opens_label' => 'Inventory', 'unit' => ['item', 'items'],
                'base' => fn (Builder $q) => $q->where('tenant_inventory_items.is_active', 1)->whereNull('tenant_inventory_items.deleted_at')->whereNull('tenant_inventory_items.merged_into_id'),
                'date' => null, 'periods' => ['now'],
                'fields' => [
                    'low'     => ['label' => 'Stock', 'type' => 'enum', 'options' => $yesNo('At or below its reorder point', 'Above its reorder point'),
                        'apply' => self::flag(fn ($q) => $q->where('tenant_inventory_items.is_stock_tracked', 1)->whereNotNull('tenant_inventory_items.shop_reorder_threshold')
                            ->whereColumn('tenant_inventory_items.computed_stock_count', '<=', 'tenant_inventory_items.shop_reorder_threshold'))],
                    'online'  => ['label' => 'Online', 'type' => 'enum', 'options' => $yesNo('Shown online', 'Not online'), 'apply' => self::flag(fn ($q) => $q->where('tenant_inventory_items.show_online', 1))],
                    'onhand'  => ['label' => 'On hand', 'type' => 'number', 'sql' => 'GREATEST(tenant_inventory_items.computed_stock_count, 0)'],
                    'value'   => ['label' => 'Stock value at cost', 'type' => 'money',
                        'sql' => '(GREATEST(tenant_inventory_items.computed_stock_count, 0) * COALESCE(tenant_inventory_items.received_cost_cents, tenant_inventory_items.shop_cost_cents, tenant_inventory_items.catalog_cost_cents, 0))'],
                    'price'   => ['label' => 'Price', 'type' => 'money', 'sql' => 'tenant_inventory_items.shop_sell_price_cents'],
                ],
                'cols' => ['Item', 'SKU', 'On hand'],
                'rows' => fn (Builder $q, $money, string $tz) => $q->orderBy('tenant_inventory_items.computed_stock_count')->orderBy('tenant_inventory_items.name')
                    ->get(['id', 'name', 'sku', 'computed_stock_count', 'shop_reorder_threshold'])
                    ->map(fn ($r) => [$r->name ?: 'No name', $r->sku ?: 'No SKU',
                        number_format((int) $r->computed_stock_count) . ($r->shop_reorder_threshold !== null ? ' · reorder at ' . (int) $r->shop_reorder_threshold : ''),
                        'tenant.inventory.show', $r->id])->all(),
            ],
            'special_orders' => [
                'label' => 'Special orders', 'desc' => 'Items ordered for a customer, by when the order was made', 'area' => 'special_orders',
                'opens' => 'tenant.special-orders.index', 'opens_label' => 'Special orders', 'unit' => ['order', 'orders'],
                'base' => fn (Builder $q) => $q->whereNull('tenant_special_orders.deleted_at'),
                'date' => ['col' => 'tenant_special_orders.created_at', 'utc' => true], 'periods' => self::DATED,
                'fields' => [
                    'status'  => ['label' => 'Status', 'type' => 'enum', 'options' => ['needed' => 'Needed', 'ordered' => 'Ordered', 'arrived' => 'Arrived', 'pulled' => 'Picked up', 'cancelled' => 'Cancelled'],
                        'apply' => self::col('tenant_special_orders.status')],
                    'late'    => ['label' => 'Arrival', 'type' => 'enum', 'options' => $yesNo('Past its expected date', 'Not late'),
                        'apply' => self::flag(fn ($q) => $q->where('tenant_special_orders.status', 'ordered')->whereNotNull('tenant_special_orders.expected_arrival_date')
                            ->whereDate('tenant_special_orders.expected_arrival_date', '<', now()->toDateString()))],
                    'cost'    => ['label' => 'Cost', 'type' => 'money',
                        'sql' => '(COALESCE(tenant_special_orders.unit_cost_cents_actual, tenant_special_orders.unit_cost_cents_estimated, 0) * tenant_special_orders.quantity)'],
                ],
                'cols' => ['Item', 'Order', 'Expected'],
                'rows' => fn (Builder $q, $money, string $tz) => $q->leftJoin('tenant_customers', 'tenant_customers.id', '=', 'tenant_special_orders.customer_id')
                    ->orderByRaw('tenant_special_orders.expected_arrival_date IS NULL')->orderBy('tenant_special_orders.expected_arrival_date')
                    ->get(['tenant_special_orders.id', 'tenant_special_orders.so_number', 'tenant_special_orders.item_name_snapshot', 'tenant_special_orders.status',
                        'tenant_special_orders.expected_arrival_date', 'tenant_customers.first_name', 'tenant_customers.last_name'])
                    ->map(fn ($r) => [$r->item_name_snapshot ?: 'Item', '#' . $r->so_number . ' · ' . Str::headline((string) $r->status) . ' · ' . self::name($r->first_name, $r->last_name, 'No customer'),
                        $r->expected_arrival_date ? Carbon::parse($r->expected_arrival_date)->format('M j') : '—', 'tenant.special-orders.show', $r->id])->all(),
            ],
            'gift_cards' => [
                'label' => 'Gift cards', 'desc' => 'Cards sold or issued, by when they were made', 'area' => 'gift_cards',
                'opens' => 'tenant.gift-cards.index', 'opens_label' => 'Gift cards', 'unit' => ['card', 'cards'],
                'base' => fn (Builder $q) => $q,
                'date' => ['col' => 'tenant_gift_cards.created_at', 'utc' => true], 'periods' => self::DATED,
                'fields' => [
                    'status'   => ['label' => 'Status', 'type' => 'enum', 'options' => ['active' => 'Active', 'used' => 'Used up', 'pending' => 'Not paid for yet', 'deactivated' => 'Deactivated'],
                        'apply' => self::col('tenant_gift_cards.status')],
                    'balance'  => ['label' => 'Balance left', 'type' => 'money', 'sql' => 'tenant_gift_cards.balance_cents'],
                    'original' => ['label' => 'Value when issued', 'type' => 'money', 'sql' => 'tenant_gift_cards.original_cents'],
                ],
                'cols' => ['Card', 'For', 'Balance'],
                'rows' => fn (Builder $q, $money, string $tz) => $q->orderByDesc('tenant_gift_cards.created_at')
                    ->get(['id', 'code', 'recipient_name', 'purchaser_name', 'balance_cents', 'status'])
                    ->map(fn ($r) => ['…' . substr((string) $r->code, -4), $r->recipient_name ?: ($r->purchaser_name ?: 'No name') . ' · ' . Str::headline((string) $r->status),
                        $money($r->balance_cents), 'tenant.gift-cards.show', $r->id])->all(),
            ],
            'rentals' => [
                'label' => 'Rentals', 'desc' => 'Rental bookings, by when they start', 'area' => 'rentals',
                'opens' => 'tenant.rentals.desk', 'opens_label' => 'Rentals desk', 'unit' => ['rental', 'rentals'],
                'base' => fn (Builder $q) => $q,
                'date' => ['col' => 'tenant_rentals.starts_at', 'utc' => true], 'periods' => self::AHEAD,
                'fields' => [
                    'status' => ['label' => 'Status', 'type' => 'enum', 'options' => ['reserved' => 'Reserved', 'out' => 'Out', 'returned' => 'Returned', 'cancelled' => 'Cancelled'],
                        'apply' => self::col('tenant_rentals.status')],
                    'late'   => ['label' => 'Due back', 'type' => 'enum', 'options' => $yesNo('Late (out past its due time)', 'Not late'),
                        'apply' => self::flag(fn ($q) => $q->where('tenant_rentals.status', 'out')->where('tenant_rentals.due_at', '<', now()))],
                    'amount' => ['label' => 'Amount', 'type' => 'money', 'sql' => 'tenant_rentals.total_cents'],
                ],
                'cols' => ['Customer', 'Rental', 'Due back'],
                'rows' => fn (Builder $q, $money, string $tz) => $q->leftJoin('tenant_customers', 'tenant_customers.id', '=', 'tenant_rentals.customer_id')
                    ->orderBy('tenant_rentals.starts_at')
                    ->get(['tenant_rentals.id', 'tenant_rentals.rental_number', 'tenant_rentals.status', 'tenant_rentals.due_at', 'tenant_customers.first_name', 'tenant_customers.last_name'])
                    ->map(fn ($r) => [self::name($r->first_name, $r->last_name), '#' . $r->rental_number . ' · ' . Str::headline((string) $r->status),
                        $r->due_at ? Carbon::parse($r->due_at, 'UTC')->setTimezone($tz)->format('M j, g:i A') : '—', 'tenant.rentals.bookings.show', $r->id])->all(),
            ],
            'class_sessions' => [
                'label' => 'Class sessions', 'desc' => 'Scheduled classes, by when they start', 'area' => 'classes',
                'opens' => 'tenant.classes.sessions', 'opens_label' => 'Classes', 'unit' => ['class', 'classes'],
                'base' => fn (Builder $q) => $q->whereNull('tenant_class_sessions.deleted_at'),
                'date' => ['col' => 'tenant_class_sessions.starts_at', 'utc' => true], 'periods' => self::AHEAD,
                'fields' => [
                    'status'   => ['label' => 'Status', 'type' => 'enum', 'options' => ['scheduled' => 'Scheduled', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled'],
                        'apply' => self::col('tenant_class_sessions.status')],
                    'full'     => ['label' => 'Spots', 'type' => 'enum', 'options' => $yesNo('Full', 'Has open spots'),
                        'apply' => self::flag(fn ($q) => $q->whereRaw(self::BOOKED . ' >= tenant_class_sessions.capacity_snapshot'))],
                    'booked'   => ['label' => 'People booked', 'type' => 'number', 'sql' => self::BOOKED],
                    'capacity' => ['label' => 'Spots', 'type' => 'number', 'sql' => 'tenant_class_sessions.capacity_snapshot'],
                ],
                'cols' => ['Class', 'When', 'Booked'],
                'rows' => fn (Builder $q, $money, string $tz) => $q->leftJoin('tenant_class_templates', 'tenant_class_templates.id', '=', 'tenant_class_sessions.class_template_id')
                    ->orderBy('tenant_class_sessions.starts_at')
                    ->selectRaw('tenant_class_sessions.id, tenant_class_sessions.starts_at, tenant_class_sessions.instructor_snapshot, tenant_class_sessions.capacity_snapshot, tenant_class_templates.name, ' . self::BOOKED . ' as booked')
                    ->get()
                    ->map(fn ($r) => [$r->name ?: 'Class', Carbon::parse($r->starts_at, 'UTC')->setTimezone($tz)->format('D M j, g:i A') . ($r->instructor_snapshot ? ' · ' . (is_string($r->instructor_snapshot) && ! str_starts_with($r->instructor_snapshot, '{') ? $r->instructor_snapshot : '') : ''),
                        (int) $r->booked . ' / ' . (int) $r->capacity_snapshot, 'tenant.classes.sessions.show', $r->id])->all(),
            ],
            'memberships' => [
                'label' => 'Memberships', 'desc' => 'Class memberships, as they are right now', 'area' => 'classes',
                'opens' => 'tenant.classes.memberships', 'opens_label' => 'Memberships', 'unit' => ['membership', 'memberships'],
                'base' => fn (Builder $q) => $q->whereNull('tenant_customer_memberships.deleted_at'),
                'date' => null, 'periods' => ['now'],
                'fields' => [
                    'status' => ['label' => 'Status', 'type' => 'enum', 'options' => ['active' => 'Active', 'past_due' => 'Payment overdue', 'paused' => 'Paused', 'cancelled' => 'Cancelled', 'expired' => 'Expired'],
                        'apply' => self::col('tenant_customer_memberships.status')],
                    'renews' => ['label' => 'Renewal', 'type' => 'enum', 'options' => $yesNo('Renews in the next 7 days', 'Not renewing this week'),
                        'apply' => self::flag(fn ($q) => $q->whereBetween('tenant_customer_memberships.current_period_end', [now(), now()->addDays(7)]))],
                    'price'  => ['label' => 'Renewal price', 'type' => 'money', 'sql' => 'tenant_customer_memberships.renewal_price_cents'],
                ],
                'cols' => ['Member', 'Plan', 'Renews'],
                'rows' => fn (Builder $q, $money, string $tz) => $q->leftJoin('tenant_customers', 'tenant_customers.id', '=', 'tenant_customer_memberships.customer_id')
                    ->leftJoin('tenant_class_membership_products', 'tenant_class_membership_products.id', '=', 'tenant_customer_memberships.product_id')
                    ->orderBy('tenant_customer_memberships.current_period_end')
                    ->get(['tenant_customer_memberships.customer_id', 'tenant_customer_memberships.status', 'tenant_customer_memberships.current_period_end',
                        'tenant_customers.first_name', 'tenant_customers.last_name', 'tenant_class_membership_products.name'])
                    ->map(fn ($r) => [self::name($r->first_name, $r->last_name), ($r->name ?: 'Membership') . ' · ' . Str::headline((string) $r->status),
                        $r->current_period_end ? Carbon::parse($r->current_period_end, 'UTC')->setTimezone($tz)->format('M j') : '—', 'tenant.customers.show', $r->customer_id])->all(),
            ],
        ];
    }

    /** People booked into a class session (registered or checked in). */
    private const BOOKED = "(SELECT COUNT(*) FROM tenant_class_registrations r WHERE r.class_session_id = tenant_class_sessions.id AND r.status IN ('registered', 'checked_in') AND r.deleted_at IS NULL)";

    // ---- the blocks themselves ---------------------------------------------

    /** Every made-here block as a registry entry (see BlockRegistry::all()). Read once per request. */
    public static function definitions(): array
    {
        if (self::$defs !== null) return self::$defs;
        $out = [];
        try {
            foreach (DashboardBlock::where('is_builtin', false)->orderBy('id')->get() as $b) {
                $cfg = self::normalize($b->kind, (array) $b->config);
                $out[$b->key] = [
                    'name'   => $b->name,
                    'origin' => 'custom',
                    'view'   => 'tenant.dashboard.blocks.custom',
                    'reads'  => self::reads($b->kind, $cfg),
                    'desc'   => self::sentence($b->kind, $cfg),
                    'access' => self::access($b->kind, $cfg),
                    'needs'  => [],
                    'title'  => $b->name,
                    'custom' => true,
                    'kind'   => $b->kind,
                    'config' => $cfg,
                    'id'     => $b->id,
                ];
            }
        } catch (\Throwable $e) {
            // table not there yet: no made-here blocks
        }

        return self::$defs = $out;
    }

    public static function flush(): void
    {
        self::$defs = null;
    }

    /** The areas a block needs, in BlockAccess's terms. */
    public static function access(string $kind, array $cfg): array
    {
        if ($kind === 'data') {
            $area = self::sources()[$cfg['source']]['area'] ?? null;
            return $area ? ['all' => [$area]] : [];
        }
        if ($kind === 'action') {
            $area = \App\Support\SectionRegistry::sectionForRoute(self::ACTIONS[$cfg['action']]['route'] ?? null);
            return $area ? ['all' => [$area]] : [];
        }

        return [];
    }

    public static function reads(string $kind, array $cfg): string
    {
        return match ($kind) {
            'data'   => self::sources()[$cfg['source']]['label'] ?? '—',
            'action' => 'Button: ' . (self::ACTIONS[$cfg['action']]['label'] ?? '—'),
            default  => 'A note',
        };
    }

    /** Fields that can be added up or averaged. */
    public static function summable(string $source): array
    {
        return array_keys(array_filter(self::sources()[$source]['fields'] ?? [], fn ($f) => in_array($f['type'], ['money', 'number'], true)));
    }

    /** Which displays fit a measure and period. */
    public static function displaysFor(string $measure, string $period, bool $dated): array
    {
        if ($measure === 'list') return ['rows', 'table'];
        $multi = $dated && ! in_array($period, ['now', 'today', 'all_time'], true);

        return array_values(array_filter(['number', $multi ? 'trend' : null, $multi ? 'bars' : null, 'progress']));
    }

    /**
     * A config fit to store and run: known values only. Anything unknown
     * falls back to a safe default rather than failing.
     */
    public static function normalize(string $kind, array $c): array
    {
        if ($kind === 'action') {
            return ['action' => isset(self::ACTIONS[$c['action'] ?? '']) ? $c['action'] : 'new_appointment', 'line' => mb_substr(trim((string) ($c['line'] ?? '')), 0, 140)];
        }
        if ($kind === 'note') {
            $until = $c['until'] ?? null;
            $until = is_string($until) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) ? $until : null;
            return ['text' => mb_substr(trim((string) ($c['text'] ?? '')), 0, 600), 'until' => $until];
        }

        $sources = self::sources();
        $source = isset($sources[$c['source'] ?? '']) ? $c['source'] : 'appointments';
        $src = $sources[$source];
        $sum = self::summable($source);
        $measure = isset(self::MEASURES[$c['measure'] ?? '']) ? $c['measure'] : 'count';
        if (in_array($measure, ['sum', 'avg'], true) && ! $sum) $measure = 'count';
        $field = in_array($c['field'] ?? null, $sum, true) ? $c['field'] : ($sum[0] ?? null);
        $period = in_array($c['period'] ?? '', $src['periods'], true) ? $c['period'] : ($src['date'] ? 'last_30' : 'now');
        $allowed = self::displaysFor($measure, $period, $src['date'] !== null);
        $display = in_array($c['display'] ?? '', $allowed, true) ? $c['display'] : $allowed[0];
        $goal = is_numeric($c['goal'] ?? null) && (float) $c['goal'] > 0 ? round((float) $c['goal'], 2) : null;
        if ($display === 'progress' && ! $goal) $goal = null; // drawn as a plain number until a goal is set

        $rules = [];
        foreach (array_slice((array) ($c['rules'] ?? []), 0, 6) as $r) {
            [$f, $op, $v] = array_pad(array_values((array) $r), 3, null);
            $def = $src['fields'][$f] ?? null;
            if (! $def) continue;
            if ($def['type'] === 'enum') {
                if (! in_array($op, ['is', 'is_not'], true) || ! isset($def['options'][$v])) continue;
            } else {
                if (! in_array($op, ['more', 'less'], true) || ! is_numeric($v)) continue;
                $v = round((float) $v, 2);
            }
            $rules[] = [$f, $op, $v];
        }

        return [
            'source'  => $source,
            'rules'   => $rules,
            'measure' => $measure,
            'field'   => in_array($measure, ['sum', 'avg'], true) ? $field : null,
            'period'  => $period,
            'compare' => $measure !== 'list' && $src['date'] !== null && ! in_array($period, ['all_time', 'now'], true) && ! empty($c['compare']),
            'display' => $display,
            'goal'    => $display === 'progress' ? $goal : null,
            'line'    => mb_substr(trim((string) ($c['line'] ?? '')), 0, 140),
        ];
    }

    /** One plain sentence: what the block shows. */
    public static function sentence(string $kind, array $c): string
    {
        if ($kind === 'action') return 'A button that opens ' . (self::ACTIONS[$c['action']]['label'] ?? 'a page') . '.';
        if ($kind === 'note') return 'A note to shops' . ($c['until'] ? ', shown until ' . Carbon::parse($c['until'])->format('M j') : '') . '.';

        $src = self::sources()[$c['source']];
        $fl = fn ($k) => strtolower($src['fields'][$k]['label'] ?? '');
        $what = match ($c['measure']) {
            'count' => 'How many ' . strtolower($src['label']),
            'sum'   => 'The total ' . $fl($c['field']) . ' across ' . strtolower($src['label']),
            'avg'   => 'The average ' . $fl($c['field']) . ' per ' . ($src['unit'][0] ?? 'record'),
            default => 'The ' . strtolower($src['label']),
        };
        $rules = array_map(function ($r) use ($src) {
            $f = $src['fields'][$r[0]];
            if ($f['type'] === 'enum') return strtolower($f['label']) . ($r[1] === 'is_not' ? ' is not ' : ' is ') . $f['options'][$r[2]];
            $amt = $f['type'] === 'money' ? '$' . number_format((float) $r[2], 2) : rtrim(rtrim(number_format((float) $r[2], 2), '0'), '.');
            return strtolower($f['label']) . ($r[1] === 'more' ? ' over ' : ' under ') . $amt;
        }, $c['rules']);
        $goal = $c['display'] === 'progress' && $c['goal'] ? ', against a goal of ' . self::goalText($c) : '';

        return $what . ($rules ? ' where ' . implode(' and ', $rules) : '') . ', ' . strtolower(self::PERIODS[$c['period']])
            . ($c['compare'] ? ', compared with the period before' : '') . $goal . '.';
    }

    public static function isMoney(array $c): bool
    {
        return in_array($c['measure'], ['sum', 'avg'], true) && (self::sources()[$c['source']]['fields'][$c['field']]['type'] ?? null) === 'money';
    }

    public static function goalText(array $c, ?Tenant $tenant = null): string
    {
        if (! $c['goal']) return '';

        return self::isMoney($c) ? self::money((int) round($c['goal'] * 100), $tenant?->currency_symbol ?? '$') : number_format($c['goal']);
    }

    // ---- figures -------------------------------------------------------------

    /** The block's figures at one shop, cached for five minutes. */
    public static function result(string $key, Tenant $tenant): ?array
    {
        $def = self::definitions()[$key] ?? null;
        if (! $def) return null;
        if ($def['kind'] !== 'data') return ['kind' => $def['kind']];

        $ck = 'dashcb:' . $tenant->id . ':' . $key . ':' . md5(json_encode($def['config']));

        return Cache::remember($ck, 300, fn () => self::compute($def['config'], $tenant));
    }

    /** The figures, uncached (the builder's preview uses this directly). */
    public static function compute(array $c, Tenant $tenant): array
    {
        $tz = $tenant->timezone();
        [$from, $to, $pFrom, $pTo] = self::range($c['period'], $tz);
        $out = ['kind' => 'data', 'measure' => $c['measure'], 'display' => $c['display'], 'money' => self::isMoney($c)];

        if ($c['measure'] === 'list') {
            $out['total'] = (clone self::query($c, $tenant, $from, $to))->count();
            $sym = $tenant->currency_symbol ?? '$';
            $out['rows'] = (self::sources()[$c['source']]['rows'])(self::query($c, $tenant, $from, $to)->limit(6), fn ($cents) => self::money((int) $cents, $sym), $tz);
            return $out;
        }

        $out['value'] = self::aggregate($c, $tenant, $from, $to);
        $out['prev'] = $c['compare'] && $pFrom ? self::aggregate($c, $tenant, $pFrom, $pTo) : null;
        $out['pct'] = ($out['prev'] !== null && $out['prev'] != 0) ? (int) round(($out['value'] - $out['prev']) / abs($out['prev']) * 100) : null;
        if (in_array($c['display'], ['trend', 'bars'], true) && $from) {
            $out['series'] = self::series($c, $tenant, $from, $to);
            $out['labels'] = self::dayLabels($from, $to);
            $out['today_i'] = array_search(Carbon::now($tz)->toDateString(), array_keys(self::days($from, $to)), true);
        }
        if ($c['display'] === 'progress' && $c['goal']) {
            $goal = self::isMoney($c) ? $c['goal'] * 100 : $c['goal'];
            $out['goal'] = $goal;
            $out['pct_goal'] = $goal > 0 ? (int) min(999, round($out['value'] / $goal * 100)) : null;
        }

        return $out;
    }

    /** [from, to, prevFrom, prevTo] as tenant-local Carbons; nulls for all time and right now. */
    public static function range(string $period, string $tz): array
    {
        $today = Carbon::now($tz)->startOfDay();
        switch ($period) {
            case 'today':      $f = $today->copy(); $t = $today->copy(); break;
            case 'next_7':     $f = $today->copy(); $t = $today->copy()->addDays(6); break;
            case 'this_week':  $f = $today->copy()->startOfWeek(); $t = $f->copy()->addDays(6); break;
            case 'last_7':     $f = $today->copy()->subDays(6); $t = $today->copy(); break;
            case 'this_month': $f = $today->copy()->startOfMonth(); $t = $today->copy()->endOfMonth()->startOfDay();
                return [$f, $t, $f->copy()->subMonthNoOverflow()->startOfMonth(), $f->copy()->subDay()];
            case 'all_time':
            case 'now':        return [null, null, null, null];
            default:           $f = $today->copy()->subDays(29); $t = $today->copy();
        }
        $days = $f->diffInDays($t) + 1;

        return [$f, $t, $f->copy()->subDays($days), $f->copy()->subDay()];
    }

    /** The source's rows at this shop, with its rules and the date range applied. */
    private static function query(array $c, Tenant $tenant, ?Carbon $from, ?Carbon $to): Builder
    {
        $src = self::sources()[$c['source']];
        $table = self::table($c['source']);
        $q = DB::table($table)->where($table . '.tenant_id', $tenant->id);
        ($src['base'])($q);
        if ($from && $src['date']) {
            $d = $src['date'];
            $d['utc']
                ? $q->whereBetween($d['col'], [$from->copy()->utc(), $to->copy()->endOfDay()->utc()])
                : $q->whereBetween($d['col'], [$from->toDateString(), $to->toDateString()]);
        }

        foreach ($c['rules'] as [$f, $op, $v]) {
            $def = $src['fields'][$f];
            if ($def['type'] === 'enum') {
                ($def['apply'])($q, $v, $op === 'is_not');
            } else {
                $q->whereRaw($def['sql'] . ($op === 'more' ? ' > ?' : ' < ?'), [$def['type'] === 'money' ? (int) round($v * 100) : $v]);
            }
        }

        return $q;
    }

    private static function table(string $source): string
    {
        return [
            'appointments' => 'tenant_appointments', 'sales' => 'tenant_sales', 'customers' => 'tenant_customers',
            'inventory' => 'tenant_inventory_items', 'special_orders' => 'tenant_special_orders', 'gift_cards' => 'tenant_gift_cards',
            'rentals' => 'tenant_rentals', 'class_sessions' => 'tenant_class_sessions', 'memberships' => 'tenant_customer_memberships',
        ][$source];
    }

    private static function aggExpr(array $c): string
    {
        if ($c['measure'] === 'count') return 'COUNT(*)';
        $sql = self::sources()[$c['source']]['fields'][$c['field']]['sql'];

        return ($c['measure'] === 'avg' ? 'AVG(' : 'SUM(') . $sql . ')';
    }

    private static function aggregate(array $c, Tenant $tenant, ?Carbon $from, ?Carbon $to): float
    {
        return (float) (self::query($c, $tenant, $from, $to)->selectRaw(self::aggExpr($c) . ' as v')->value('v') ?? 0);
    }

    private static function days(Carbon $from, Carbon $to): array
    {
        $days = [];
        for ($d = $from->copy(); $d->lte($to) && count($days) < 62; $d->addDay()) $days[$d->toDateString()] = 0;

        return $days;
    }

    /** "M", "T"… for a week or less; the day of the month beyond that. */
    private static function dayLabels(Carbon $from, Carbon $to): array
    {
        $keys = array_keys(self::days($from, $to));

        return array_map(fn ($d) => count($keys) <= 7 ? Carbon::parse($d)->format('D')[0] : Carbon::parse($d)->format('j'), $keys);
    }

    /** One value per day across the period. */
    private static function series(array $c, Tenant $tenant, Carbon $from, Carbon $to): array
    {
        $days = self::days($from, $to);
        $d = self::sources()[$c['source']]['date'];
        $q = self::query($c, $tenant, $from, $to);
        if (! $d['utc']) {
            $q->selectRaw('DATE(' . $d['col'] . ') as d, ' . self::aggExpr($c) . ' as v');
        } else {
            [$expr, $bind] = tenant_tz_offset_expr($d['col'], $tenant->timezone(), $from->copy()->utc(), $to->copy()->endOfDay()->utc());
            $q->selectRaw("DATE({$expr}) as d, " . self::aggExpr($c) . ' as v', $bind);
        }
        foreach ($q->groupBy('d')->pluck('v', 'd') as $day => $v) {
            if (array_key_exists($day, $days)) $days[$day] = (float) $v;
        }

        return array_values($days);
    }

    public static function money(int $cents, string $sym = '$'): string
    {
        return ($cents < 0 ? '-' : '') . $sym . number_format(abs($cents) / 100, abs($cents) >= 100000 ? 0 : 2);
    }

    /** A value as text: "42", "3.5", "$1,280". */
    public static function format(float $v, array $r, ?Tenant $tenant = null): string
    {
        if (! empty($r['money'])) return self::money((int) round($v), $tenant?->currency_symbol ?? '$');

        return number_format($v, (abs($v - round($v)) > 0.05) ? 1 : 0);
    }

    /** The headline figure as text. */
    public static function figure(array $r, ?Tenant $tenant = null): string
    {
        return self::format((float) ($r['value'] ?? 0), $r, $tenant);
    }

    /** The unit after a count: "bookings". */
    public static function unit(array $cfg, float $n): string
    {
        $u = self::sources()[$cfg['source']]['unit'] ?? ['', ''];

        return (int) $n === 1 ? $u[0] : $u[1];
    }

    /** "Last 30 days · vs the 30 days before" */
    public static function meta(array $cfg): string
    {
        $p = self::PERIODS[$cfg['period']] ?? '';
        if (! $cfg['compare']) return $p;

        return $p . ' · vs ' . match ($cfg['period']) {
            'today' => 'yesterday', 'this_week' => 'last week', 'this_month' => 'last month', 'next_7' => 'the 7 days before',
            'last_7' => 'the 7 days before', default => 'the 30 days before',
        };
    }
}
