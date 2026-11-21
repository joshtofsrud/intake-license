<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantOrder;
use App\Support\JobFailureReporter;
use App\Support\OrderSteps;
use Illuminate\Console\Command;

/**
 * MARKER-ORDER-STEPS: pickup orders that have sat ready longer than the shop allows
 * (E-commerce > Fulfillment) get one reminder email. The Orders list flags them either way.
 */
class OrdersPickupRemindersCommand extends Command
{
    protected $signature = 'orders:pickup-reminders';
    protected $description = 'Remind customers about pickup orders that are ready and not collected';

    public function handle(): int
    {
        $n = 0;
        try {
            $tids = TenantOrder::query()->where('status', TenantOrder::STATUS_FULFILLED)->where('fulfillment_type', 'pickup')->distinct()->pluck('tenant_id');
            foreach ($tids as $tid) {
                $tenant = Tenant::find($tid);
                $days = $tenant ? OrderSteps::reminderDays($tenant) : 0;
                if (! $tenant || $days < 1) continue;
                $cfg = \App\Services\Tenant\OrderService::forTenant($tenant)->config();
                $orders = TenantOrder::query()->where('tenant_id', $tid)->where('status', TenantOrder::STATUS_FULFILLED)
                    ->where('fulfillment_type', 'pickup')->with('items')->get();
                foreach ($orders as $o) {
                    $m = (array) ($o->metadata ?? []);
                    $waited = OrderSteps::waitingDays($o);
                    if ($waited === null || $waited < $days || ! empty($m['reminded_at']) || ! filled($o->contact_email)) continue;
                    try {
                        $rows = $o->items->map(fn ($i) => "<tr><td style='padding:3px 14px 3px 0'>" . e($i->name_snapshot) . ' ×' . (int) $i->quantity . '</td></tr>')->implode('');
                        (new \App\Services\EmailService($tenant))->send('order_pickup_reminder', $o->contact_email, [
                            'first_name' => $o->contact_first_name ?: 'there', 'order_number' => $o->order_number, 'shop_name' => $tenant->name,
                            'items_rows' => "<table style='font-size:14px'>{$rows}</table>",
                            'pickup_line' => e($cfg['pickup_instructions'] !== '' ? $cfg['pickup_instructions'] : 'Bring your name or order number to the counter.'),
                            'ready_date' => \Illuminate\Support\Carbon::parse($m['ready_at'])->setTimezone($tenant->timezone())->format('l, M j'),
                            'order_url' => rtrim($tenant->publicUrl(), '/') . '/order/' . $o->token,
                        ]);
                        $m['reminded_at'] = now()->toIso8601String();
                        $o->forceFill(['metadata' => $m])->save();
                        OrderSteps::record($o, 'Reminder sent', null, 'waiting ' . $waited . ' days');
                        $n++;
                    } catch (\Throwable $e) {
                        JobFailureReporter::report(self::class, 'Pickup reminder failed for ' . $o->order_number, $e, ['order' => $o->id], $tid);
                    }
                }
            }
        } catch (\Throwable $e) {
            JobFailureReporter::report(self::class, 'Pickup reminders failed', $e);
            return self::FAILURE;
        }
        $this->info("Sent {$n} pickup reminders.");
        return self::SUCCESS;
    }
}
