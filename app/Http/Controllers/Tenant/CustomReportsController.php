<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\ReportBuilder\ReportCatalog;
use App\Services\Tenant\ReportBuilder\ReportRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CustomReportsController — the "Custom" tab on Reports. (MARKER-REPORT-BUILDER-CORE)
 *
 * Reports are read-only: nothing here changes shop data. A saved report is
 * just a validated config; it is re-checked against the catalog on every run.
 */
class CustomReportsController extends Controller
{
    public function index(Request $request)
    {
        $tenant = tenant();
        $user = Auth::guard('tenant')->user();
        $isManager = $user && $user->isManager();

        $saved = null;
        $input = $request->query();

        if ($request->filled('saved')) {
            $saved = $this->findSaved((string) $request->query('saved'), $user);
            if ($saved) {
                $input = (array) json_decode($saved->config, true);
            }
        }

        $cfg = ReportCatalog::clean($input, $isManager);
        $runner = new ReportRunner($tenant);

        // The builder shows results as soon as it has anything to show: a saved
        // report, or any query that names a measure. A bare visit shows the list.
        $hasRun = $saved !== null || $request->has('m') || $request->has('go');
        $result = $hasRun ? $runner->run($cfg) : null;

        return view('tenant.reports.custom', [
            'cfg'         => $cfg,
            'catalog'     => ReportCatalog::datasets(),
            'ranges'      => ReportCatalog::ranges(),
            'dims'        => ReportCatalog::allowedDims($cfg['ds'], $isManager),
            'filterOpts'  => $runner->filterOptions($cfg['ds'], $isManager),
            'result'      => $result,
            'saved'       => $saved,
            'myReports'   => $this->listSaved($user),
            'isManager'   => $isManager,
            'maxMeasures' => ReportCatalog::MAX_MEASURES,
        ]);
    }

    public function save(Request $request)
    {
        $user = Auth::guard('tenant')->user();
        $isManager = $user && $user->isManager();

        $data = $request->validate([
            'name'       => ['required', 'string', 'max:120'],
            'visibility' => ['nullable', 'in:me,team'],
        ]);

        $cfg = ReportCatalog::clean($request->all(), $isManager);

        $id = (string) Str::uuid();
        DB::table('tenant_saved_reports')->insert([
            'id'         => $id,
            'tenant_id'  => tenant()->id,
            'user_id'    => $user?->id,
            'name'       => trim($data['name']),
            'dataset'    => $cfg['ds'],
            'config'     => json_encode($cfg),
            'visibility' => $data['visibility'] ?? 'me',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('tenant.reports.custom', ['saved' => $id])->with('info', 'Report saved.');
    }

    public function destroy(string $id)
    {
        $user = Auth::guard('tenant')->user();
        $row = $this->findSaved($id, $user);

        // Only the person who built it, or an owner/manager, can delete it.
        if ($row && ($row->user_id === $user?->id || ($user && $user->isManager()))) {
            DB::table('tenant_saved_reports')->where('tenant_id', tenant()->id)->where('id', $id)->delete();
            return redirect()->route('tenant.reports.custom')->with('info', 'Report deleted.');
        }

        return redirect()->route('tenant.reports.custom')->with('info', 'That report is not yours to delete.');
    }

    public function export(Request $request): StreamedResponse
    {
        $user = Auth::guard('tenant')->user();
        $isManager = $user && $user->isManager();

        $input = $request->query();
        if ($request->filled('saved') && ($saved = $this->findSaved((string) $request->query('saved'), $user))) {
            $input = (array) json_decode($saved->config, true);
        }

        $cfg = ReportCatalog::clean($input, $isManager);
        $result = (new ReportRunner(tenant()))->run($cfg);

        $file = 'report-' . $cfg['ds'] . '-' . now()->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($result) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_column($result['columns'], 'label'));
            foreach ($result['rows'] as $row) {
                $line = [];
                foreach ($result['columns'] as $col) {
                    $v = $row[$col['key']];
                    $line[] = $col['key'] === 'label'
                        ? $this->csvSafe((string) $v)
                        : round((float) $v, 2);
                }
                fputcsv($out, $line);
            }
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv']);
    }

    /** A name starting with = + - @ would run as a formula in a spreadsheet. */
    private function csvSafe(string $v): string
    {
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }

    private function findSaved(string $id, $user): ?object
    {
        $row = DB::table('tenant_saved_reports')->where('tenant_id', tenant()->id)->where('id', $id)->first();
        if (!$row) {
            return null;
        }
        // Mine, or shared with the team.
        if ($row->visibility === 'team' || $row->user_id === $user?->id || ($user && $user->isManager())) {
            return $row;
        }
        return null;
    }

    private function listSaved($user): array
    {
        $rows = DB::table('tenant_saved_reports as r')
            ->leftJoin('tenant_users as u', 'u.id', '=', 'r.user_id')
            ->where('r.tenant_id', tenant()->id)
            ->where(function ($q) use ($user) {
                $q->where('r.visibility', 'team')->orWhere('r.user_id', $user?->id);
            })
            ->orderByDesc('r.created_at')
            ->select('r.id', 'r.name', 'r.dataset', 'r.visibility', 'r.created_at', 'r.user_id', 'u.name as by_name')
            ->get();

        return $rows->all();
    }
}
