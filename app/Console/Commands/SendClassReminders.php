<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantClassRegistration;
use App\Models\Tenant\TenantClassSession;
use App\Models\Tenant\TenantCustomerPack;
use App\Services\ClassMessages;
use App\Services\ClassRules;
use Illuminate\Console\Command;

/**
 * Class reminders (the studio's "hours before", default 2) and the
 * "pass expires in 7 days" notice. Each is sent once: the registration or
 * pack is marked before its email goes out. MARKER-CLASSES-P5
 */
class SendClassReminders extends Command
{
    protected $signature   = 'classes:send-reminders';
    protected $description = 'Email class reminders and pass-expiry notices';

    public function handle(): int
    {
        $sent = 0;
        $sessions = TenantClassSession::whereIn('status', ['scheduled', 'confirmed'])
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHours(ClassRules::MAX_REMINDER_HOURS))
            ->get()->groupBy('tenant_id');

        foreach ($sessions as $tenantId => $list) {
            $tenant = Tenant::find($tenantId);
            if (! $tenant || ! $tenant->classes_active || ! $tenant->notificationEnabled('class_reminder_email')) {
                continue;
            }
            $hours = ClassRules::for($tenant)->get('reminder_hours');
            foreach ($list as $s) {
                if ($s->starts_at->gt(now()->addHours($hours))) {
                    continue;
                }
                $regs = TenantClassRegistration::where('class_session_id', $s->id)->where('status', 'registered')->get();
                foreach ($regs as $r) {
                    $meta = (array) ($r->metadata ?? []);
                    if (! empty($meta['reminded_at'])) {
                        continue;
                    }
                    $r->forceFill(['metadata' => $meta + ['reminded_at' => now()->toIso8601String()]])->save();
                    ClassMessages::reminder($r);
                    $sent++;
                }
            }
        }

        $packs = TenantCustomerPack::where('status', 'active')
            ->where('credits_remaining', '>', 0)
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays(7))
            ->with('product')->get();
        foreach ($packs as $p) {
            $tenant = Tenant::find($p->tenant_id);
            if (! $tenant || ! $tenant->classes_active || ! empty(((array) $p->metadata)['expiring_reminded_at'])) {
                continue;
            }
            ClassMessages::packReminder($p, 'expiring');
            $sent++;
        }

        $this->info("Sent {$sent}.");
        return self::SUCCESS;
    }
}
