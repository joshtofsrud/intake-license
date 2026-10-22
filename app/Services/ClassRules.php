<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantClassSession;

/**
 * The studio's booking rules, read from tenant settings (settings.class_rules).
 * Every rule is optional: an empty value means "no limit", so a studio that
 * never opens the rules page behaves exactly as before. Staff are never held
 * to these (callers pass $staff and skip them).
 *
 * MARKER-CLASSES-P4
 */
class ClassRules
{
    public const MAX_REMINDER_HOURS = 48; // MARKER-CLASSES-P5

    /** What happens to an online payment when the customer cancels in time. The studio's choice; unset = nothing automatic. MARKER-CLASSES-P6 */
    public const ONLINE_REFUND = [
        'card' => 'Refund to their card',
        'none' => 'No refund, handle it at the studio',
    ];

    public const AFTER_CUTOFF = [
        'keep'  => 'Keep the credit (it is used up)',
        'block' => "Don't allow cancelling",
    ];

    public function __construct(private array $v) {}

    public static function for(?Tenant $tenant = null): self
    {
        $tenant ??= tenant();
        $raw = (array) (((array) ($tenant->settings ?? []))['class_rules'] ?? []);
        $int = fn ($k) => isset($raw[$k]) && $raw[$k] !== '' && $raw[$k] !== null ? max(0, (int) $raw[$k]) : null;
        return new self([
            'book_ahead_days'     => $int('book_ahead_days'),
            'book_cutoff_minutes' => $int('book_cutoff_minutes'),
            'cancel_cutoff_hours' => $int('cancel_cutoff_hours'),
            'after_cutoff'        => array_key_exists($raw['after_cutoff'] ?? '', self::AFTER_CUTOFF) ? $raw['after_cutoff'] : 'keep',
            'waitlist_on'         => ! array_key_exists('waitlist_on', $raw) || (bool) $raw['waitlist_on'],
            'waitlist_max'        => $int('waitlist_max'),
            'waitlist_stop_hours' => $int('waitlist_stop_hours'),
            'publish_weeks'       => min(26, max(1, $int('publish_weeks') ?? 4)), // MARKER-CLASSES-P7
            'online_refund'       => array_key_exists($raw['online_refund'] ?? '', self::ONLINE_REFUND) ? $raw['online_refund'] : null, // MARKER-CLASSES-P6
            'reminder_hours'      => min(self::MAX_REMINDER_HOURS, max(1, $int('reminder_hours') ?? 2)), // MARKER-CLASSES-P5
        ]);
    }

    public function get(string $k): mixed { return $this->v[$k] ?? null; }
    public function all(): array { return $this->v; }

    /** Why a customer can't book this session yet or any more; null = fine. */
    public function bookingBlock(TenantClassSession $s): ?string
    {
        if ($this->v['book_ahead_days'] !== null && $s->starts_at->gt(now()->addDays($this->v['book_ahead_days']))) {
            return 'Booking for this class opens ' . $this->v['book_ahead_days'] . ' day' . ($this->v['book_ahead_days'] === 1 ? '' : 's') . ' before it starts.';
        }
        if ($this->v['book_cutoff_minutes'] && $s->starts_at->lte(now()->addMinutes($this->v['book_cutoff_minutes']))) {
            return 'Booking closes ' . $this->minutes($this->v['book_cutoff_minutes']) . ' before the class starts.';
        }
        return null;
    }

    /** True once a customer's cancel is "late" for this session. */
    public function isLateCancel(TenantClassSession $s): bool
    {
        return $this->v['cancel_cutoff_hours'] !== null
            && $s->starts_at->lte(now()->addHours($this->v['cancel_cutoff_hours']));
    }

    /** No one is moved in from the waitlist this close to the start. */
    public function promotionStopped(TenantClassSession $s): bool
    {
        return $this->v['waitlist_stop_hours'] !== null
            && $s->starts_at->lte(now()->addHours($this->v['waitlist_stop_hours']));
    }

    /** One plain sentence for the class page / account about cancelling. */
    public function cancelNote(TenantClassSession $s): ?string
    {
        if ($this->v['cancel_cutoff_hours'] === null) {
            return null;
        }
        $h = $this->v['cancel_cutoff_hours'];
        $when = $h === 0 ? 'before the class starts' : $h . ' hour' . ($h === 1 ? '' : 's') . ' before it starts';
        if ($this->isLateCancel($s)) {
            return $this->v['after_cutoff'] === 'block'
                ? 'It is too late to cancel this class online. Contact the studio.'
                : 'Cancelling now will use up your credit.';
        }
        return 'Cancel free up to ' . $when . '.';
    }

    private function minutes(int $m): string
    {
        if ($m % 60 === 0) {
            $h = intdiv($m, 60);
            return $h . ' hour' . ($h === 1 ? '' : 's');
        }
        return $m . ' minutes';
    }
}
