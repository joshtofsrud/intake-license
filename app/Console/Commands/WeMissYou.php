<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantCustomerMembership;
use App\Services\ClassMessages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * "We miss you": a member with no visit in 21 days and nothing booked.
 * Off until the studio turns it on (Classes › Settings › Messages). At most
 * once a month per member. MARKER-CLASSES-P11
 */
class WeMissYou extends Command
{
    protected $signature   = 'classes:we-miss-you';
    protected $description = 'Email members who have not been to class in 21 days';

    public function handle(): int
    {
        $sent = 0;
        $members = TenantCustomerMembership::where('status', 'active')
            ->where('created_at', '<=', now()->subDays(21))
            ->with('product')->get()->groupBy('tenant_id');

        foreach ($members as $tenantId => $list) {
            $tenant = Tenant::find($tenantId);
            if (! $tenant || ! $tenant->classes_active || ! $tenant->notificationEnabled('class_we_miss_you_email')) {
                continue;
            }
            foreach ($list->unique('customer_id') as $m) {
                $meta = (array) ($m->metadata ?? []);
                if (! empty($meta['missed_emailed_at']) && now()->subDays(30)->lt($meta['missed_emailed_at'])) {
                    continue;
                }
                $q = fn () => DB::table('tenant_class_registrations as r')
                    ->join('tenant_class_sessions as s', 's.id', '=', 'r.class_session_id')
                    ->where('r.tenant_id', $tenantId)->where('r.customer_id', $m->customer_id);
                $recent   = $q()->whereIn('r.status', ['registered', 'checked_in'])->whereBetween('s.starts_at', [now()->subDays(21), now()])->exists();
                $upcoming = $q()->whereIn('r.status', ['registered', 'waitlisted'])->where('s.starts_at', '>', now())->exists();
                if ($recent || $upcoming) {
                    continue;
                }
                $lastAt = $q()->whereIn('r.status', ['registered', 'checked_in'])->where('s.starts_at', '<', now())->max('s.starts_at');

                $m->forceFill(['metadata' => array_merge($meta, ['missed_emailed_at' => now()->toIso8601String()])])->save();
                ClassMessages::weMissYou($m, $lastAt);
                $sent++;
            }
        }
        $this->info("Sent {$sent}.");
        return self::SUCCESS;
    }
}
