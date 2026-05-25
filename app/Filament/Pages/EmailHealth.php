<?php
// MARKER-PATCH-148

namespace App\Filament\Pages;

use App\Models\Tenant\TenantEmailBounceEvent;
use App\Models\Tenant\TenantEmailSuppression;
use App\Models\Tenant;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Master-admin email health page.
 *
 * Read-only view of platform-wide email sending hygiene. Surfaces the
 * data Josh needs to spot a problem tenant before AWS does.
 */
class EmailHealth extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-envelope';
    protected static ?string $navigationLabel = 'Email health';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?int    $navigationSort  = 20;

    protected static string $view = 'filament.pages.email-health';

    public ?string $search = null;

    public function getViewData(): array
    {
        $search = trim((string) request()->query('q', ''));

        return [
            'tiles'       => $this->buildTiles(),
            'byBounce'    => $this->tenantsByBounceRate(),
            'recent'      => $this->recentEvents(),
            'searchTerm'  => $search,
            'searchHits'  => $search !== '' ? $this->searchSuppressions($search) : null,
            'generatedAt' => now(),
        ];
    }

    protected function buildTiles(): array
    {
        $week = now()->subDays(7);

        return [
            'platform' => TenantEmailSuppression::whereNull('tenant_id')->count(),
            'tenant'   => TenantEmailSuppression::whereNotNull('tenant_id')->count(),
            'bounces7' => TenantEmailBounceEvent::where('event_type', 'bounce')
                ->where('received_at', '>=', $week)->count(),
            'complaints7' => TenantEmailBounceEvent::where('event_type', 'complaint')
                ->where('received_at', '>=', $week)->count(),
        ];
    }

    /**
     * Tenants with the highest bounce rate in the last 7 days.
     * Bounce rate = bounces / (bounces + estimated sends).
     *
     * "Estimated sends" is approximated from debug_logs mail.sent events
     * if available; otherwise we just show bounce count.
     */
    protected function tenantsByBounceRate(): array
    {
        $week = now()->subDays(7);

        // Bounce counts grouped by tenant
        $bounces = TenantEmailBounceEvent::where('event_type', 'bounce')
            ->where('received_at', '>=', $week)
            ->whereNotNull('tenant_id')
            ->select('tenant_id', DB::raw('COUNT(*) as n'))
            ->groupBy('tenant_id')
            ->orderByDesc('n')
            ->limit(10)
            ->get();

        if ($bounces->isEmpty()) return [];

        $tenants = Tenant::whereIn('id', $bounces->pluck('tenant_id'))
            ->get(['id', 'name', 'subdomain'])
            ->keyBy('id');

        return $bounces->map(function ($row) use ($tenants) {
            $t = $tenants[$row->tenant_id] ?? null;
            // Estimated sends via mail.sent log channel
            $sent = 0;
            try {
                $sent = (int) DB::table('debug_logs')
                    ->where('channel', 'mail')
                    ->where('event', 'mail.sent')
                    ->where('tenant_id', $row->tenant_id)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count();
            } catch (\Throwable $e) { /* table column shape may vary; degrade gracefully */ }

            $total = $sent + $row->n;
            $rate = $total > 0 ? round(($row->n / $total) * 100, 1) : null;

            return [
                'tenant_id' => $row->tenant_id,
                'name'      => $t->name ?? '(unknown tenant)',
                'subdomain' => $t->subdomain ?? null,
                'bounces'   => (int) $row->n,
                'sent'      => $sent,
                'rate'      => $rate,
                'severity'  => $this->rateSeverity($rate, $row->n),
            ];
        })->all();
    }

    /**
     * Categorise rate: AWS suspends accounts above 5% sustained.
     * Anything over 2% is worth investigating; over 5% is alarm.
     */
    protected function rateSeverity(?float $rate, int $bounces): string
    {
        if ($rate === null) {
            return $bounces > 5 ? 'warn' : 'info';
        }
        if ($rate >= 5)  return 'bad';
        if ($rate >= 2)  return 'warn';
        return 'ok';
    }

    /**
     * Most recent bounce + complaint events across all tenants.
     */
    protected function recentEvents(): array
    {
        return TenantEmailBounceEvent::orderByDesc('received_at')
            ->limit(50)
            ->get()
            ->map(function ($e) {
                $tenant = $e->tenant_id ? Tenant::find($e->tenant_id) : null;
                return [
                    'id'         => $e->id,
                    'email'      => $e->email,
                    'event_type' => $e->event_type,
                    'subtype'    => $e->bounce_subtype,
                    'tenant'     => $tenant?->name,
                    'received_at'=> $e->received_at,
                ];
            })
            ->all();
    }

    /**
     * Search the suppression list across all tenants.
     */
    protected function searchSuppressions(string $term): array
    {
        return TenantEmailSuppression::where('email', 'like', '%' . $term . '%')
            ->orderByDesc('suppressed_at')
            ->limit(50)
            ->get()
            ->map(function ($s) {
                $tenant = $s->tenant_id ? Tenant::find($s->tenant_id) : null;
                return [
                    'id'             => $s->id,
                    'email'          => $s->email,
                    'tenant_id'      => $s->tenant_id,
                    'tenant_name'    => $tenant?->name,
                    'reason'         => $s->reason,
                    'suppressed_at'  => $s->suppressed_at,
                    'is_platform'    => is_null($s->tenant_id),
                ];
            })
            ->all();
    }
}
