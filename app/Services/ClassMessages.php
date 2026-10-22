<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantClassRegistration;
use App\Models\Tenant\TenantClassSession;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantCustomerMembership;
use App\Models\Tenant\TenantCustomerPack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Emails to class customers. Each one can be switched off and reworded in
 * Communication (notify_<key>_email, template <key>). Sends wait until the
 * booking's transaction commits, so a rolled-back booking never emails, and
 * a failed send never breaks a booking.
 *
 * MARKER-CLASSES-P5
 */
class ClassMessages
{
    public const KEYS = [
        'class_booking_confirmation', 'class_reminder', 'class_waitlist_moved_in',
        'class_cancelled', 'class_late_cancel', 'class_pass_reminder',
        'class_membership_renewed', 'class_membership_payment_due', 'class_purchase_receipt',
        'class_changed', // MARKER-CLASSES-P7
    ];

    /** The switches on Classes › Settings, in order: key => [label, description]. MARKER-CLASSES-P8 */
    public const LABELS = [
        'class_booking_confirmation'   => ['Class booked', 'With the class notes and an add-to-calendar link'],
        'class_reminder'               => ['Reminder', 'Before class'],
        'class_waitlist_moved_in'      => ['Moved in from the waitlist', 'Straight away'],
        'class_changed'                => ['Class time changed', 'When you move a class people are booked into'],
        'class_cancelled'              => ['Class cancelled by the studio', 'Says what happened to their credit or payment'],
        'class_late_cancel'            => ['Late cancel', 'Says the rule and how to ask for it to be waived'],
        'class_pass_reminder'          => ['Pass running low or expiring', 'At 1 class left, and 7 days before a pack expires'],
        'class_membership_renewed'     => ['Membership renewed', 'The receipt for an automatic renewal'],
        'class_membership_payment_due' => ['Renewal failed or due', 'Card declined, with the retry date, or due at the studio'],
        'class_purchase_receipt'       => ['Pass or class bought online', 'The receipt'],
    ];

    private static int $quiet = 0;

    /** Run $fn without booking confirmations (the online receipt already covers it). */
    public static function quietly(callable $fn): mixed
    {
        self::$quiet++;
        try {
            return $fn();
        } finally {
            self::$quiet--;
        }
    }

    // ------------------------------------------------------------------
    // Class messages
    // ------------------------------------------------------------------

    public static function booked(TenantClassRegistration $r): void
    {
        if (self::$quiet > 0) {
            return;
        }
        self::forRegistration('class_booking_confirmation', $r);
    }

    public static function reminder(TenantClassRegistration $r): void
    {
        self::forRegistration('class_reminder', $r);
    }

    public static function movedIn(TenantClassRegistration $r): void
    {
        self::forRegistration('class_waitlist_moved_in', $r);
    }

    /** The class moved to a new time (MARKER-CLASSES-P7). */
    public static function changed(TenantClassRegistration $r, $oldStart): void
    {
        $tenant = Tenant::find($r->tenant_id);
        if ($tenant) {
            self::forRegistration('class_changed', $r, ['change_line' => e('It was ' . self::local($tenant, $oldStart, 'l, M j') . ' at ' . self::local($tenant, $oldStart, 'g:i A') . '.')]);
        }
    }

    public static function studioCancelled(TenantClassRegistration $r, string $creditLine): void
    {
        self::forRegistration('class_cancelled', $r, ['credit_line' => e($creditLine)]);
    }

    public static function lateCancel(TenantClassRegistration $r, ?int $hours): void
    {
        self::forRegistration('class_late_cancel', $r, [
            'rule_line' => e($hours ? "Classes can be cancelled free up to {$hours} hour" . ($hours === 1 ? '' : 's') . ' before they start.' : 'This class was cancelled after the free-cancel window.'),
        ]);
    }

