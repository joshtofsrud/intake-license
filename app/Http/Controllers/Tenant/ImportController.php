<?php

namespace App\Http\Controllers\Tenant;

// MARKER-IMPORT1 — the import wizard. Customers in patch 1; the pipeline is
// shaped so inventory drops in beside it.

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantImport;
use App\Models\Tenant\TenantImportMapping;   // MARKER-IMPORT-PRESETS
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

        // MARKER-IMPORT-PRESETS — the hub section held back until something
        // could fill it. Renders only when at least one preset exists.
        $presets = TenantImportMapping::where('tenant_id', $tenant->id)
            ->orderByDesc('last_used_at')->orderBy('name')->get();

        return view('tenant.imports.index',
            compact('imports', 'actors', 'counts', 'total', 'presets'));
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

        // MARKER-IMPORT-PRESETS — "Use" on the hub lands here; the preset is
        // carried through the upload and applied on the map step.
        $presetId = $request->query('preset');
        $preset   = $presetId
            ? TenantImportMapping::where('tenant_id', tenant()->id)
                ->where('type', $type)->find($presetId)
            : null;

        return view('tenant.imports.create', [
            'type'   => $type,
            'fields' => ImportFieldRegistry::for($type),
            'preset' => $preset,
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
            $import->update(['options' => array_filter([
                'row_count' => $stats['rows'],
                'ragged'    => $stats['ragged'],
                // MARKER-IMPORT-PRESETS — applied on the map step, not here,
                // because that is where the header is read.
                'preset_id' => $request->input('preset_id'),
            ], fn ($v) => $v !== null)]);
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

        // MARKER-IMPORT-PRESETS — a preset the person picked, then a preset
        // whose headers match exactly, then the per-column guess. Only ever
        // on a fresh import: once someone has chosen, that choice stands.
        $hash    = TenantImportMapping::hashHeader($preview['header']);
        $applied = null;
        $mapping = $import->mapping ?? [];

        if (! $mapping) {
            $chosen = ($import->options ?? [])['preset_id'] ?? null;

            $applied = TenantImportMapping::where('tenant_id', tenant()->id)
                ->where('type', $import->type)
                ->when($chosen, fn ($q) => $q->where('id', $chosen),
                                fn ($q) => $q->where('header_hash', $hash))
                ->first();

            if ($applied) {
                $mapping = $applied->mapping;
                $import->update([
                    'columns' => $preview['header'],
                    'mapping' => $mapping,
                    'options' => array_merge((array) $import->options, (array) $applied->options),
                ]);
                $applied->increment('use_count');
                $applied->update(['last_used_at' => now()]);
            } else {
                foreach ($preview['header'] as $i => $h) {
                    $guess = ImportFieldRegistry::guess($import->type, $h);
                    if ($guess) { $mapping[$i] = ['field' => $guess, 'dir' => null]; }
                }
                $import->update(['columns' => $preview['header'], 'mapping' => $mapping]);
            }
        }

        // Whether it was auto-matched matters on screen: an applied preset
        // should not look like a lucky guess.
        $autoMatched = $applied && ! (($import->options ?? [])['preset_id'] ?? null)
                       && $applied->header_hash === $hash;

        $presets = TenantImportMapping::where('tenant_id', tenant()->id)
            ->where('type', $import->type)->orderBy('name')->get();

        // MARKER-IMPORT2 — inventory needs a location to count stock at
        $locations = \App\Models\Tenant\TenantLocation::where('tenant_id', tenant()->id)
            ->where('is_active', true)->orderBy('name')->get();

        // MARKER-IMPORT-LEGEND — the screen is shared by both types, so the
        // match key it names has to come from the registry, not a hardcoded
        // "email". saveMapping() blocks on this same field.
        $matchField = ImportFieldRegistry::matchField($import->type);

        return view('tenant.imports.map', compact(
            'import', 'preview', 'stats', 'fields', 'mapping', 'locations',
            'matchField', 'presets', 'applied', 'autoMatched'));
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
                // MARKER-CUSTOMER-TAGS — tag every customer this import
                // CREATES. Updates and skips are not tagged: those people
                // did not come from this file.
                'tag_name'          => trim((string) $request->input('tag_name', '')) ?: null,
                // MARKER-IMPORT-TAG-CARD — 'created' (default) or 'touched',
                // which also tags rows that matched an existing customer.
                'tag_scope'         => in_array($request->input('tag_scope'), ['created', 'touched', 'all'], true) // MARKER-IMPORT-TAG-ALL
                                       ? $request->input('tag_scope') : 'created',
            ]),
        ]);

        // MARKER-IMPORT-MERGE — the review decides what preview is previewing.
        return redirect()->route('tenant.imports.conflicts', $import->id);
    }

    /**
     * MARKER-IMPORT-MERGE — conflicts grouped by field. Skipped entirely when
     * the file doesn't disagree with anything we already have.
     */
    public function conflicts(string $id)
    {
        $this->guard();
        $import   = $this->find($id);
        $importer = $this->importer($import);
        $analysis = $importer->conflicts();

        if (! $analysis['fields']) {
            return redirect()->route('tenant.imports.preview', $import->id);
        }

        $overrideCount = 0;
        foreach ((array) ($import->row_overrides ?? []) as $lines) {
            $overrideCount += count((array) $lines);
        }

        return view('tenant.imports.conflicts',
            compact('import', 'importer', 'analysis', 'overrideCount'));
    }

    /** MARKER-IMPORT-MERGE — write the per-field choices back into the mapping. */
    public function saveConflicts(Request $request, string $id)
    {
        $this->guard();
        $import = $this->find($id);

        $chosen = (array) $request->input('dir', []);
        $map    = (array) $import->mapping;

        foreach ($map as $idx => $m) {
            $field = is_array($m) ? ($m['field'] ?? null) : $m;
            if (! $field || ! array_key_exists($field, $chosen)) { continue; }
            $dir = $chosen[$field];
            $map[$idx] = [
                'field' => $field,
                'dir'   => in_array($dir, ['csv', 'keep', 'blank'], true) ? $dir : null,
            ];
        }

        $import->update(['mapping' => $map]);

        return redirect()->route('tenant.imports.preview', $import->id);
    }

    /** MARKER-IMPORT-MERGE — every differing row for one field. */
    public function conflictField(Request $request, string $id, string $field)
    {
        $this->guard();
        $import   = $this->find($id);
        $importer = $this->importer($import);

        $fields = ImportFieldRegistry::for($import->type);
        abort_unless(isset($fields[$field]), 404);

        $per    = 50;
        $page   = max(1, (int) $request->integer('page', 1));
        $filter = (string) $request->input('q', '');

        $result    = $importer->conflictRows($field, ($page - 1) * $per, $per, $filter);
        $overrides = $importer->overridesFor($field);

        $rule = null;
        foreach ((array) $import->mapping as $m) {
            if ((is_array($m) ? ($m['field'] ?? null) : $m) === $field) {
                $rule = is_array($m) ? ($m['dir'] ?? null) : null;
            }
        }
        $rule = $rule ?: (($import->options ?? [])['direction'] ?? 'csv');

        return view('tenant.imports.conflict-field', [
            'import'    => $import,
            'importer'  => $importer,
            'field'     => $field,
            'label'     => $fields[$field]['label'],
            'rows'      => $result['rows'],
            'total'     => $result['total'],
            'page'      => $page,
            'per'       => $per,
            'filter'    => $filter,
            'rule'      => $rule,
            'overrides' => $overrides,
        ]);
    }

    /** MARKER-IMPORT-MERGE — merge this page's row decisions into the import. */
    public function saveConflictField(Request $request, string $id, string $field)
    {
        $this->guard();
        $import = $this->find($id);

        $fields = ImportFieldRegistry::for($import->type);
        abort_unless(isset($fields[$field]), 404);

        $all  = (array) ($import->row_overrides ?? []);
        $cur  = (array) ($all[$field] ?? []);

        // Only the lines on the submitted page are touched; '' means "follow
        // the field rule", which is a removal rather than a stored value.
        foreach ((array) $request->input('ov', []) as $line => $choice) {
            $line = (string) (int) $line;
            if (in_array($choice, ['csv', 'keep', 'blank'], true)) {
                $cur[$line] = $choice;
            } else {
                unset($cur[$line]);
            }
        }

        if ($cur) { $all[$field] = $cur; } else { unset($all[$field]); }
        $import->update(['row_overrides' => $all]);

        return redirect()->route('tenant.imports.conflict.field', [
            $import->id, $field, 'page' => $request->input('page', 1), 'q' => $request->input('q'),
        ])->with('success', 'Row choices saved.');
    }

    /** MARKER-IMPORT-PRESETS — apply a chosen preset over the current mapping. */
    public function applyPreset(Request $request, string $id)
    {
        $this->guard();
        $import = $this->find($id);

        $preset = TenantImportMapping::where('tenant_id', tenant()->id)
            ->where('type', $import->type)->findOrFail($request->input('preset_id'));

        $import->update([
            'mapping' => $preset->mapping,
            'options' => array_merge((array) $import->options, (array) $preset->options),
        ]);
        $preset->increment('use_count');
        $preset->update(['last_used_at' => now()]);

        return redirect()->route('tenant.imports.map', $import->id)
            ->with('success', 'Applied "' . $preset->name . '".');
    }

    /**
     * MARKER-IMPORT-PRESETS — save the current mapping for reuse.
     *
     * Saving under a name that already exists for this tenant and type
     * updates it, which is what "save" means to the person doing it.
     */
    public function savePreset(Request $request, string $id)
    {
        $this->guard();
        $import = $this->find($id);

        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);

        if (! $import->mapping) {
            return back()->with('error', 'Map at least one column before saving a mapping.');
        }

        $header = (array) ($import->columns ?? []);

        TenantImportMapping::updateOrCreate(
            ['tenant_id' => tenant()->id, 'type' => $import->type, 'name' => trim($data['name'])],
            [
                'mapping'            => $import->mapping,
                'options'            => $import->options,
                'header'             => $header,
                'header_hash'        => TenantImportMapping::hashHeader($header),
                'created_by_user_id' => auth('tenant')->id(),
            ]
        );

        return back()->with('success', 'Saved "' . trim($data['name']) . '".');
    }

    /** MARKER-IMPORT-PRESETS */
    public function renamePreset(Request $request, string $mappingId)
    {
        $this->guard();

        $preset = TenantImportMapping::where('tenant_id', tenant()->id)->findOrFail($mappingId);
        $data   = $request->validate(['name' => ['required', 'string', 'max:80']]);

        $preset->update(['name' => trim($data['name'])]);

        return back()->with('success', 'Renamed.');
    }

    /** MARKER-IMPORT-PRESETS — deletes the mapping only; imports are untouched. */
    public function deletePreset(string $mappingId)
    {
        $this->guard();

        $preset = TenantImportMapping::where('tenant_id', tenant()->id)->findOrFail($mappingId);
        $name   = $preset->name;
        $preset->delete();

        return back()->with('success', 'Deleted "' . $name . '".');
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
            // MARKER-IMPORT-FAILREASON — never store an empty reason: a blank
            // failure screen tells the operator nothing at all.
            $reason = trim((string) $e->getMessage());
            if ($reason === '') {
                $reason = class_basename($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine()
                        . ' (no message) — the application log for today has the full trace';
            }

            $import->update(['status' => 'failed', 'failure_reason' => $reason,
                             'finished_at' => now()]);
            \Log::error('customer import failed', [
                'import' => $import->id,
                'error'  => $reason,
                'class'  => get_class($e),
                'trace'  => \Illuminate\Support\Str::limit($e->getTraceAsString(), 2000),
            ]);

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
    /**
     * MARKER-IMPORT-DRILLDOWN — what is actually behind one of the numbers.
     * Returns JSON so the result page can open it without a page load.
     *
     * created/updated come from the row ledger; errors are read back out of
     * the error CSV, which is the only place the per-row reasons live.
     */
    public function detail(Request $request, string $id)
    {
        $this->guard();
        $import = $this->find($id);

        $kind = $request->query('kind');
        if (! in_array($kind, ['created', 'updated', 'errors'], true)) {
            return response()->json(['ok' => false, 'error' => 'Nothing to show for that.'], 422);
        }

        if ($kind === 'errors') {
            return response()->json([
                'ok'    => true,
                'kind'  => 'errors',
                'rows'  => $this->readErrorCsv($import, 200),
                'total' => (int) $import->total('errors'),
            ]);
        }

        $rows = \App\Models\Tenant\TenantImportRow::where('import_id', $import->id)
            ->where('action', $kind === 'created' ? 'created' : 'updated')
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        $ids = $rows->pluck('record_id')->all();

        if ($import->type === 'inventory') {
            $records = \App\Models\Tenant\TenantInventoryItem::whereIn('id', $ids)
                ->get(['id', 'sku', 'name'])->keyBy('id');
            $label = fn ($r) => trim(($r->sku ? $r->sku . ' — ' : '') . ($r->name ?: 'Unnamed item'));
        } else {
            $records = \App\Models\Tenant\TenantCustomer::whereIn('id', $ids)
                ->get(['id', 'first_name', 'last_name', 'email'])->keyBy('id');
            $label = fn ($r) => trim(($r->first_name . ' ' . $r->last_name)) ?: ($r->email ?: 'Unnamed');
        }

        $out = [];
        foreach ($rows as $row) {
            $rec = $records[$row->record_id] ?? null;
            $out[] = [
                'id'    => $row->record_id,
                'label' => $rec ? $label($rec) : 'Record no longer exists',
                'sub'   => $rec ? (string) ($rec->email ?? $rec->sku ?? '') : '',
            ];
        }

        return response()->json([
            'ok'    => true,
            'kind'  => $kind,
            'rows'  => $out,
            'total' => (int) $import->total($kind),
        ]);
    }

    /** MARKER-IMPORT-DRILLDOWN — read the error CSV back for on-screen display. */
    private function readErrorCsv(TenantImport $import, int $limit): array
    {
        if (! $import->error_path) return [];

        $abs = Storage::disk('local')->path($import->error_path);
        if (! is_readable($abs)) return [];

        $out = [];
        $h = fopen($abs, 'r');
        if (! $h) return [];

        $header = fgetcsv($h); // original columns + 'Why it was skipped'
        while (($row = fgetcsv($h)) !== false && count($out) < $limit) {
            $why = array_pop($row);
            // Show the first couple of populated cells so the row is
            // recognisable without dumping every column on screen.
            $bits = array_values(array_filter(array_map('trim', $row), fn ($v) => $v !== ''));
            $out[] = [
                'label' => implode(' · ', array_slice($bits, 0, 3)) ?: '(empty row)',
                'sub'   => (string) $why,
            ];
        }
        fclose($h);

        return $out;
    }

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
    /**
     * MARKER-IMPORT-PROGRESS — tiny JSON for the banner. Reads columns the
     * job already wrote; never counts anything, so polling stays cheap.
     */
    public function progress()
    {
        $this->guard();

        $import = \App\Models\Tenant\TenantImport::where('tenant_id', tenant()->id)
            ->whereIn('status', ['running', 'reversing'])
            ->orWhere(function ($q) {
                $q->where('tenant_id', tenant()->id)
                  ->whereIn('progress_stage', ['done', 'reversed', 'failed'])
                  ->whereNull('progress_seen_at')
                  ->where('updated_at', '>=', now()->subHours(6));
            })
            ->orderByDesc('updated_at')->first();

        // MARKER-CATALOG-IMPORT-ALL — a distributor catalog import is the
        // other long-running job a shop can start. Same banner, same shape.
        $batch = \App\Models\Tenant\CatalogChangeBatch::where('tenant_id', tenant()->id)
            ->where(function ($q) {
                // MARKER-CATALOG-PROGRESS-STAGE — progress_stage, not status:
                // the import service resets status each page, stage is ours.
                $q->where('progress_stage', 'importing')
                  ->orWhere(function ($q2) {
                      $q2->whereIn('progress_stage', ['done', 'failed'])
                         ->whereNull('progress_seen_at')
                         ->where('updated_at', '>=', now()->subHours(6));
                  });
            })
            ->orderByDesc('updated_at')->first();

        if ($batch && (! $import || $batch->updated_at > $import->updated_at)) {
            $running = $batch->progress_stage === 'importing'; // MARKER-CATALOG-PROGRESS-STAGE
            $res     = (array) ($batch->result ?? []);
            $code    = (string) (($batch->filter ?? [])['code'] ?? 'distributor');

            return response()->json([
                'active'  => true,
                'id'      => $batch->id,
                'kind'    => 'catalog',
                'running' => $running,
                'stage'   => $batch->progress_stage,
                'done'    => (int) $batch->progress_done,
                'total'   => (int) $batch->progress_total,
                'pct'     => $batch->progress_total > 0
                    ? min(100, (int) round($batch->progress_done / $batch->progress_total * 100)) : 0,
                'label'   => 'Importing ' . $code . ' catalog',
                'result'  => $running ? null : ($batch->progress_stage === 'failed'
                    ? 'Catalog import stopped — what imported is on the batch and can still be undone'
                    : 'Catalog import finished · ' . number_format((int) ($res['created'] ?? 0)) . ' added, '
                        . number_format((int) ($res['merged'] ?? 0)) . ' merged, '
                        . number_format((int) ($res['skipped'] ?? 0)) . ' already had'),
                'href'    => route('tenant.distributors.attention.history'),
            ]);
        }

        if (! $import) {
            return response()->json(['active' => false]);
        }

        $running = in_array($import->status, ['running', 'reversing'], true);
        $totals  = (array) $import->totals;
        $rev     = (array) ($totals['reversal'] ?? []);

        return response()->json([
            'active'   => true,
            'id'       => $import->id,
            'running'  => $running,
            'stage'    => $import->progress_stage,
            'done'     => (int) $import->progress_done,
            'total'    => (int) $import->progress_total,
            'pct'      => $import->progress_total > 0
                ? min(100, (int) round($import->progress_done / $import->progress_total * 100)) : 0,
            'label'    => $import->status === 'reversing' ? 'Reversing import' : 'Importing ' . $import->type,
            'result'   => $running ? null : match ($import->progress_stage) {
                'reversed' => 'Reverse finished · ' . number_format((int) ($rev['deleted'] ?? 0)) . ' deleted, '
                    . number_format((int) ($rev['restored'] ?? 0)) . ' restored'
                    . (($rev['kept'] ?? 0) ? ', ' . number_format((int) $rev['kept']) . ' kept (used since)' : ''),
                'failed'   => $import->failure_reason ?: 'Stopped — press Reverse again to continue',
                default    => 'Import finished · ' . number_format((int) ($totals['created'] ?? 0)) . ' added, '
                    . number_format((int) ($totals['updated'] ?? 0)) . ' updated',
            },
            'href'     => route('tenant.imports.show', $import->id),
        ]);
    }

    /** MARKER-IMPORT-PROGRESS — dismiss the finished banner. */
    public function progressSeen(string $id)
    {
        $this->guard();
        \App\Models\Tenant\TenantImport::where('tenant_id', tenant()->id)
            ->where('id', $id)->update(['progress_seen_at' => now()]);

        // MARKER-CATALOG-IMPORT-ALL — the banner shows both kinds, so dismiss
        // has to reach both. Ids are uuids; only one of these can match.
        \App\Models\Tenant\CatalogChangeBatch::where('tenant_id', tenant()->id)
            ->where('id', $id)->update(['progress_seen_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function reverse(string $id)
    {
        $this->guard();
        $import = $this->find($id);

        if ($import->status !== 'done') {
            return back()->with('error', 'Only a finished import can be reversed.');
        }

        // MARKER-IMPORT-PROGRESS — queued. This used to run inline: every row
        // undone in the web request, with fifteen unbatched queries apiece.
        $pending = \App\Models\Tenant\TenantImportRow::where('import_id', $import->id)
            ->where('tenant_id', tenant()->id)->whereNull('reversed_at')->count();

        $import->update([
            'status'         => 'reversing',
            'progress_done'  => 0,
            'progress_total' => $pending,
            'progress_stage' => 'reversing',
            'progress_seen_at' => null,
        ]);

        \App\Jobs\ReverseImportJob::dispatch(tenant()->id, $import->id);

        $msg = 'Reversing ' . number_format($pending) . ' rows in the background — the banner at the top tracks it.';
        if (false) {
        }

        return redirect()->route('tenant.imports.show', $import->id)->with('success', $msg);
    }
}
