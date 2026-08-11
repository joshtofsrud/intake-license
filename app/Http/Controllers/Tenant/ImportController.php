<?php

namespace App\Http\Controllers\Tenant;

// MARKER-IMPORT1 — the import wizard. Customers in patch 1; the pipeline is
// shaped so inventory drops in beside it.

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantImport;
use App\Services\Tenant\Import\CsvFile;
use App\Services\Tenant\Import\CustomerImporter;
use App\Support\ImportFieldRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImportController extends Controller
{
    private function guard(): void
    {
        abort_unless(auth('tenant')->user()?->can('customers.import'), 403);
    }

    /** MARKER-IMPORT2 — one importer per type, same contract. */
    private function importer(TenantImport $import)
    {
        return $import->type === 'inventory'
            ? new \App\Services\Tenant\Import\InventoryImporter(tenant(), $import)
            : new CustomerImporter(tenant(), $import);
    }

    private function find(string $id): TenantImport
    {
        return TenantImport::where('tenant_id', tenant()->id)->where('id', $id)->firstOrFail();
    }

    public function index()
    {
        $this->guard();

        $tenant = tenant();

        $imports = TenantImport::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')->limit(25)->get();

        // MARKER-IMPORT3 — who ran each one, resolved in one query rather than
        // per row.
        $actors = \App\Models\Tenant\TenantUser::where('tenant_id', $tenant->id)
            ->whereIn('id', $imports->pluck('created_by_user_id')->filter()->unique())
            ->pluck('name', 'id');

        $counts = [
            'customers' => \App\Models\Tenant\TenantCustomer::where('tenant_id', $tenant->id)->count(),
            'inventory' => \App\Models\Tenant\TenantInventoryItem::where('tenant_id', $tenant->id)->count(),
        ];

        $total = TenantImport::where('tenant_id', $tenant->id)->count();

        return view('tenant.imports.index', compact('imports', 'actors', 'counts', 'total'));
    }

    /**
     * MARKER-IMPORT3 — a starter CSV, generated FROM the field registry.
     *
     * Header row plus one example row, so it can never drift from what the
     * importer actually accepts: add a field to the registry and it appears
     * here automatically.
     */
    public function template(string $type)
    {
        $this->guard();

        abort_unless(in_array($type, ['customers', 'inventory'], true), 404);

        $fields  = ImportFieldRegistry::for($type);
        $headers = array_map(fn ($d) => $d['label'], $fields);

        $example = $type === 'customers'
            ? ['Marcus', 'Lee', 'marcus@example.com', '(509) 555-0142', '1200 W Riverside Ave', '',
               'Spokane', 'WA', '99201', 'US', 'Prefers text', 'no', 'person', '', 'no', '', '', 'no']
            : ['SH-BR-1042', 'Shimano BR-M6100 Caliper', '', 'Hydraulic disc brake caliper',
               'Brakes > Hydraulic', 'QBP', '42.10', '79.99', '1', '2', '5', 'A-14', '4',
               'Black', '', '4550170512347', '', 'yes', 'yes', 'yes', 'no'];

        // Pad or trim so a registry change can't misalign the example row.
        $example = array_slice(array_pad($example, count($headers), ''), 0, count($headers));

        $out = fopen('php://temp', 'r+');
        fputcsv($out, $headers);
        fputcsv($out, $example);
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="intake-' . $type . '-template.csv"',
        ]);
    }

    /** MARKER-IMPORT3 — throw away an upload that never got mapped. */
    public function destroy(string $id)
    {
        $this->guard();
        $import = $this->find($id);

        if (! in_array($import->status, ['draft', 'previewed'], true)) {
            return back()->with('error', 'Only an unfinished import can be discarded.');
        }

        if ($import->stored_path && is_file($import->stored_path)) {
            @unlink($import->stored_path);
        }
        $import->delete();

        return redirect()->route('tenant.imports.index')->with('success', 'Import discarded.');
    }

    public function create(Request $request)
    {
        $this->guard();

        // MARKER-IMPORT3 — the type is chosen on the hub, so this page is the
        // upload step for one type rather than a type picker plus a file box.
        $type = $request->query('type');
        if (! in_array($type, ['customers', 'inventory'], true)) {
            return redirect()->route('tenant.imports.index');
        }

        return view('tenant.imports.create', [
            'type'   => $type,
            'fields' => ImportFieldRegistry::for($type),
        ]);
    }

    public function store(Request $request)
    {
        $this->guard();

        $data = $request->validate([
            'type' => ['required', 'in:customers,inventory'], // MARKER-IMPORT2
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
        ]);

        $tenant = tenant();
        $path   = $request->file('file')->store('imports/' . $tenant->id, 'local');
        $abs    = Storage::disk('local')->path($path);

        $import = TenantImport::create([
            'tenant_id'         => $tenant->id,
            'type'              => $data['type'],
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'stored_path'       => $abs,
            'delimiter'         => CsvFile::detectDelimiter($abs),
            'encoding'          => CsvFile::detectEncoding($abs),
            'has_header'        => true,
            'status'            => 'draft',
            'created_by_user_id'=> auth('tenant')->id(),
        ]);

        // MARKER-IMPORT3 — count once, here, so the hub and the upload summary
        // don't each re-read the file.
        try {
            $stats = (new CsvFile($abs, $import->delimiter, $import->encoding))->stats(true);
            $import->update(['options' => ['row_count' => $stats['rows'], 'ragged' => $stats['ragged']]]);
        } catch (\Throwable $e) {
            \Log::warning('import row count failed', ['import' => $import->id, 'error' => $e->getMessage()]);
        }

        return redirect()->route('tenant.imports.map', $import->id);
    }

    public function map(string $id)
    {
        $this->guard();
        $import = $this->find($id);

        $csv     = new CsvFile($import->stored_path, $import->delimiter, $import->encoding);
        $preview = $csv->preview($import->has_header);
        $stats   = $csv->stats($import->has_header);

        $fields  = ImportFieldRegistry::for($import->type);

        // Guess once, then remember whatever the person chose.
        $mapping = $import->mapping ?? [];
        if (! $mapping) {
            foreach ($preview['header'] as $i => $h) {
                $guess = ImportFieldRegistry::guess($import->type, $h);
                if ($guess) { $mapping[$i] = ['field' => $guess, 'dir' => null]; }
            }
            $import->update(['columns' => $preview['header'], 'mapping' => $mapping]);
        }

        // MARKER-IMPORT2 — inventory needs a location to count stock at
        $locations = \App\Models\Tenant\TenantLocation::where('tenant_id', tenant()->id)
            ->where('is_active', true)->orderBy('name')->get();

        return view('tenant.imports.map', compact('import', 'preview', 'stats', 'fields', 'mapping', 'locations'));
    }

    public function saveMapping(Request $request, string $id)
    {
        $this->guard();
        $import = $this->find($id);

        $fields = ImportFieldRegistry::for($import->type);
        $map    = [];

        foreach ((array) $request->input('field', []) as $idx => $field) {
            if (! $field || ! isset($fields[$field])) { continue; }
            $dir = $request->input('dir.' . $idx);
            $map[(int) $idx] = [
                'field' => $field,
                'dir'   => in_array($dir, ['csv', 'keep', 'blank'], true) ? $dir : null,
            ];
        }

        $used = array_column($map, 'field');
        $match = ImportFieldRegistry::matchField($import->type);
        if (! in_array($match, $used, true)) {
            return back()->with('error',
                'Map a column to ' . ($fields[$match]['label'] ?? $match) .
                ' — it is how an existing record is recognised.');
        }

        $import->update([
            'mapping' => $map,
            'options' => array_merge((array) $import->options, [
                'mode'      => in_array($request->input('mode'), ['upsert', 'insert', 'update'], true)
                               ? $request->input('mode') : 'upsert',
                'direction' => in_array($request->input('direction'), ['csv', 'keep', 'blank'], true)
                               ? $request->input('direction') : 'csv',
                // MARKER-IMPORT2 — inventory only
                'location_id'       => $request->input('location_id'),
                'stock_mode'        => in_array($request->input('stock_mode'), ['set', 'add', 'leave'], true)
                                       ? $request->input('stock_mode') : 'set',
                'create_categories' => $request->boolean('create_categories'),
                'create_vendors'    => $request->boolean('create_vendors'),
            ]),
        ]);

        return redirect()->route('tenant.imports.preview', $import->id);
    }

    public function preview(string $id)
    {
        $this->guard();
        $import = $this->find($id);

        $result = $this->importer($import)->preview();
        $import->update(['status' => 'previewed']);

        return view('tenant.imports.preview', compact('import', 'result'));
    }

    public function run(string $id)
    {
        $this->guard();
        $import = $this->find($id);

        $import->update(['status' => 'running', 'started_at' => now()]);

        try {
            $result = $this->importer($import)->run();
        } catch (\Throwable $e) {
            $import->update(['status' => 'failed', 'failure_reason' => $e->getMessage(),
                             'finished_at' => now()]);
            \Log::error('customer import failed', ['import' => $import->id, 'error' => $e->getMessage()]);

            return redirect()->route('tenant.imports.show', $import->id)
                ->with('error', 'The import stopped: ' . $e->getMessage());
        }

        $errorPath = null;
        if ($result['errorRows']) {
            $errorPath = $this->writeErrorCsv($import, $result['errorRows']);
        }

        $import->update([
            'status'      => 'done',
            'totals'      => $result['counts'],
            'error_path'  => $errorPath,
            'finished_at' => now(),
        ]);

        return redirect()->route('tenant.imports.show', $import->id);
    }

    /** Original columns + a reason column, so it can be fixed and re-imported. */
    private function writeErrorCsv(TenantImport $import, array $rows): string
    {
        $rel = 'imports/' . $import->tenant_id . '/errors-' . $import->id . '.csv';
        $abs = Storage::disk('local')->path($rel);
        @mkdir(dirname($abs), 0775, true);

        $h = fopen($abs, 'w');
        $header = $import->columns ?? [];
        if ($header) { fputcsv($h, array_merge($header, ['Why it was skipped'])); }
        foreach ($rows as [$cells, $why]) {
            fputcsv($h, array_merge((array) $cells, [$why]));
        }
        fclose($h);

        return $abs;
    }

    public function show(string $id)
    {
        $this->guard();

        return view('tenant.imports.show', ['import' => $this->find($id)]);
    }

    public function errors(string $id)
    {
        $this->guard();
        $import = $this->find($id);

        abort_unless($import->error_path && is_file($import->error_path), 404);

        return response()->download($import->error_path,
            'import-errors-' . $import->original_filename);
    }

    /**
     * MARKER-IMPORT2 — reverse an import.
     *
     * Anything that has been used since is kept rather than deleted, and the
     * result says so plainly. Undoing twice is harmless: reversed rows are
     * stamped and skipped.
     */
    public function reverse(string $id)
    {
        $this->guard();
        $import = $this->find($id);

        if ($import->status !== 'done') {
            return back()->with('error', 'Only a finished import can be reversed.');
        }

        $result = (new \App\Services\Tenant\Import\ImportReverser(tenant(), $import))->reverse();

        $import->update([
            'status' => 'reversed',
            'totals' => array_merge((array) $import->totals, ['reversal' => $result]),
        ]);

        $msg = 'Reversed: ' . $result['deleted'] . ' deleted, ' . $result['restored'] . ' restored';
        if ($result['stock_reversed']) { $msg .= ', ' . $result['stock_reversed'] . ' stock changes undone'; }
        if ($result['kept']) {
            $msg .= '. ' . $result['kept'] . ' kept because they have been used since.';
        }

        return redirect()->route('tenant.imports.show', $import->id)->with('success', $msg);
    }
}
