<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\CustomerTimelineService;
use App\Services\Tenant\ReportBuilder\ReportCatalog;
use App\Services\Tenant\ReportBuilder\ReportEngine;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CustomReportsController — the "Custom" tab on Reports.
 * (MARKER-REPORT-BUILDER-CORE MARKER-REPORT-BUILDER-V2)
 *
 * Read-only: nothing here changes shop data. The page sends a report's
 * settings; every request cleans them against the catalog before running.
 */
class CustomReportsController extends Controller
{
    private function user()
    {
        return Auth::guard('tenant')->user();
    }

    private function isManager(): bool
    {
        $u = $this->user();
        return $u && $u->isManager();
    }

    private function engine(): ReportEngine
    {
        return new ReportEngine(tenant(), $this->isManager());
    }

    public function index()
    {
        $engine = $this->engine();
        $u = $this->user();
        return view('tenant.reports.custom', [
            'boot' => [
                'catalog'   => $engine->catalog(),
                'templates' => ReportCatalog::templates(),
                'saved'     => $this->listSaved(),
                'calcs'     => $this->myCalcs(),
                'me'        => ['id' => $u?->id, 'name' => $u?->name, 'manager' => $this->isManager()],
                'ranges'    => \App\Services\Tenant\ReportBuilder\Periods::RANGES,
                'urls'      => [
                    'data'      => route('tenant.reports.custom.data'),
                    'save'      => route('tenant.reports.custom.save'),
                    'delete'    => route('tenant.reports.custom.delete', ['id' => '__ID__']),
                    'opened'    => route('tenant.reports.custom.opened', ['id' => '__ID__']),
                    'export'    => route('tenant.reports.custom.export'),
                    'customers' => route('tenant.reports.custom.customers'),
                    'timeline'  => route('tenant.reports.custom.timeline'),
                    'calcs'     => route('tenant.reports.custom.calcs'),
                ],
            ],
        ]);
    }

    public function data(Request $request)
    {
        $engine = $this->engine();
        $cfg = $engine->clean((array) $request->input('cfg', []));
        if ($cfg['mode'] === 'timeline') {
            return response()->json(['cfg' => $cfg] + $this->buildTimeline($cfg));
        }
        $out = $cfg['dim2'] !== '' ? $engine->pivot($cfg) : $engine->rows($cfg);
        return response()->json(['cfg' => $cfg, 'kind' => $cfg['dim2'] !== '' ? 'pivot' : 'rows'] + $out);
    }

    public function export(Request $request): StreamedResponse
    {
        $engine = $this->engine();
        $raw = json_decode(base64_decode((string) $request->query('cfg', ''), true) ?: '[]', true);
        $cfg = $engine->clean(is_array($raw) ? $raw : []);
        $name = Str::slug(mb_substr((string) $request->query('name', 'report'), 0, 60)) ?: 'report';
        $safe = fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
        $num = fn ($v) => $v === null ? '' : round((float) $v, 2);

        return response()->streamDownload(function () use ($engine, $cfg, $safe, $num) {
            $out = fopen('php://output', 'w');
            if ($cfg['dim2'] !== '') {
                $p = $engine->pivot($cfg);
                $m = $cfg['measures'][0];
                fputcsv($out, array_merge([$engine->dimLabel($cfg, $cfg['dim']) . ' / ' . $engine->dimLabel($cfg, $cfg['dim2']) . ' (' . $engine->measureDef($cfg, $m)[0] . ')'], array_map($safe, $p['cols']), ['Total']));
                foreach ($p['rows'] as $r) {
                    $line = [$safe($r['l'])];
                    foreach (array_keys($p['cols']) as $j) {
                        $vals = [];
                        foreach ($r['cells'] as $k => $arr) $vals[$k] = $arr[$j];
                        $line[] = $num($engine->cellValue($cfg, $m, $vals));
                    }
                    $line[] = $num($engine->cellValue($cfg, $m, $r['total']));
                    fputcsv($out, $line);
                }
            } else {
                $res = $engine->rows($cfg);
                $head = [$engine->dimLabel($cfg, $cfg['dim'])];
                foreach ($cfg['measures'] as $m) $head[] = $engine->measureDef($cfg, $m)[0];
                fputcsv($out, $head);
                foreach ($res['rows'] as $r) {
                    $line = [$safe($r['l'])];
                    foreach ($cfg['measures'] as $m) $line[] = $num($engine->cellValue($cfg, $m, $r['vals']));
                    fputcsv($out, $line);
                }
                $line = ['Total'];
                foreach ($cfg['measures'] as $m) $line[] = $num($engine->cellValue($cfg, $m, $res['totals']));
                fputcsv($out, $line);
            }
            fclose($out);
        }, $name . '-' . now()->format('Ymd') . '.csv', ['Content-Type' => 'text/csv']);
    }

    // --------------------------------------------------------------- saving

