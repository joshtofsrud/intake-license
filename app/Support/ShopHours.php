<?php

namespace App\Support;

use App\Models\Tenant\TenantLocation;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-SHOP-LOCATIONS: a shop's locations as its public site shows them:
 * the Locations menu, the top strip and the footer all read these cards, so
 * hours (Capacity), address and phone (Settings > Locations) are set once.
 */
class ShopHours
{
    public const PARTS = ['status', 'hours', 'map', 'addr', 'call', 'dir'];
    private const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    private const ORDER = [1, 2, 3, 4, 5, 6, 0];

    private static array $memo = [];

    /** Active locations for the builder's picker: [['id' => ..., 'name' => ...]]. */
    public static function options($tenant): array
    {
        return array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['loc_name']], self::cards($tenant));
    }

    public static function cleanParts($parts): array
    {
        if (! is_array($parts)) return self::PARTS;
        $p = array_values(array_intersect(self::PARTS, array_map('strval', $parts)));
        return $p ?: self::PARTS;
    }

    /** Cards for the shop's active locations, main one first. $ids narrows the set (unknown ids are ignored). */
    public static function cards($tenant, array $ids = []): array
    {
        if (! $tenant) return [];
        $tid = (string) $tenant->id;
        if (! isset(self::$memo[$tid])) self::$memo[$tid] = self::build($tenant);
        $all = self::$memo[$tid];
        if ($ids) {
            $pick = array_values(array_filter($all, fn ($c) => in_array($c['id'], $ids, true)));
            if ($pick) return $pick;
        }
        return $all;
    }

    private static function build($tenant): array
    {
        try {
            $locs = TenantLocation::where('tenant_id', $tenant->id)->where('is_active', true)
                ->orderByDesc('is_default')->orderBy('sort_order')->orderBy('name')->get();
            if ($locs->isEmpty()) return [];

            $nowUtc = now('UTC');
            $rules = DB::table('tenant_capacity_rules')->where('tenant_id', $tenant->id)
                ->where(fn ($w) => $w->where('rule_type', 'default')
                    ->orWhere(fn ($x) => $x->where('rule_type', 'override')
                        ->whereBetween('specific_date', [$nowUtc->copy()->subDays(2)->toDateString(), $nowUtc->copy()->addDays(2)->toDateString()])))
                ->get(['location_id', 'rule_type', 'day_of_week', 'specific_date', 'open_time', 'close_time', 'is_closed']);
        } catch (\Throwable $e) {
            return [];
        }

        $byLoc = [];
        foreach ($rules as $r) $byLoc[(string) ($r->location_id ?? '')][] = $r;
        $mainId = (string) $locs->first()->id;
        $many   = $locs->count() > 1;

        $out = [];
        foreach ($locs as $l) {
            $own = $byLoc[(string) $l->id] ?? [];
            $hasOwnWeek = (bool) array_filter($own, fn ($r) => $r->rule_type === 'default');
            // a location without hours of its own follows the shop's (unassigned, then the main location's)
            $src = $hasOwnWeek ? $own : (($byLoc[''] ?? []) ?: ($byLoc[$mainId] ?? []));
            $tz  = $l->timezone ?: $tenant->timezone();
            $out[] = self::card($l, $src, $tz, $many ? (string) $l->name : (string) ($tenant->name ?: $l->name));
        }
        return $out;
    }

    private static function card(TenantLocation $l, array $rules, string $tz, string $title): array
    {
        try { $now = now($tz); } catch (\Throwable $e) { $now = now('UTC'); }
        $today = $now->toDateString();
        $dow   = (int) $now->dayOfWeek;

        $week = [];
        $todayRule = null;
        foreach ($rules as $r) {
            if ($r->rule_type === 'default' && $r->day_of_week !== null) $week[(int) $r->day_of_week] = $r;
        }
        foreach ($rules as $r) {
            if ($r->rule_type === 'override' && substr((string) $r->specific_date, 0, 10) === $today
                && ($r->is_closed || ($r->open_time && $r->close_time))) $todayRule = $r;
        }
        $todayRule = $todayRule ?: ($week[$dow] ?? null);

        $text = function ($r) {
            if (! $r || $r->is_closed || ! $r->open_time || ! $r->close_time) return null;
            return self::time($r->open_time) . '–' . self::time($r->close_time);
        };

        $rows = [];
        foreach (self::ORDER as $d) {
            $r = $d === $dow ? $todayRule : ($week[$d] ?? null);
            $rows[] = ['day' => self::DAYS[$d], 'text' => $text($r) ?? 'Closed', 'today' => $d === $dow, 'base' => $text($week[$d] ?? null)];
        }

        // Mon–Sat 10am–6:30pm · Sun closed
        $sum = [];
        for ($i = 0; $i < 7;) {
            $j = $i;
            while ($j + 1 < 7 && $rows[$j + 1]['base'] === $rows[$i]['base']) $j++;
            $sum[] = ($i === $j ? $rows[$i]['day'] : $rows[$i]['day'] . '–' . $rows[$j]['day']) . ' ' . ($rows[$i]['base'] ?? 'closed');
            $i = $j + 1;
        }

        $hasHours = (bool) $week;
        $open = null; $status = ''; $todayText = '';
        if ($hasHours) {
            $t = $text($todayRule);
            if ($t === null) {
                $open = false; $status = 'Closed today'; $todayText = 'Closed today';
            } else {
                $n = $now->format('H:i:s');
                $o = substr((string) $todayRule->open_time, 0, 8);
                $c = substr((string) $todayRule->close_time, 0, 8);
                $isOpen = $c > $o ? ($n >= $o && $n < $c) : ($n >= $o || $n < $c);
                $open = $isOpen;
                $status = $isOpen ? 'Open · until ' . self::time($c) : ($n < $o ? 'Opens ' . self::time($o) : 'Closed now');
                $todayText = 'Open today ' . $t;
            }
        }

        $l1 = trim(implode(', ', array_filter([trim((string) $l->address_line_1), trim((string) $l->address_line_2)])));
        $l2 = trim(trim((string) $l->city) . (trim((string) $l->state) !== '' ? ', ' . trim((string) $l->state) : '') . ' ' . trim((string) $l->postal_code), " ,");
        $lines = array_values(array_filter([$l1, $l2]));
        $q = $l1 !== '' ? rawurlencode(implode(', ', $lines)) : '';
        $phone = trim((string) $l->phone);

        return [
            'id'        => (string) $l->id,
            'name'      => $title,
            'loc_name'  => (string) $l->name,
            'has_hours' => $hasHours,
            'open'      => $open,
            'status'    => $status,
            'today'     => $todayText,
            'week'      => $rows,
            'summary'   => $hasHours ? implode(' · ', $sum) : '',
            'lines'     => $lines,
            'address'   => implode(', ', $lines),
            'dir_url'   => $q !== '' ? 'https://www.google.com/maps/dir/?api=1&destination=' . $q : '',
            'map_url'   => $q !== '' ? 'https://www.google.com/maps?q=' . $q . '&output=embed' : '',
            'phone'     => $phone,
            'tel'       => $phone !== '' ? 'tel:' . preg_replace('/[^0-9+]/', '', $phone) : '',
            'email'     => trim((string) $l->email),
        ];
    }

    /** 10:00:00 -> 10am, 18:30:00 -> 6:30pm */
    private static function time(string $t): string
    {
        $h = (int) substr($t, 0, 2);
        $m = (int) substr($t, 3, 2);
        $ap = $h >= 12 ? 'pm' : 'am';
        $h12 = $h % 12 ?: 12;
        return $h12 . ($m ? ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) : '') . $ap;
    }
}
