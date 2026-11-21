<?php

namespace App\Support;

use App\Models\Tenant\TenantOrder;

/**
 * MARKER-ORDER-STEPS: what each order step is called for how the order leaves, and the
 * record of who moved it along. The statuses underneath stay the same (paid → fulfilling →
 * fulfilled → completed), so reports and filters don't change; only the words do.
 */
class OrderSteps
{
    /** [status => label] in order, for this order's way of leaving. */
    public static function steps(TenantOrder $o): array
    {
        return match ($o->fulfillment_type) {
            'ship'           => [TenantOrder::STATUS_PAID => 'Paid', TenantOrder::STATUS_FULFILLING => 'Shipping', TenantOrder::STATUS_FULFILLED => 'All shipped', TenantOrder::STATUS_COMPLETED => 'Delivered'],
            'local_delivery' => [TenantOrder::STATUS_PAID => 'Paid', TenantOrder::STATUS_FULFILLING => 'Being prepared', TenantOrder::STATUS_FULFILLED => 'Out for delivery', TenantOrder::STATUS_COMPLETED => 'Delivered'],
            default          => [TenantOrder::STATUS_PAID => 'Paid', TenantOrder::STATUS_FULFILLING => 'Being prepared', TenantOrder::STATUS_FULFILLED => 'Ready for pickup', TenantOrder::STATUS_COMPLETED => 'Picked up'],
        };
    }

    public static function label(TenantOrder $o, ?string $status = null): string
    {
        $status ??= $o->status;
        return self::steps($o)[$status] ?? match ($status) {
            TenantOrder::STATUS_PENDING_PAYMENT => 'Awaiting payment',
            TenantOrder::STATUS_CANCELLED       => 'Cancelled',
            TenantOrder::STATUS_ABANDONED       => 'Abandoned',
            default                             => ucfirst(str_replace('_', ' ', (string) $status)),
        };
    }

    /**
     * The button that moves it on and what that does. Null when there is none to press
     * (done, cancelled, or a shipped order, which moves with its shipments).
     *
     * @return array{0:string,1:string,2:string}|null [next status, button, what happens]
     */
    public static function next(TenantOrder $o): ?array
    {
        if ($o->fulfillment_type === 'ship') {
            return null;
        }
        $delivery = $o->fulfillment_type === 'local_delivery';
        return match ($o->status) {
            TenantOrder::STATUS_PAID       => [TenantOrder::STATUS_FULFILLING, 'Start preparing', 'Pull the items and set them aside. Nothing is sent to the customer yet.'],
            TenantOrder::STATUS_FULFILLING => $delivery
                ? [TenantOrder::STATUS_FULFILLED, 'Out for delivery', 'The customer is emailed that it is on its way today.']
                : [TenantOrder::STATUS_FULFILLED, 'Ready for pickup', 'The customer is told it is ready.'],
            TenantOrder::STATUS_FULFILLED  => $delivery
                ? [TenantOrder::STATUS_COMPLETED, 'Delivered', 'Records who delivered it and when. The order is then complete.']
                : [TenantOrder::STATUS_COMPLETED, 'Picked up', 'Records who handed it over and when. The order is then complete.'],
            default => null,
        };
    }

    /** Add a line to the order's history (kept on the order, newest last). Saves. */
    public static function record(TenantOrder $o, string $what, ?string $by = null, ?string $note = null): void
    {
        $m = (array) ($o->metadata ?? []);
        $m['history'] = array_values(array_merge((array) ($m['history'] ?? []), [array_filter([
            'what' => $what, 'at' => now()->toIso8601String(), 'by' => $by, 'note' => $note,
        ], fn ($v) => $v !== null && $v !== '')]));
        $o->forceFill(['metadata' => $m])->save();
    }

    /** The history to show, with Paid filled in for orders from before it was kept. */
    public static function history(TenantOrder $o): array
    {
        $h = (array) (((array) ($o->metadata ?? []))['history'] ?? []);
        if ($o->paid_at && ! collect($h)->contains(fn ($r) => ($r['what'] ?? '') === 'Paid')) {
            array_unshift($h, ['what' => 'Paid', 'at' => $o->paid_at->toIso8601String(), 'note' => $o->payment_method ? tender_label($o->payment_method) : 'card']);
        }
        return $h;
    }

    /** When it reached each status, from the history (for the step bar). [status => iso time] */
    public static function reached(TenantOrder $o): array
    {
        $labels = array_flip(self::steps($o));
        $out = [];
        foreach (self::history($o) as $r) {
            if (isset($labels[$r['what'] ?? ''])) {
                $out[$labels[$r['what']]] = $r['at'];
            }
        }
        return $out;
    }

    /** Pickup orders ready this many days or more and not collected (0 = reminders off). */
    public static function reminderDays(\App\Models\Tenant $t): int
    {
        return max(0, (int) ((($t->settings ?? [])['storefront'] ?? [])['pickup_reminder_days'] ?? 7));
    }

    /** Days since a pickup order was made ready, or null when it isn't waiting. */
    public static function waitingDays(TenantOrder $o): ?int
    {
        if ($o->fulfillment_type !== 'pickup' || $o->status !== TenantOrder::STATUS_FULFILLED) {
            return null;
        }
        $at = ((array) ($o->metadata ?? []))['ready_at'] ?? null;
        return $at ? (int) \Illuminate\Support\Carbon::parse($at)->diffInDays(now()) : null;
    }
}
