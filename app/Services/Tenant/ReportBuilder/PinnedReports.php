<?php

namespace App\Services\Tenant\ReportBuilder;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reports a person pinned to their own dashboard. (MARKER-REPORT-DELIVERY)
 * Each runs as the person looking at it, so a pin never shows more than they
 * could open in Reports. Results are kept for 15 minutes.
 */
class PinnedReports
{
    public const CACHE_SECONDS = 900;

    public static function for(Tenant $tenant, $user): array
    {
        if (!$user || !Schema::hasTable('tenant_report_pins')) return [];
        $locked = !ReportAccess::allowed($tenant);
        $mgr = method_exists($user, 'isManager') && $user->isManager();
        $pins = DB::table('tenant_report_pins as p')
            ->join('tenant_saved_reports as r', 'r.id', '=', 'p.report_id')
            ->where('p.tenant_id', $tenant->id)->where('p.user_id', $user->id)
            ->where(function ($w) use ($user, $mgr) {
                $w->where('r.user_id', $user->id)->orWhereIn('r.visibility', ['all', 'team']);
                if ($mgr) $w->orWhere('r.visibility', 'mgr');
            })
            ->orderBy('p.position')->orderBy('p.created_at')
            ->select('r.id', 'r.name', 'r.config', 'r.updated_at', 'p.show_as')
            ->get();
        if ($pins->isEmpty()) return [];
        if ($locked) {
            // No numbers for a shop without extended reports: just a name and a lock.
            return $pins->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'as' => $p->show_as, 'table' => null, 'error' => null, 'locked' => true])->all();
        }

        $engine = null;
        $out = [];
        foreach ($pins as $p) {
            $key = 'rb:pin:' . $tenant->id . ':' . $user->id . ':' . $p->id . ':' . md5($p->config . $p->show_as . $p->updated_at . ($mgr ? 'm' : 's'));
            $out[] = Cache::remember($key, self::CACHE_SECONDS, function () use (&$engine, $tenant, $mgr, $p) {
                $engine ??= new ReportEngine($tenant, $mgr);
                $item = ['id' => $p->id, 'name' => $p->name, 'as' => $p->show_as, 'table' => null, 'error' => null,
                    'at' => $engine->periods()->today()->format('M j') . ', ' . now()->setTimezone($tenant->timezone())->format('g:i a')];
                try {
                    $cfg = $engine->clean((array) json_decode($p->config, true));
                    if ($cfg['mode'] === 'timeline') {
                        $item['error'] = 'A customer timeline can\'t be pinned. Open it in Reports.';
                    } else {
                        $item['table'] = $engine->table($cfg);
                        $item['range'] = ($engine->catalog()[$cfg['ds']]['snap'] ?? false) ? 'As of today' : $cfg['range'];
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $item['error'] = 'This report couldn\'t run just now.';
                }
                return $item;
            });
        }
        return $out;
    }
}