    public function save(Request $request)
    {
        $u = $this->user();
        $data = $request->validate([
            'id'   => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:120'],
            'vis'  => ['nullable', 'in:me,mgr,all'],
            'cfg'  => ['required', 'array'],
        ]);
        $engine = $this->engine();
        $cfg = $engine->clean($data['cfg']);
        $vis = $data['vis'] ?? 'me';
        if ($cfg['ds'] === 'staff' && $vis === 'all') {
            $vis = 'mgr';  // staff hours never go past owner and managers
        }

        $existing = null;
        if (!empty($data['id'])) {
            $existing = DB::table('tenant_saved_reports')->where('tenant_id', tenant()->id)->where('id', $data['id'])->first();
            if ($existing && $existing->user_id !== $u?->id && !$this->isManager()) {
                $existing = null; // not theirs: save a copy instead
            }
        }

        $row = ['name' => trim($data['name']), 'dataset' => $cfg['ds'], 'config' => json_encode($cfg), 'visibility' => $vis, 'updated_at' => now()];
        if ($existing) {
            DB::table('tenant_saved_reports')->where('tenant_id', tenant()->id)->where('id', $existing->id)->update($row);
            $id = $existing->id;
        } else {
            $id = (string) Str::uuid();
            DB::table('tenant_saved_reports')->insert($row + ['id' => $id, 'tenant_id' => tenant()->id, 'user_id' => $u?->id, 'created_at' => now()]);
        }
        return response()->json(['id' => $id, 'saved' => $this->listSaved()]);
    }

    public function destroy(string $id)
    {
        $u = $this->user();
        $row = DB::table('tenant_saved_reports')->where('tenant_id', tenant()->id)->where('id', $id)->first();
        if (!$row || ($row->user_id !== $u?->id && !$this->isManager())) {
            return response()->json(['ok' => false, 'message' => 'Only the person who built a report, or an owner or manager, can delete it.'], 403);
        }
        DB::table('tenant_saved_reports')->where('tenant_id', tenant()->id)->where('id', $id)->delete();
        return response()->json(['ok' => true, 'saved' => $this->listSaved()]);
    }

    public function opened(string $id)
    {
        if (Schema::hasColumn('tenant_saved_reports', 'last_run_at')) {
            DB::table('tenant_saved_reports')->where('tenant_id', tenant()->id)->where('id', $id)->update(['last_run_at' => now()]);
        }
        return response()->json(['ok' => true]);
    }

    private function listSaved(): array
    {
        $u = $this->user();
        $mgr = $this->isManager();
        $hasRun = Schema::hasColumn('tenant_saved_reports', 'last_run_at');
        $q = DB::table('tenant_saved_reports as r')
            ->leftJoin('tenant_users as u', 'u.id', '=', 'r.user_id')
            ->where('r.tenant_id', tenant()->id)
            ->where(function ($w) use ($u, $mgr) {
                $w->where('r.user_id', $u?->id)->orWhereIn('r.visibility', ['all', 'team']);
                if ($mgr) $w->orWhere('r.visibility', 'mgr');
            })
            ->orderByDesc('r.created_at')
            ->select('r.id', 'r.name', 'r.dataset', 'r.config', 'r.visibility', 'r.created_at', 'r.user_id', 'u.name as by_name');
        if ($hasRun) $q->addSelect('r.last_run_at');
        $tz = tenant()->timezone();
        return $q->get()->map(fn ($r) => [
            'id' => $r->id, 'name' => $r->name, 'ds' => $r->dataset === 'inventory' ? 'inv' : $r->dataset,
            'cfg' => json_decode($r->config, true) ?: [],
            'vis' => $r->visibility === 'team' ? 'all' : $r->visibility,
            'cr' => Carbon::parse($r->created_at, 'UTC')->setTimezone($tz)->toDateString(),
            'own' => $r->user_id === $u?->id, 'by' => $r->by_name ?: 'Former staff',
            'last' => $hasRun && $r->last_run_at ? Carbon::parse($r->last_run_at, 'UTC')->setTimezone($tz)->format('M j, g:i a') : null,
        ])->all();
    }

    // ---------------------------------------------------------- calculations

    private function myCalcs(): array
    {
        if (!Schema::hasTable('tenant_report_calcs')) return [];
        return DB::table('tenant_report_calcs')->where('tenant_id', tenant()->id)->where('user_id', $this->user()?->id)
            ->orderBy('name')->get(['name', 'formula as f', 'type as t'])->map(fn ($r) => (array) $r)->all();
    }