    /** $why: 'low' (1 credit left) or 'expiring' (7 days left). Sent once per pack each. */
    public static function packReminder(TenantCustomerPack $p, string $why): void
    {
        $meta = (array) ($p->metadata ?? []);
        $flag = $why . '_reminded_at';
        if (! empty($meta[$flag])) {
            return;
        }
        $p->forceFill(['metadata' => $meta + [$flag => now()->toIso8601String()]])->save();

        $tenant   = Tenant::find($p->tenant_id);
        $customer = TenantCustomer::find($p->customer_id);
        if (! $tenant || ! $customer) {
            return;
        }
        $name  = $p->product?->name ?? 'class pack';
        $until = $p->expires_at ? self::local($tenant, $p->expires_at, 'M j') : null;
        $line  = $why === 'low'
            ? "You have 1 class left on your {$name}" . ($until ? " (use it by {$until})" : '') . '.'
            : "Your {$name} has {$p->credits_remaining} class" . ($p->credits_remaining === 1 ? '' : 'es') . " left and expires {$until}.";
        self::queue($tenant, 'class_pass_reminder', $customer, [
            'pass_line'  => e($line),
            'passes_url' => $tenant->urlTo('/classes/passes'),
        ]);
    }

    // ------------------------------------------------------------------
    // Membership billing
    // ------------------------------------------------------------------

    public static function membershipRenewed(TenantCustomerMembership $m, int $amountCents): void
    {
        [$tenant, $customer] = self::owner($m);
        if (! $tenant) {
            return;
        }
        $m->refresh();
        self::queue($tenant, 'class_membership_renewed', $customer, [
            'pass_name'  => e($m->product?->name ?? 'Membership'),
            'amount'     => e(format_money($amountCents)),
            'card_line'  => e($m->card_last4 ? ucfirst((string) ($m->card_brand ?: 'card')) . ' ending ' . $m->card_last4 : 'Your saved card'),
            'next_date'  => e($m->current_period_end ? self::localDate($m->current_period_end->copy()->addDay(), 'M j, Y') : ''),
            'passes_url' => $tenant->urlTo('/account/passes'),
        ]);
    }

    /** A card renewal that failed, or a desk renewal that is due. */
    public static function membershipDue(TenantCustomerMembership $m): void
    {
        [$tenant, $customer] = self::owner($m);
        if (! $tenant) {
            return;
        }
        $m->refresh();
        $retry = $m->next_retry_at ? self::local($tenant, $m->next_retry_at, 'M j') : null;
        $line  = $m->billing_mode === 'card'
            ? "We couldn't charge your card for your renewal." . ($retry ? " We'll try again {$retry}." : '') . ' You can update your card from your account.'
            : 'Your renewal is due. Please pay at the studio' . ($retry ? " by {$retry}" : '') . ' to keep booking.';
        self::queue($tenant, 'class_membership_payment_due', $customer, [
            'pass_name'    => e($m->product?->name ?? 'Membership'),
            'payment_line' => e($line),
            'amount'       => e(format_money((int) ($m->renewal_price_cents ?? 0))),
            'passes_url'   => $tenant->urlTo('/account/passes'),
        ]);
    }

    // ------------------------------------------------------------------
    // Shared
    // ------------------------------------------------------------------

    /** Sample values for Communication's test send. */
    public static function sampleVars(Tenant $tenant): array
    {
        $when = now()->addDay()->setTime(17, 30);
        return [
            'class_name'   => 'Power Flow 60',
            'class_when'   => $when->format('l, M j') . ' at ' . $when->format('g:i A'),
            'details'      => self::table([['Class', 'Power Flow 60'], ['When', $when->format('D M j, g:i A')], ['With', 'Dana K.']]),
            'notes'        => '<p style="font-size:13px;color:#555">Bring water and a mat if you have one.</p>',
            'calendar_url' => $tenant->urlTo('/classes'),
            'class_url'    => $tenant->urlTo('/classes'),
            'schedule_url' => $tenant->urlTo('/classes'),
            'passes_url'   => $tenant->urlTo('/classes/passes'),
            'account_url'  => $tenant->urlTo('/account'),
            'credit_line'  => 'Your class credit has been returned to your pack.',
            'change_line'  => 'It was ' . $when->copy()->subHour()->format('l, M j') . ' at ' . $when->copy()->subHour()->format('g:i A') . '.',
            'rule_line'    => 'Classes can be cancelled free up to 12 hours before they start.',
            'pass_line'    => 'You have 1 class left on your 10-class pack (use it by ' . now()->addDays(20)->format('M j') . ').',
            'pass_name'    => 'Unlimited',
            'amount'       => '$139.00',
            'card_line'    => 'Visa ending 4242',
            'next_date'    => now()->addMonth()->format('M j, Y'),
            'payment_line' => "We couldn't charge your card for your renewal. We'll try again " . now()->addDays(3)->format('M j') . '. You can update your card from your account.',
            'account_line' => '<p><a href="' . e($tenant->urlTo('/account/passes')) . '" style="color:#111">See your passes</a></p>',
        ];
    }

