<?php

namespace App\Support\Launch;

use App\Models\Launch\LaunchChecklist;
use App\Models\Launch\LaunchStep;
use App\Models\Launch\TenantLaunchEvent;
use App\Models\Launch\TenantLaunchMark;
use App\Models\Launch\TenantLaunchState;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * MARKER-LAUNCH-CHECKLIST: where a shop is with the online store (ecom) and
 * rentals (rent), and the one place that answers "can a visitor see it" and
 * "can a visitor pay or book".
 *
 *   preview  only signed-in staff can see it; visitors get a coming-soon page
 *   live     anyone can see and use it
 *   paused   anyone can look; checkout or booking is closed until it is lifted
 *
 * The checklist only reads. Nothing here changes a shop's settings.
 */
class Launch
{
    public const TIERS = ['starter' => 0, 'branded' => 1, 'scale' => 2];

    private static array $memo = [];

    // ---------------------------------------------------------------- modules

    /** Modules this shop has, keyed to their label. */
    public static function modules(Tenant $t): array
    {
        $out = [];
        try { if ($t->online_store_enabled) $out['ecom'] = 'E-commerce'; } catch (\Throwable $e) { report($e); }
        try { if ($t->rentals_enabled) $out['rent'] = 'Rentals'; } catch (\Throwable $e) { report($e); }
        return $out;
    }

    public static function label(string $module): string
    {
        return LaunchDefaults::MODULES[$module] ?? $module;
    }

    /** "store" / "booking page": what a shop calls the public thing. */
    public static function thing(string $module): string
    {
        return $module === 'rent' ? 'booking page' : 'store';
    }

    public static function home(string $module): string
    {
        return $module === 'rent' ? route('tenant.launch.rentals') : route('tenant.storefront.settings', ['tab' => 'launch']);
    }

    // ---------------------------------------------------------------- state

    public static function state(Tenant $t, string $module): TenantLaunchState
    {
        $key = $t->id . ':' . $module;
        if (isset(self::$memo[$key])) return self::$memo[$key];
        try {
            $row = TenantLaunchState::firstOrNew(['tenant_id' => $t->id, 'module' => $module], ['state' => 'preview']);
        } catch (\Throwable $e) {
            // the table is missing (deploy order) or the database blinked: behave as before checklists
            report($e);
            $row = new TenantLaunchState(['tenant_id' => $t->id, 'module' => $module, 'state' => 'live']);
        }
        return self::$memo[$key] = $row;
    }

    public static function forgetMemo(): void
    {
        self::$memo = [];
    }

    /** off | preview | live | paused, as the public sees it. */
    public static function publicState(Tenant $t, string $module): string
    {
        if (! isset(self::modules($t)[$module])) return 'off';
        if ($module === 'ecom' && ! (bool) ((($t->settings['storefront'] ?? [])['enabled'] ?? true))) return 'off';
        if ($module === 'rent' && ! $t->rentals_visible) return 'off';
        $st = self::state($t, $module);
        if ($st->state === 'paused') return 'paused';
        if ($st->state === 'live') return 'live';
        return LaunchChecklist::behaviour($module)['hold_go_live'] ? 'preview' : 'live';
    }

