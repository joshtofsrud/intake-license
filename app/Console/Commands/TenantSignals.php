<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\PlatformInbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-TENANT-SIGNALS — raise an alert when a tenant crosses a line.
 *
 * Every signal is evaluated fresh each run and compared against the stored
 * state. Only an off→on transition raises anything. Staying on is silent;
 * going off is silent but re-arms the signal.
 */
class TenantSignals extends Command
{
    protected $signature = 'tenants:signals
                            {--dry-run : Print what would be raised, write nothing}
                            {--tenant= : Limit to one tenant id}';

    protected $description = 'Evaluate tenant signals and raise once-per-crossing alerts into the inbox.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $tenants = Tenant::query()
            ->where('is_platform', false)
            ->where('is_demo', false)
            ->when($this->option('tenant'), fn ($q, $id) => $q->where('id', $id))
            ->get(['id', 'name', 'created_at', 'trial_ends_at', 'subscription_status', 'is_active']);

        $raised = 0;

        foreach ($tenants as $t) {
            foreach ($this->evaluate($t) as $signal => [$on, $subject, $body, $detail]) {
                $prev = DB::table('tenant_signal_states')
                    ->where('tenant_id', $t->id)->where('signal', $signal)->first();
                $was = $prev ? (bool) $prev->active : false;

                if ($on && ! $was) {
                    $raised++;
                    $this->line(sprintf('  %-28s %-16s %s', mb_strimwidth($t->name, 0, 28), $signal, $subject));
                    if (! $dry) {
                        PlatformInbox::alert([
                            'tenant_id' => $t->id,
                            'subject'   => $t->name . ': ' . $subject,
                            'body'      => $body . "\n\nOpen the tenant: " . url('/admin/tenants/' . $t->id . '/edit'),
                            'meta'      => ['signal' => $signal] + $detail,
                        ]);
                    }
                }

                if (! $dry && ($prev === null || $was !== $on)) {
                    DB::table('tenant_signal_states')->updateOrInsert(
                        ['tenant_id' => $t->id, 'signal' => $signal],
                        ['active' => $on, 'changed_at' => now(), 'detail' => json_encode($detail),
                         'updated_at' => now(), 'created_at' => $prev->created_at ?? now()]
                    );
                }
            }
        }

        $this->info(($dry ? 'Would raise ' : 'Raised ') . $raised . ' alert' . ($raised === 1 ? '' : 's') . ' across ' . $tenants->count() . ' tenants.');
        return self::SUCCESS;
    }

    /** @return array<string, array{0:bool,1:string,2:string,3:array}> signal => [on, subject, body, detail] */
    protected function evaluate(Tenant $t): array
    {
        $out = [];

        // new_tenant — an account that appeared since the last run. "On" for
        // 24h from creation, so it fires exactly once and then re-arms; it
        // can never fire again for the same tenant since created_at is fixed.
        $isNew = $t->created_at && $t->created_at->gt(now()->subDay());
        $out['new_tenant'] = [
            $isNew,
            'new tenant',
            'Created ' . optional($t->created_at)->diffForHumans() . '.',
            ['created_at' => optional($t->created_at)->toDateTimeString()],
        ];

        // trial_ending — within 3 days, still in the future.
        $days = $t->trial_ends_at ? now()->diffInDays($t->trial_ends_at, false) : null;
        $out['trial_ending'] = [
            $days !== null && $days >= 0 && $days <= 3 && ($t->subscription_status ?? 'trialing') !== 'active',
            'trial ends ' . ($days === 0 ? 'today' : 'in ' . $days . ' day' . ($days === 1 ? '' : 's')),
            'Trial ends ' . optional($t->trial_ends_at)->format('D M j') . '. No active subscription on file.',
            ['trial_ends_at' => optional($t->trial_ends_at)->toDateString()],
        ];

        // no_login_14d — someone HAD logged in, and nobody has for 14 days.
        $lastLogin = DB::table('tenant_users')->where('tenant_id', $t->id)->max('last_login_at');
        $out['no_login_14d'] = [
            $lastLogin !== null && now()->diffInDays($lastLogin) >= 14,
            'no staff login in ' . ($lastLogin ? now()->diffInDays($lastLogin) : 0) . ' days',
            'Last staff login was ' . ($lastLogin ? \Carbon\Carbon::parse($lastLogin)->diffForHumans() : 'never') . '.',
            ['last_login_at' => $lastLogin],
        ];

        // no_sales_7d — a shop that rang sales in the 30 days before the last
        // 7, and none since. A shop that never sells is not going quiet.
        $lastSale = DB::table('tenant_sales')->where('tenant_id', $t->id)
            ->whereNotNull('sale_number')->where('status', '!=', 'cancelled')->max('sale_date');
        $hadSales = DB::table('tenant_sales')->where('tenant_id', $t->id)
            ->whereNotNull('sale_number')->where('status', '!=', 'cancelled')
            ->whereBetween('sale_date', [now()->subDays(37)->toDateString(), now()->subDays(7)->toDateString()])
            ->exists();
        $quiet = $hadSales && ($lastSale === null || \Carbon\Carbon::parse($lastSale)->lt(now()->subDays(7)));
        $out['no_sales_7d'] = [
            $quiet,
            'no sales in ' . ($lastSale ? now()->diffInDays($lastSale) : 7) . ' days',
            'Last finalised sale was ' . ($lastSale ? \Carbon\Carbon::parse($lastSale)->format('D M j') : 'unknown') . ', after regular sales in the month before.',
            ['last_sale_date' => $lastSale],
        ];

        // payment_failed — Stripe told us the subscription is past due.
        $out['payment_failed'] = [
            ($t->subscription_status ?? '') === 'past_due',
            'subscription payment failed',
            'Stripe reports the subscription as past due.',
            ['subscription_status' => $t->subscription_status],
        ];

        return $out;
    }
}