    private static function forRegistration(string $key, TenantClassRegistration $r, array $extra = []): void
    {
        $tenant   = Tenant::find($r->tenant_id);
        $customer = TenantCustomer::find($r->customer_id);
        $session  = TenantClassSession::with('template')->find($r->class_session_id);
        if (! $tenant || ! $customer || ! $session) {
            return;
        }
        $tpl  = $session->template;
        $rows = [['Class', $tpl?->name ?? 'Class'], ['When', self::local($tenant, $session->starts_at, 'D M j, g:i A')]];
        if ($session->instructor_snapshot) {
            $rows[] = ['With', $session->instructor_snapshot];
        }
        $paid = match ($r->payment_method) {
            'membership' => 'Membership',
            'pack'       => 'Class pack',
            'online'     => 'Paid online',
            'cash'       => 'Paid at the studio',
            default      => 'Pay at the studio',
        };
        if ($key !== 'class_cancelled') {
            $rows[] = ['Paid with', $paid];
        }
        $notes = trim(implode("\n\n", array_filter([(string) ($tpl?->class_notes ?? ''), (string) ($session->session_notes_override ?? '')])));

        self::queue($tenant, $key, $customer, $extra + [
            'class_name'   => e($tpl?->name ?? 'Class'),
            'class_when'   => e(self::local($tenant, $session->starts_at, 'l, M j') . ' at ' . self::local($tenant, $session->starts_at, 'g:i A')),
            'details'      => self::table($rows),
            'notes'        => $notes !== '' ? '<p style="font-size:13px;color:#555">' . nl2br(e($notes)) . '</p>' : '',
            'calendar_url' => $tenant->urlTo('/classes/' . $session->id . '/calendar.ics'),
            'class_url'    => $tenant->urlTo('/classes/' . $session->id),
            'schedule_url' => $tenant->urlTo('/classes'),
            'account_url'  => $tenant->urlTo('/account'),
        ]);
    }

    private static function queue(Tenant $tenant, string $key, ?TenantCustomer $customer, array $vars): void
    {
        if (! $customer || ! filled($customer->email) || ! $tenant->notificationEnabled($key . '_email')) {
            return;
        }
        $vars += ['first_name' => e($customer->first_name ?: 'there'), 'shop_name' => e($tenant->name)];
        $email = $customer->email;
        DB::afterCommit(function () use ($tenant, $key, $email, $vars) {
            try {
                (new EmailService($tenant))->send($key, $email, $vars);
            } catch (\Throwable $e) {
                Log::warning('classes: message not sent', ['key' => $key, 'tenant' => $tenant->id, 'error' => $e->getMessage()]);
            }
        });
    }

    private static function owner(TenantCustomerMembership $m): array
    {
        $tenant = Tenant::find($m->tenant_id);
        return [$tenant, $tenant ? TenantCustomer::where('tenant_id', $tenant->id)->find($m->customer_id) : null];
    }

    private static function local(Tenant $tenant, $instant, string $fmt): string
    {
        return $instant->copy()->setTimezone($tenant->timezone())->format($fmt);
    }

    /** Dates stored as studio-local calendar days (period ends) are formatted as is. */
    private static function localDate($date, string $fmt): string
    {
        return $date->format($fmt);
    }

    private static function table(array $rows): string
    {
        return '<table style="font-size:14px;line-height:1.9;margin:10px 0">' . collect($rows)->map(fn ($r) =>
            '<tr><td style="color:#666;padding-right:16px">' . e($r[0]) . '</td><td><strong>' . e($r[1]) . '</strong></td></tr>'
        )->implode('') . '</table>';
    }
}