    public function calcs(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'f'    => ['required', 'string', 'max:300'],
            't'    => ['required', 'in:$,n,%,d'],
        ]);
        $u = $this->user();
        $exists = DB::table('tenant_report_calcs')->where('tenant_id', tenant()->id)->where('user_id', $u?->id)->where('formula', $data['f'])->exists();
        if (!$exists) {
            DB::table('tenant_report_calcs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => tenant()->id, 'user_id' => $u?->id,
                'name' => $data['name'], 'formula' => $data['f'], 'type' => $data['t'], 'created_at' => now(), 'updated_at' => now()]);
        }
        return response()->json(['calcs' => $this->myCalcs()]);
    }

    // ------------------------------------------------------------- timeline

    public function customers(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $rows = DB::table('tenant_customers')->where('tenant_id', tenant()->id)->whereNull('erased_at')
            ->when($q !== '', function ($w) use ($q) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
                $w->where(function ($x) use ($like) {
                    $x->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                      ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", [$like])
                      ->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like);
                });
            })
            ->orderBy('first_name')->orderBy('last_name')->limit(20)
            ->get(['id', 'first_name', 'last_name', 'email']);
        return response()->json(['customers' => $rows->map(fn ($c) => ['id' => $c->id,
            'name' => trim($c->first_name . ' ' . $c->last_name) ?: 'Unnamed customer', 'email' => $c->email])->all()]);
    }

    public function timeline(Request $request)
    {
        $engine = $this->engine();
        $cfg = $engine->clean(['mode' => 'timeline'] + (array) $request->input('cfg', []));
        return response()->json(['cfg' => $cfg] + $this->buildTimeline($cfg));
    }

    private function buildTimeline(array $cfg): array
    {
        $tid = tenant()->id;
        if ($cfg['tcust'] === '') return ['customer' => null, 'events' => [], 'stats' => null];
        $c = DB::table('tenant_customers')->where('tenant_id', $tid)->where('id', $cfg['tcust'])->whereNull('erased_at')->first();
        if (!$c) return ['customer' => null, 'events' => [], 'stats' => null];

        $map = ['sale' => 'sale', 'appointment' => 'appt', 'message_thread' => 'msg', 'quality_signal' => 'note',
            'class_registration' => 'class', 'pack_grant' => 'class', 'membership_grant' => 'class', 'rental' => 'rental', 'lease' => 'rental'];
        $events = [];
        foreach (app(CustomerTimelineService::class)->buildForCustomer($tid, $c->id) as $e) {
            $cents = $e['amount_cents'] ?? ($e['amount'] ?? null);
            $kind = $map[$e['kind'] ?? ($e['type'] ?? '')] ?? 'note';
            $events[] = ['date' => Carbon::parse($e['date'])->toDateString(), 'kind' => $kind,
                'title' => trim(($e['title'] ?? '') . (isset($e['identifier']) && $e['identifier'] ? ' ' . $e['identifier'] : '')),
                'sub' => trim(trim((string) ($e['subtitle'] ?? ''), ' ·') . (isset($e['status']) && $e['status'] ? ' · ' . $e['status'] : ''), ' ·'),
                'amount' => $cents !== null ? $cents / 100 : null,
                'refund' => (bool) ($e['is_refunded'] ?? false)];
        }
        foreach (DB::table('tenant_customer_notes')->where('tenant_id', $tid)->where('customer_id', $c->id)->get(['note', 'created_at']) as $n) {
            $events[] = ['date' => Carbon::parse($n->created_at, 'UTC')->setTimezone(tenant()->timezone())->toDateString(), 'kind' => 'note',
                'title' => 'Note', 'sub' => (string) $n->note, 'amount' => null, 'refund' => false];
        }

        [$from] = (new \App\Services\Tenant\ReportBuilder\Periods(tenant()))->range($cfg['range']);
        $events = array_values(array_filter($events, fn ($e) => $e['date'] >= $from->toDateString() && ($cfg['tevents'][$e['kind']] ?? 1)));
        usort($events, fn ($a, $b) => strcmp($b['date'], $a['date']));

        // Lifetime value after each paid sale, oldest first.
        $sales = DB::table('tenant_sales')->where('tenant_id', $tid)->where('customer_id', $c->id)->where('payment_status', 'paid')
            ->orderBy('sale_date')->get(['sale_date', 'subtotal_cents', 'discount_cents', 'refund_of_sale_id']);
        $ltv = 0; $visits = 0; $first = null; $last = null; $spentNoRefund = 0; $byDate = [];
        foreach ($sales as $s) {
            $amt = ((int) $s->subtotal_cents - (int) $s->discount_cents) * ($s->refund_of_sale_id ? -1 : 1);
            $ltv += $amt;
            $d = substr((string) $s->sale_date, 0, 10);
            $byDate[$d] = $ltv;
            if (!$s->refund_of_sale_id) { $visits++; $spentNoRefund += $amt; $first = $first ?? $d; $last = $d; }
        }
        foreach ($events as &$e) {
            $e['ltv'] = $e['kind'] === 'sale' && isset($byDate[$e['date']]) ? $byDate[$e['date']] / 100 : null;
        }
        unset($e);

        $since = $c->created_at ? Carbon::parse($c->created_at)->toDateString() : $first;
        $gap = null;
        if ($visits > 1 && $first && $last) $gap = (int) round(Carbon::parse($first)->diffInDays(Carbon::parse($last)) / ($visits - 1));

        return [
            'customer' => ['id' => $c->id, 'name' => trim($c->first_name . ' ' . $c->last_name) ?: 'Unnamed customer'],
            'events'   => $events,
            'stats'    => ['since' => $since, 'ltv' => $ltv / 100, 'visits' => $visits, 'gap' => $gap,
                'avg' => $visits ? $spentNoRefund / $visits / 100 : null, 'last' => $last],
        ];
    }
}
