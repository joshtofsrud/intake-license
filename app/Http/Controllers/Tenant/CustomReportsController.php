<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\CustomerTimelineService;
use App\Services\Tenant\ReportBuilder\ReportCatalog;
use App\Services\Tenant\ReportBuilder\ReportEngine;
use App\Services\Tenant\ReportBuilder\ReportMailer;
use App\Services\Tenant\ReportBuilder\ReportSchedule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CustomReportsController — the "Custom" tab on Reports.
 * (MARKER-REPORT-BUILDER-CORE MARKER-REPORT-BUILDER-V2 MARKER-REPORT-DELIVERY)
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
                'me'        => ['id' => $u?->id, 'name' => $u?->name, 'email' => $u?->email, 'manager' => $this->isManager()],
                'schedule'  => ['freqs' => ReportSchedule::FREQS, 'days' => ReportSchedule::DAYS, 'times' => ReportSchedule::times(),
                    'distEmail' => ReportCatalog::velocity()['email']],
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
                    'delivery'  => route('tenant.reports.custom.delivery', ['id' => '__ID__']),
                    'test'      => route('tenant.reports.custom.test', ['id' => '__ID__']),
                    'pin'       => route('tenant.reports.custom.pin', ['id' => '__ID__']),
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
        $csv = $engine->csv($cfg);
        return response()->streamDownload(function () use ($csv) { echo $csv; }, $name . '-' . now()->format('Ymd') . '.csv', ['Content-Type' => 'text/csv']);
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
        if (Schema::hasTable('tenant_report_deliveries')) {
            DB::table('tenant_report_deliveries')->where('tenant_id', tenant()->id)->where('report_id', $id)->delete();
            DB::table('tenant_report_pins')->where('tenant_id', tenant()->id)->where('report_id', $id)->delete();
        }
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
        $del = []; $pins = [];
        if (Schema::hasTable('tenant_report_deliveries')) {
            $del = DB::table('tenant_report_deliveries')->where('tenant_id', tenant()->id)->get()->keyBy('report_id')->all();
            $pins = DB::table('tenant_report_pins')->where('tenant_id', tenant()->id)->where('user_id', $u?->id)->pluck('show_as', 'report_id')->all();
        }
        return $q->get()->map(fn ($r) => [
            'id' => $r->id, 'name' => $r->name, 'ds' => $r->dataset === 'inventory' ? 'inv' : $r->dataset,
            'cfg' => json_decode($r->config, true) ?: [],
            'vis' => $r->visibility === 'team' ? 'all' : $r->visibility,
            'cr' => Carbon::parse($r->created_at, 'UTC')->setTimezone($tz)->toDateString(),
            'own' => $r->user_id === $u?->id, 'by' => $r->by_name ?: 'Former staff',
            'last' => $hasRun && $r->last_run_at ? Carbon::parse($r->last_run_at, 'UTC')->setTimezone($tz)->format('M j, g:i a') : null,
            'delivery' => isset($del[$r->id]) ? $this->deliveryOut($del[$r->id]) : null,
            'pin' => $pins[$r->id] ?? null,
        ])->all();
    }

    private function deliveryOut(object $d): array
    {
        $tz = tenant()->timezone();
        $a = (array) $d;
        return [
            'freq' => $d->email_freq, 'day' => (int) $d->email_day, 'time' => $d->email_time, 'to' => (string) $d->recipients,
            'csv' => (bool) $d->attach_csv, 'skip' => (bool) $d->skip_empty, 'label' => ReportSchedule::label($a),
            'next' => $d->next_send_at ? Carbon::parse($d->next_send_at, 'UTC')->setTimezone($tz)->format('D M j, g:i a') : null,
            'lastSent' => $d->last_sent_at ? Carbon::parse($d->last_sent_at, 'UTC')->setTimezone($tz)->format('M j, g:i a') : null,
            'error' => $d->last_error,
            'alert' => (bool) $d->alert_on, 'alertM' => $d->alert_measure, 'alertOp' => $d->alert_op,
            'alertV' => $d->alert_value !== null ? (float) $d->alert_value : null,
        ];
    }

    // ------------------------------------------------- emails, alerts, pins

    private function ownedReport(string $id): ?object
    {
        $u = $this->user();
        $row = DB::table('tenant_saved_reports')->where('tenant_id', tenant()->id)->where('id', $id)->first();
        return $row && ($row->user_id === $u?->id || $this->isManager()) ? $row : null;
    }

    public function delivery(Request $request, string $id)
    {
        $report = $this->ownedReport($id);
        if (!$report) {
            return response()->json(['message' => 'Only the person who built this report, or an owner or manager, can change how it is sent.'], 403);
        }
        $data = $request->validate([
            'freq'   => ['required', 'in:never,daily,weekly,monthly,payperiod'],
            'day'    => ['nullable', 'integer', 'min:1', 'max:28'],
            'time'   => ['nullable', 'regex:/^\\d{2}:00$/'],
            'to'     => ['nullable', 'string', 'max:2000'],
            'csv'    => ['nullable', 'boolean'],
            'skip'   => ['nullable', 'boolean'],
            'alert'  => ['nullable', 'boolean'],
            'alertM' => ['nullable', 'string', 'max:40'],
            'alertOp'=> ['nullable', 'in:below,above,change'],
            'alertV' => ['nullable', 'numeric'],
        ]);
        $to = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($data['to'] ?? ''))))));
        $bad = array_values(array_filter($to, fn ($x) => !filter_var($x, FILTER_VALIDATE_EMAIL)));
        if ($bad) return response()->json(['message' => 'These don\'t look like email addresses: ' . implode(', ', $bad)], 422);
        if (count($to) > 20) return response()->json(['message' => 'Up to 20 addresses per report.'], 422);
        if ($data['freq'] !== 'never' && !$to) return response()->json(['message' => 'Add at least one email address, or set it to Never.'], 422);

        $mailer = new ReportMailer(tenant());
        if (($data['freq'] !== 'never' || !empty($data['alert'])) && ($why = $mailer->blocked($report))) {
            return response()->json(['message' => $why], 422);
        }
        $row = [
            'email_freq' => $data['freq'], 'email_day' => (int) ($data['day'] ?? 1), 'email_time' => $data['time'] ?? '07:00',
            'recipients' => implode(', ', $to), 'attach_csv' => (bool) ($data['csv'] ?? true), 'skip_empty' => (bool) ($data['skip'] ?? false),
            'alert_on' => (bool) ($data['alert'] ?? false), 'alert_measure' => $data['alertM'] ?? null, 'alert_op' => $data['alertOp'] ?? 'below',
            'alert_value' => isset($data['alertV']) ? (float) $data['alertV'] : null, 'last_error' => null, 'updated_at' => now(),
        ];
        $row['next_send_at'] = ReportSchedule::next($row, tenant(), now());
        $existing = DB::table('tenant_report_deliveries')->where('tenant_id', tenant()->id)->where('report_id', $id)->first();
        if ($existing) {
            // A changed alert starts fresh, so it can't fire on yesterday's comparison.
            if ($existing->alert_measure !== $row['alert_measure'] || $existing->alert_op !== $row['alert_op']
                || (float) $existing->alert_value !== (float) $row['alert_value'] || !$existing->alert_on) {
                $row['alert_state'] = null; $row['alert_checked_on'] = null;
            }
            DB::table('tenant_report_deliveries')->where('id', $existing->id)->update($row);
        } else {
            DB::table('tenant_report_deliveries')->insert($row + ['id' => (string) Str::uuid(), 'tenant_id' => tenant()->id, 'report_id' => $id, 'created_at' => now()]);
        }
        return response()->json(['saved' => $this->listSaved()]);
    }

    public function test(Request $request, string $id)
    {
        $report = $this->ownedReport($id);
        $email = $this->user()?->email;
        if (!$report || !$email) return response()->json(['message' => 'Only the person who built this report, or an owner or manager, can send a test.'], 403);
        $d = DB::table('tenant_report_deliveries')->where('tenant_id', tenant()->id)->where('report_id', $id)->first();
        $arr = $d ? (array) $d : ['email_freq' => 'never', 'attach_csv' => 1];
        $r = (new ReportMailer(tenant()))->send($report, $arr, [$email], true);
        if ($r['skipped']) return response()->json(['message' => $r['skipped']], 422);
        return response()->json(['ok' => $r['sent'] > 0, 'to' => $email, 'message' => $r['sent'] ? null : 'The email couldn\'t be sent. Check the address isn\'t blocked from email.']);
    }

    public function pin(Request $request, string $id)
    {
        $data = $request->validate(['on' => ['required', 'boolean'], 'as' => ['nullable', 'in:table,chart,number']]);
        $u = $this->user();
        $visible = collect($this->listSaved())->firstWhere('id', $id);
        if (!$visible) return response()->json(['message' => 'That report isn\'t yours to pin.'], 403);
        $q = DB::table('tenant_report_pins')->where('tenant_id', tenant()->id)->where('report_id', $id)->where('user_id', $u?->id);
        if (!$data['on']) {
            $q->delete();
        } elseif ($q->exists()) {
            $q->update(['show_as' => $data['as'] ?? 'table', 'updated_at' => now()]);
        } else {
            if (DB::table('tenant_report_pins')->where('tenant_id', tenant()->id)->where('user_id', $u?->id)->count() >= 6) {
                return response()->json(['message' => 'You can pin up to 6 reports. Unpin one first.'], 422);
            }
            DB::table('tenant_report_pins')->insert(['id' => (string) Str::uuid(), 'tenant_id' => tenant()->id, 'report_id' => $id,
                'user_id' => $u?->id, 'show_as' => $data['as'] ?? 'table', 'created_at' => now(), 'updated_at' => now()]);
        }
        return response()->json(['saved' => $this->listSaved()]);
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
