<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantCustomerSignal;
use Illuminate\Support\Collection;

/**
 * Finds regulars who are overdue against THEIR OWN visit rhythm (not a fixed
 * number), and attaches the "likely why" from any recent quality signal.
 *
 * Cadence = average gap between a customer's completed visits. A customer is
 * at-risk when days-since-last-visit exceeds their average gap × buffer, once
 * they have enough visits to trust the rhythm.
 */
class AtRiskCustomerService
{
    private const DONE = ['completed', 'shipped', 'closed'];

    public function forTenant(string $tenantId, array $opts = []): Collection
    {
        $settings  = Tenant::find($tenantId)?->settings ?? [];
        $buffer    = (float) ($opts['buffer'] ?? ($settings['recovery_overdue_buffer'] ?? 1.5));
        $minVisits = (int)   ($opts['min_visits'] ?? ($settings['recovery_min_visits'] ?? 3));
        $today     = tnow()->startOfDay();

        $visitsByCustomer = TenantAppointment::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('customer_id')
            ->whereIn('status', self::DONE)
            ->whereNotNull('appointment_date')
            ->where('appointment_date', '>=', $today->copy()->subMonths(18))
            ->orderBy('appointment_date')
            ->get(['id', 'customer_id', 'appointment_date'])
            ->groupBy('customer_id');

        $atRisk = collect();

        foreach ($visitsByCustomer as $customerId => $visits) {
            if ($visits->count() < $minVisits) {
                continue;
            }

            $dates = $visits->pluck('appointment_date')->values();
            $gaps  = [];
            for ($i = 1; $i < $dates->count(); $i++) {
                $g = $dates[$i - 1]->diffInDays($dates[$i]);
                if ($g > 0) {
                    $gaps[] = $g;
                }
            }
            if (empty($gaps)) {
                continue;
            }

            $avgGap    = array_sum($gaps) / count($gaps);
            $last      = $dates->last();
            $daysSince = $last->diffInDays($today);

            if ($avgGap <= 0 || $daysSince <= $avgGap * $buffer) {
                continue; // still within their normal rhythm
            }

            $signal = TenantCustomerSignal::where('tenant_id', $tenantId)
                ->where('customer_id', $customerId)
                ->orderByDesc('occurred_at')
                ->first();

            $atRisk->push([
                'customer_id'  => $customerId,
                'avg_gap_days' => (int) round($avgGap),
                'days_since'   => (int) $daysSince,
                'last_visit'   => $last,
                'visit_count'  => $visits->count(),
                'reason'       => $signal ? $this->reasonLabel($signal) : null,
                'reason_type'  => $signal?->type,
            ]);
        }

        // Attach customer name/phone.
        $customers = TenantCustomer::whereIn('id', $atRisk->pluck('customer_id'))->get()->keyBy('id');
        $atRisk = $atRisk->map(function ($c) use ($customers) {
            $cust = $customers[$c['customer_id']] ?? null;
            $c['name']  = $cust ? trim(($cust->first_name ?? '') . ' ' . ($cust->last_name ?? '')) : 'Customer';
            $c['phone'] = $cust->phone ?? null;
            return $c;
        });

        // MARKER-PATCH-507 — flagged-first is now a setting (default on).
        $prioritize = (bool) ($settings['recovery_prioritize_flagged'] ?? true);

        return $atRisk
            ->sortByDesc(fn ($c) => ($prioritize && $c['reason'] ? 1_000_000 : 0) + $c['days_since'])
            ->values();
    }

    protected function reasonLabel(TenantCustomerSignal $signal): string
    {
        $meta = $signal->meta ?? [];
        $days = (int) ($meta['days_late'] ?? 0);

        return match ($signal->type) {
            'late_completion'     => 'Last visit ran ' . $days . ' day' . ($days === 1 ? '' : 's') . ' late',
            'late_delivery'       => 'Their last delivery ran late',
            'reschedule'          => 'We rescheduled their last appointment',
            'special_order_delay' => 'Their special order was delayed',
            default               => 'A recent issue on their last visit',
        };
    }
}
