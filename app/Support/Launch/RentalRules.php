<?php

namespace App\Support\Launch;

use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * MARKER-RENTAL-HOURS: pickup and return hours, closed dates, and how renters pay.
 *
 * Hours live in settings['rental_hours'] = ['days' => [1..7 => ['open' => bool, 'from' => 'HH:MM', 'to' => 'HH:MM']], 'closed' => ['Y-m-d', ...]]
 * (ISO weekday: 1 = Monday). A shop that never saved hours has none, and online
 * bookings are not limited by time until it does; the launch checklist asks for them.
 *
 * Pay mode lives in settings['rental_pay_mode']: online | pickup | choice.
 */
class RentalRules
{
    public const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    public const PAY_MODES = ['online' => 'Online when they book', 'pickup' => 'At pickup', 'choice' => 'Renter chooses'];

    public static function hours(Tenant $t): array
    {
        $raw = (array) (($t->settings['rental_hours'] ?? []) ?: []);
        $days = [];
        foreach (self::DAY_NAMES as $n => $name) {
            $d = (array) ($raw['days'][$n] ?? $raw['days'][(string) $n] ?? []);
            $days[$n] = [
                'open' => (bool) ($d['open'] ?? false),
                'from' => self::hhmm($d['from'] ?? null, '09:00'),
                'to'   => self::hhmm($d['to'] ?? null, '17:00'),
            ];
        }
        $closed = array_values(array_unique(array_filter(array_map('strval', (array) ($raw['closed'] ?? [])), fn ($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d))));
        sort($closed);
        return ['days' => $days, 'closed' => $closed, 'saved' => isset($raw['days'])];
    }

    private static function hhmm($v, string $fallback): string
    {
        return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $fallback;
    }

    /** "Tue–Sat 9:00–17:00 · 2 closed dates" */
    public static function summary(array $h): string
    {
        $groups = [];
        $prev = null;
        foreach ($h['days'] as $n => $d) {
            $sig = $d['open'] ? $d['from'] . '-' . $d['to'] : null;
            if ($sig && $prev && $prev['sig'] === $sig && $prev['last'] === $n - 1) {
                $groups[count($groups) - 1]['last'] = $n;
                $prev = $groups[count($groups) - 1];
                continue;
            }
            if ($sig) {
                $groups[] = ['first' => $n, 'last' => $n, 'sig' => $sig, 'from' => $d['from'], 'to' => $d['to']];
                $prev = $groups[count($groups) - 1];
            } else {
                $prev = null;
            }
        }
        $parts = array_map(fn ($g) => self::DAY_NAMES[$g['first']] . ($g['last'] !== $g['first'] ? '–' . self::DAY_NAMES[$g['last']] : '') . ' ' . self::nice($g['from']) . '–' . self::nice($g['to']), $groups);
        $closed = count(array_filter($h['closed'], fn ($d) => $d >= now()->toDateString()));
        return implode(', ', $parts) . ($closed ? ' · ' . $closed . ' closed ' . ($closed === 1 ? 'date' : 'dates') : '');
    }

    public static function nice(string $hhmm): string
    {
        return Carbon::createFromFormat('H:i', $hhmm)->format('g:i A');
    }

    /** null when the time is fine, or the reason it is not, in words a renter reads. */
    public static function refuse(array $h, Carbon $local, string $what): ?string
    {
        if (! array_filter($h['days'], fn ($d) => $d['open'])) return null; // no hours saved: not limited
        if (in_array($local->toDateString(), $h['closed'], true)) {
            return 'We are closed on ' . $local->format('l, M j') . '. Choose another ' . $what . ' day.';
        }
        $d = $h['days'][(int) $local->format('N')];
        if (! $d['open']) {
            return 'We are closed on ' . $local->format('l') . 's. Choose another ' . $what . ' day.';
        }
        $hm = $local->format('H:i');
        if ($hm < $d['from'] || $hm > $d['to']) {
            return ucfirst($what) . ' on ' . $local->format('l') . 's is between ' . self::nice($d['from']) . ' and ' . self::nice($d['to']) . '.';
        }
        return null;
    }

    public static function payMode(Tenant $t): string
    {
        $m = (string) ($t->settings['rental_pay_mode'] ?? '');
        if (isset(self::PAY_MODES[$m])) return $m;
        // never chosen: what the booking page did before the choice existed
        return (new \App\Services\Tenant\DirectPaymentsService($t))->isEnabled() ? 'online' : 'pickup';
    }

    public static function payModeLabel(string $m): string
    {
        return self::PAY_MODES[$m] ?? 'At pickup';
    }

    /**
     * Does this booking get paid online? $asked is what the renter picked when the
     * shop lets them choose. Online needs payments switched on; without it the
     * booking is paid at pickup rather than refused.
     */
    public static function paysOnline(Tenant $t, ?string $asked): bool
    {
        if (! (new \App\Services\Tenant\DirectPaymentsService($t))->isEnabled()) return false;
        return match (self::payMode($t)) {
            'online' => true,
            'choice' => $asked !== 'pickup',
            default  => false,
        };
    }
}