    public static function staffViewing(): bool
    {
        try {
            return Auth::guard('tenant')->check();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Can whoever is looking see the store or booking page? */
    public static function visible(Tenant $t, string $module): bool
    {
        $s = self::publicState($t, $module);
        if ($s === 'off') return false;
        return $s !== 'preview' || self::staffViewing();
    }

    /** Shown in nav links, sitemaps and the Google feed: public only, never "because staff are signed in". */
    public static function publiclyListed(Tenant $t, string $module): bool
    {
        return in_array(self::publicState($t, $module), ['live', 'paused'], true);
    }

    /** Can whoever is looking pay (ecom) or book (rent)? */
    public static function canTransact(Tenant $t, string $module): bool
    {
        $s = self::publicState($t, $module);
        if ($s === 'live') return true;
        if ($s === 'preview') return self::staffViewing();
        return false;
    }

    public static function pausedNotice(string $module): string
    {
        return $module === 'rent'
            ? 'Online booking is paused for a moment. Please call or visit us to book.'
            : 'Checkout is paused for a moment. Your cart is saved; please try again soon or contact us.';
    }

    // ---------------------------------------------------------------- steps

    /** @return LaunchStep[] enabled steps in order */
    public static function steps(string $module): array
    {
        $key = 'steps:' . $module;
        if (isset(self::$memo[$key])) return self::$memo[$key];
        try {
            $rows = LaunchStep::where('module', $module)->where('enabled', true)->orderBy('sort')->get()->all();
        } catch (\Throwable $e) {
            report($e);
            $rows = [];
        }
        return self::$memo[$key] = $rows;
    }

    public static function tierRank(?string $tier): int
    {
        return self::TIERS[$tier ?? 'starter'] ?? 0;
    }

    /**
     * Every step with its status for this shop, and the totals.
     *
     * Row status: ok | warn | bad | na | todo (a step the shop confirms, not done)
     *             | confirmed | skipped | waived
     * $heavy = false skips the checks that walk every item (the sweep).
     * $save  = remember what was seen (last result, first pass times).
     */
    public static function evaluate(Tenant $t, string $module, bool $heavy = true, bool $save = true): array
    {
        $st = self::state($t, $module);
        $beh = LaunchChecklist::behaviour($module);
        $marks = [];
        try {
            $marks = TenantLaunchMark::where('tenant_id', $t->id)->where('module', $module)->get()->keyBy('step_key')->all();
        } catch (\Throwable $e) {
            report($e);
        }
        $rank = self::tierRank($t->plan_tier ?? 'starter');
        $isLive = in_array($st->state, ['live', 'paused'], true);

        $rows = [];
        $last = (array) ($st->last ?? []);
        foreach (self::steps($module) as $step) {
            if (self::tierRank($step->min_tier) > $rank) continue;
            $level = $step->level;
            $late = $isLive && $st->live_since && $step->created_at && $step->created_at->gt($st->live_since);
            if ($late && $level === 'req' && ! $beh['new_req_for_live']) $level = 'rec';

            $mark = $marks[$step->key] ?? null;
            if ($step->isConfirm()) {
                $res = ['s' => 'todo', 'line' => (string) $step->explain];
            } elseif (! $heavy && in_array($step->check, LaunchChecks::HEAVY, true)) {
                $res = ['s' => $last[$step->key] ?? 'warn', 'line' => '', 'stale' => true];
            } else {
                $res = LaunchChecks::run($step->check, $t);
            }
            if ($res['s'] === 'hide') continue;

            $status = $res['s'];
            if ($mark?->kind === 'waived') $status = 'waived';
            elseif ($mark?->kind === 'confirmed' && $step->isConfirm()) $status = 'confirmed';
            elseif ($mark?->kind === 'skipped' && $level === 'opt') $status = 'skipped';

            $rows[] = [
                'step' => $step, 'key' => $step->key, 'level' => $level, 'late' => $late,
                'status' => $status, 'raw' => $res['s'], 'line' => $res['line'], 'mark' => $mark,
                'fix' => $step->isConfirm() ? null : LaunchChecks::fixUrl($step->check, $t),
                'fix_label' => LaunchChecks::fixLabel($step->check),
                'help' => $step->help_key ? route('tenant.help.for', $step->help_key) : null,
            ];
        }

        $done = fn ($r) => in_array($r['status'], ['ok', 'na', 'confirmed', 'skipped', 'waived'], true);
        $reqLeft = array_values(array_filter($rows, fn ($r) => $r['level'] === 'req' && ! $done($r)));
        $broken = $isLive ? array_values(array_filter($reqLeft, fn ($r) => ! $r['step']->isConfirm() && $r['status'] === 'bad')) : [];

        if ($save) {
            self::remember($t, $st, $rows);
        }

        return [
            'module' => $module, 'state' => $st, 'rows' => $rows,
            'total' => count($rows), 'done' => count(array_filter($rows, $done)),
            'req_left' => $reqLeft, 'broken' => $broken,
            'can_go_live' => ! $reqLeft || ! $beh['hold_go_live'] || $st->forced_live,
            'public' => self::publicState($t, $module),
            'behaviour' => $beh,
        ];
    }

    /** Keep what the check saw, and the first time each step passed (for "where shops get stuck"). */
    private static function remember(Tenant $t, TenantLaunchState $st, array $rows): void
    {
        try {
            $last = (array) ($st->last ?? []);
            $passed = (array) ($st->passed_at ?? []);
            foreach ($rows as $r) {
                if (! empty($r['stale'])) continue;
                $last[$r['key']] = $r['status'];
                if (in_array($r['status'], ['ok', 'na', 'confirmed', 'skipped', 'waived'], true) && empty($passed[$r['key']])) {
                    $passed[$r['key']] = now()->toIso8601String();
                }
            }
            $st->last = $last;
            $st->passed_at = $passed;
            $st->checked_at = now();
            $st->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ---------------------------------------------------------------- shop actions

    public static function event(Tenant $t, string $module, string $message): void
    {
        try {
            TenantLaunchEvent::create(['tenant_id' => $t->id, 'module' => $module, 'message' => mb_strimwidth($message, 0, 500, '…'), 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return string|null why it could not go live */
    public static function goLive(Tenant $t, string $module, string $who): ?string
    {
        $ev = self::evaluate($t, $module);
        if (! $ev['can_go_live']) {
            return count($ev['req_left']) . ' required ' . (count($ev['req_left']) === 1 ? 'step is' : 'steps are') . ' left.';
        }
        $st = $ev['state'];
        $st->state = 'live';
        $st->live_since = now();
        $st->paused_at = null; $st->pause_step = null; $st->pause_reason = null;
        $st->broken = [];
        $st->save();
        self::event($t, $module, $who . ' went live. ' . ($ev['req_left'] ? 'Required steps were open (allowed by Intake).' : 'Required steps were all done.'));
        return null;
    }

    public static function takeOffline(Tenant $t, string $module, string $who): void
    {
        $st = self::state($t, $module);
        $st->state = 'preview';
        $st->paused_at = null; $st->pause_step = null; $st->pause_reason = null;
        $st->broken = [];
        $st->save();
        self::event($t, $module, $who . ' took the ' . self::thing($module) . ' offline.');
    }

    public static function mark(Tenant $t, string $module, string $stepKey, ?string $kind, string $who, ?string $reason = null): void
    {
        if ($kind === null) {
            $old = TenantLaunchMark::where('tenant_id', $t->id)->where('module', $module)->where('step_key', $stepKey)->first();
            if ($old) {
                $old->delete();
                $title = self::stepTitle($module, $stepKey);
                self::event($t, $module, $who . ' ' . match ($old->kind) { 'waived' => 'removed the waiver on', 'skipped' => 'turned back on', default => 'reset the tick on' } . ' "' . $title . '".');
            }
            return;
        }
        TenantLaunchMark::updateOrCreate(
            ['tenant_id' => $t->id, 'module' => $module, 'step_key' => $stepKey],
            ['kind' => $kind, 'reason' => $reason ? mb_strimwidth($reason, 0, 300, '…') : null, 'by_name' => $who]
        );
        $title = self::stepTitle($module, $stepKey);
        self::event($t, $module, $who . ' ' . match ($kind) { 'waived' => 'waived', 'skipped' => 'skipped', default => 'confirmed' } . ' "' . $title . '"' . ($reason ? ': ' . $reason : '') . '.');
    }

    public static function stepTitle(string $module, string $key): string
    {
        foreach (self::steps($module) as $s) if ($s->key === $key) return $s->title;
        return $key;
    }

    // ---------------------------------------------------------------- Intake actions

    public static function forceLive(Tenant $t, string $module, string $who, string $reason): void
    {
        $st = self::state($t, $module);
        $st->forced_live = true;
        $st->forced_reason = mb_strimwidth($reason, 0, 300, '…');
        $st->save();
        self::event($t, $module, $who . ' (Intake) let the shop go live with steps open: ' . $reason . '.');
    }

    public static function pauseByHand(Tenant $t, string $module, string $who, string $reason): void
    {
        $st = self::state($t, $module);
        if (! $st->exists) { $st->state = 'live'; $st->live_since = now(); }
        $st->state = 'paused';
        $st->paused_at = now();
        $st->pause_step = null;
        $st->pause_reason = mb_strimwidth($reason, 0, 300, '…');
        $st->save();
        self::event($t, $module, $who . ' (Intake) paused ' . ($module === 'rent' ? 'online booking' : 'checkout') . ': ' . $reason . '.');
        self::alertOwner($t, $module,
            ($module === 'rent' ? 'Online booking' : 'Checkout') . ' is paused on your ' . self::thing($module),
            'Intake paused ' . ($module === 'rent' ? 'online booking' : 'checkout') . ' on your ' . self::thing($module) . ".\n\nReason: " . $reason
            . "\n\nVisitors can still look; orders and bookings already made are not touched. Reply to this email if you have questions.");
    }

    public static function lift(Tenant $t, string $module, string $who): void
    {
        $st = self::state($t, $module);
        if ($st->state !== 'paused') return;
        $st->state = 'live';
        $st->paused_at = null; $st->pause_step = null; $st->pause_reason = null;
        $st->save();
        self::event($t, $module, $who . ' lifted the pause. ' . ($module === 'rent' ? 'Online booking' : 'Checkout') . ' is open again.');
    }

    // ---------------------------------------------------------------- sweep

    /**
     * Run by launch:sweep every 15 minutes. For live and paused shops, a
     * required step that was passing and has stopped raises the alarm (and
     * pauses when the step says so); a pause that came from a step lifts by
     * itself once that step passes again. Shops in preview get the reminder
     * emails. Returns counts for the command's output.
     */
    public static function sweep(?callable $say = null): array
    {
        $n = ['checked' => 0, 'broken' => 0, 'paused' => 0, 'lifted' => 0, 'reminded' => 0];
        Tenant::query()->orderBy('id')->chunk(50, function ($tenants) use (&$n) {
            foreach ($tenants as $t) {
                foreach (array_keys(self::modules($t)) as $module) {
                    try {
                        self::sweepOne($t, $module, $n);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
                self::forgetMemo();
            }
        });
        return $n;
    }

    private static function sweepOne(Tenant $t, string $module, array &$n): void
    {
        if (self::publicState($t, $module) === 'off') return;
        $st = self::state($t, $module);
        $beh = LaunchChecklist::behaviour($module);
        $before = (array) ($st->last ?? []);
        $hadBaseline = $st->checked_at !== null;
        $ev = self::evaluate($t, $module, false, true);
        $n['checked']++;

        if ($st->state === 'preview') {
            self::remind($t, $module, $st, $ev, $beh, $n);
            return;
        }

        $broken = (array) ($st->broken ?? []);
        $byKey = [];
        foreach ($ev['rows'] as $r) $byKey[$r['key']] = $r;

        // newly broken: required, automatic, failing now, and passing last time we looked
        foreach ($ev['broken'] as $r) {
            if (isset($broken[$r['key']])) continue;
            $wasOk = in_array($before[$r['key']] ?? null, ['ok', 'na', 'waived'], true);
            if (! $hadBaseline || ! $wasOk) continue;
            $broken[$r['key']] = now()->toIso8601String();
            $n['broken']++;
            $pause = $r['step']->pause_on_fail && $st->state === 'live';
            if ($pause) {
                $st->state = 'paused';
                $st->paused_at = now();
                $st->pause_step = $r['key'];
                $st->pause_reason = $r['step']->title . ' stopped passing';
                $n['paused']++;
            }
            self::event($t, $module, '"' . $r['step']->title . '" stopped passing: ' . $r['line']
                . ($pause ? ' ' . ($module === 'rent' ? 'Online booking' : 'Checkout') . ' paused.' : '')
                . ($beh['email_owner'] ? ' Owner emailed.' : '') . ($beh['add_issue'] ? ' Added to Issues.' : ''));
            self::raise($t, $module, $r, $pause, $beh);
        }

        // fixed again
        foreach (array_keys($broken) as $key) {
            $r = $byKey[$key] ?? null;
            if ($r && in_array($r['status'], ['bad'], true)) continue;
            unset($broken[$key]);
            $title = $r ? $r['step']->title : self::stepTitle($module, $key);
            $lifted = $st->state === 'paused' && $st->pause_step === $key;
            if ($lifted) {
                $st->state = 'live';
                $st->paused_at = null; $st->pause_step = null; $st->pause_reason = null;
                $n['lifted']++;
            }
            self::event($t, $module, '"' . $title . '" is passing again.' . ($lifted ? ' ' . ($module === 'rent' ? 'Online booking' : 'Checkout') . ' reopened.' : ''));
            if ($lifted) {
                if ($beh['email_owner']) self::alertOwner($t, $module, ($module === 'rent' ? 'Online booking' : 'Checkout') . ' is open again',
                    '"' . $title . '" is passing again, so ' . ($module === 'rent' ? 'online booking' : 'checkout') . ' on your ' . self::thing($module) . ' reopened by itself. Nothing else to do.');
                self::alertIntake($t, $module, 'Resolved: ' . $t->name . ' · ' . $title, '"' . $title . '" is passing again and the pause lifted itself.', false);
            }
        }

        $st->broken = $broken;
        $st->save();
    }

    /** One email at remind_days in preview, one more at twice that. Never more. */
    private static function remind(Tenant $t, string $module, TenantLaunchState $st, array $ev, array $beh, array &$n): void
    {
        $days = max(1, (int) $beh['remind_days']);
        if (! $st->created_at || $st->reminders >= 2 || ! $ev['req_left']) return;
        $age = (int) $st->created_at->diffInDays(now());
        $due = $st->reminders === 0 ? $days : $days * 2;
        if ($age < $due) return;
        self::sendNudge($t, $module, $ev, 'Automatic reminder');
        $st->reminders = $st->reminders + 1;
        $st->reminded_at = now();
        $st->save();
        $n['reminded']++;
    }

    public static function sendNudge(Tenant $t, string $module, array $ev, string $by): void
    {
        $left = array_map(fn ($r) => '  - ' . $r['step']->title, $ev['req_left']);
        self::alertOwner($t, $module, 'Your ' . self::thing($module) . ' is almost ready',
            'Your ' . strtolower(self::label($module)) . ' ' . self::thing($module) . ' is still in preview, so visitors cannot see it yet. '
            . (count($left) === 1 ? 'One required step is left:' : count($left) . ' required steps are left:') . "\n\n" . implode("\n", $left)
            . "\n\nOpen the checklist: " . self::home($module));
        self::event($t, $module, $by . ': reminder emailed to the owner (' . count($left) . ' required left).');
    }

    // ---------------------------------------------------------------- alerts

    /** A live required step stopped passing: the owner, Intake's Issues and Intake's alert address. */
    private static function raise(Tenant $t, string $module, array $r, bool $paused, array $beh): void
    {
        $what = ($module === 'rent' ? 'Online booking' : 'Checkout');
        $title = $r['step']->title;
        if ($beh['email_owner']) {
            self::alertOwner($t, $module,
                ($paused ? 'Action needed: ' . strtolower($what) . ' is paused' : 'Action needed: ' . $title),
                '"' . $title . '" stopped passing on your ' . self::thing($module) . ': ' . $r['line'] . "\n\n"
                . ($paused ? $what . " is paused until it passes again, so no customer is charged or booked by mistake. Visitors can still look, and orders and bookings already made are not touched. It reopens by itself once this is fixed.\n\n" : '')
                . 'Fix it here: ' . ($r['fix'] ?: self::home($module)));
        }
        $subject = ($paused ? 'PAUSED ' . strtolower($what) . ': ' : 'Broken: ') . $t->name . ' · ' . $title;
        $body = $t->name . ' (' . self::label($module) . '): "' . $title . '" stopped passing. ' . $r['line']
            . ($paused ? "\n\n" . $what . ' was paused automatically.' : '')
            . "\n\nOpen the shop: " . url('/admin/launch-checklists?view=shop&tenant=' . $t->id . '&module=' . $module);
        if ($beh['add_issue']) {
            \App\Support\PlatformInbox::alert(['tenant_id' => $t->id, 'subject' => $subject, 'body' => $body,
                'ref_id' => 'LAUNCH-' . strtoupper(substr(md5($t->id . $module . $r['key'] . now()->toDateString()), 0, 8)),
                'meta' => ['launch' => true, 'module' => $module, 'step' => $r['key'], 'paused' => $paused]]);
        }
        if ($beh['email_intake']) self::alertIntake($t, $module, $subject, $body, true);
    }

    public static function alertOwner(Tenant $t, string $module, string $subject, string $body): void
    {
        try {
            $to = $t->owner?->email ?: ($t->email ?? null);
            if (! $to) return;
            Mail::raw($body . "\n\n— Intake, for " . $t->name, fn ($m) => $m->to($to)->subject('[' . $t->name . '] ' . $subject));
        } catch (\Throwable $e) {
            Log::warning('launch.owner_email_failed', ['tenant' => $t->id, 'error' => $e->getMessage()]);
        }
    }

    private static function alertIntake(Tenant $t, string $module, string $subject, string $body, bool $dedupe): void
    {
        try {
            $to = \App\Support\PlatformInbox::notifyAddress();
            if (! $to) return;
            if ($dedupe && ! Cache::add('launch:alert:' . md5($t->id . $module . $subject), 1, 3600)) return;
            Mail::raw($body, fn ($m) => $m->to($to)->subject('[Intake] ' . $subject));
        } catch (\Throwable $e) {
            Log::warning('launch.intake_email_failed', ['tenant' => $t->id, 'error' => $e->getMessage()]);
        }
    }

    // ---------------------------------------------------------------- summaries for banners

    /** What the tenant banner and dashboard nudge need, cheap: from the stored last check only. */
    public static function attention(Tenant $t): array
    {
        $out = [];
        foreach (self::modules($t) as $module => $label) {
            $pub = self::publicState($t, $module);
            if ($pub === 'off') continue;
            $st = self::state($t, $module);
            $beh = LaunchChecklist::behaviour($module);
            $last = (array) ($st->last ?? []);
            $broken = array_keys((array) ($st->broken ?? []));
            $reqOpen = [];
            foreach (self::steps($module) as $s) {
                if ($s->level !== 'req' || self::tierRank($s->min_tier) > self::tierRank($t->plan_tier ?? 'starter')) continue;
                $v = $last[$s->key] ?? null;
                if ($v === null || ! in_array($v, ['ok', 'na', 'confirmed', 'skipped', 'waived'], true)) $reqOpen[] = $s->title;
            }
            $out[$module] = [
                'label' => $label, 'public' => $pub, 'state' => $st, 'nudge' => (bool) $beh['nudge'],
                'broken' => array_map(fn ($k) => self::stepTitle($module, $k), $broken),
                'req_open' => $reqOpen, 'checked' => $st->checked_at !== null, 'home' => self::home($module),
            ];
        }
        return $out;
    }
}
