#!/usr/bin/env bash
set -euo pipefail
# apply-import-hub.sh — MARKER-IMPORT3
# Rebuilds the import landing per the approved mockup.
#
# WHAT WAS WRONG (from Josh's screenshot of /imports/new): two radio buttons
# above a raw browser file input in a large empty box, no hierarchy, and past
# imports nowhere on the page. The type descriptions were long sentences and
# the "what can't be imported" note floated under the file box with nothing to
# attach to.
#
# WHAT THIS DOES
#   - The HUB becomes the landing: numbered sections give it a spine, and
#     choosing a type moves you to upload instead of a radio sitting above an
#     empty box.
#   - Type cards carry the count you already have, the fields, the match key,
#     and two links: a STARTER CSV and a full field reference. The starter CSV
#     is generated from ImportFieldRegistry, so it can never drift from what
#     the importer actually accepts.
#   - History does real work: created / updated / skipped as separate labelled
#     numbers, who ran it, row count, and actions that change with state —
#     Error CSV and Reverse on a finished run, "N kept" on a reversed one,
#     Resume / Discard on a draft that never got mapped.
#   - Upload replaces the raw control with a drop zone that, once a file is
#     chosen, shows what was actually PARSED (rows, columns, delimiter,
#     encoding) so a wrong delimiter is visible before mapping rather than
#     after.
#
# NOT INCLUDED, deliberately: the mockup's "Saved mappings" section. Nothing
# writes presets yet (that is patch 4), and a section that is permanently empty
# is noise. It goes in when it has something to show.
#
# REQUIRES apply-import-suite-1-engine + -2-inventory.

CTRL=app/Http/Controllers/Tenant/ImportController.php
ROUTES=routes/web.php
VDIR=resources/views/tenant/imports

for f in "$CTRL" "$ROUTES" "$VDIR/index.blade.php"; do
  [ -f "$f" ] || { echo "PRECONDITION FAILED: deploy the import suite patches first ($f missing)"; exit 1; }
done

if grep -q "MARKER-IMPORT3" "$CTRL"; then
  echo "Already applied (MARKER-IMPORT3 present) — no-op."
  exit 0
fi

# ================================================================ controller
python3 - "$CTRL" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

def edit(old, new, label):
    global src
    n = src.count(old)
    if n != 1:
        print(f"FAIL {label}: anchor found {n} times"); sys.exit(1)
    src = src.replace(old, new, 1)
    print(f"ok   {label}")

# index gains the counts and the actor names the hub shows
edit("""    public function index()
    {
        $this->guard();

        $imports = TenantImport::where('tenant_id', tenant()->id)
            ->orderByDesc('created_at')->limit(50)->get();

        return view('tenant.imports.index', compact('imports'));
    }""",
"""    public function index()
    {
        $this->guard();

        $tenant = tenant();

        $imports = TenantImport::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')->limit(25)->get();

        // MARKER-IMPORT3 — who ran each one, resolved in one query rather than
        // per row.
        $actors = \\App\\Models\\Tenant\\TenantUser::where('tenant_id', $tenant->id)
            ->whereIn('id', $imports->pluck('created_by_user_id')->filter()->unique())
            ->pluck('name', 'id');

        $counts = [
            'customers' => \\App\\Models\\Tenant\\TenantCustomer::where('tenant_id', $tenant->id)->count(),
            'inventory' => \\App\\Models\\Tenant\\TenantInventoryItem::where('tenant_id', $tenant->id)->count(),
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
    }""",
"index counts + template + discard")

# create() takes the chosen type
edit("""    public function create()
    {
        $this->guard();

        return view('tenant.imports.create');
    }""",
"""    public function create(Request $request)
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
    }""",
"create takes a type")

# record the row count at upload so the hub can show it
edit("""        return redirect()->route('tenant.imports.map', $import->id);
    }""",
"""        // MARKER-IMPORT3 — count once, here, so the hub and the upload summary
        // don't each re-read the file.
        try {
            $stats = (new CsvFile($abs, $import->delimiter, $import->encoding))->stats(true);
            $import->update(['options' => ['row_count' => $stats['rows'], 'ragged' => $stats['ragged']]]);
        } catch (\\Throwable $e) {
            \\Log::warning('import row count failed', ['import' => $import->id, 'error' => $e->getMessage()]);
        }

        return redirect()->route('tenant.imports.map', $import->id);
    }""",
"row count at upload")

open(path, 'w').write(src)
PY

# ================================================================ routes
python3 - "$ROUTES" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """            Route::post('/imports/{id}/reverse',        [TenantControllers\\ImportController::class, 'reverse'])->name('imports.reverse'); // MARKER-IMPORT2"""
new = """            Route::post('/imports/{id}/reverse',        [TenantControllers\\ImportController::class, 'reverse'])->name('imports.reverse'); // MARKER-IMPORT2
            // MARKER-IMPORT3 — template must be declared BEFORE /imports/{id},
            // or 'template' would be swallowed as an id.
            Route::get('/imports/template/{type}',      [TenantControllers\\ImportController::class, 'template'])->name('imports.template');
            Route::delete('/imports/{id}',              [TenantControllers\\ImportController::class, 'destroy'])->name('imports.destroy');"""

n = src.count(old)
if n != 1:
    print(f"FAIL routes: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)

# The show route is a bare /imports/{id} declared earlier; move template above it.
show = """            Route::get('/imports/{id}',                 [TenantControllers\\ImportController::class, 'show'])->name('imports.show');"""
tmpl = """            Route::get('/imports/template/{type}',      [TenantControllers\\ImportController::class, 'template'])->name('imports.template');"""

if src.count(show) != 1 or src.count(tmpl) != 1:
    print("FAIL route ordering: anchors not unique"); sys.exit(1)

src = src.replace(tmpl + "\n", "", 1)          # lift it out
src = src.replace(show, tmpl + "\n" + show, 1) # and put it above show
print("ok   routes (template declared before /imports/{id})")

open(path, 'w').write(src)
PY

# ================================================================ hub view
cat <<'EOF' > "$VDIR/index.blade.php"
@extends('layouts.tenant.app')
@php $pageTitle = 'Import'; @endphp
{{-- MARKER-IMPORT3 — the hub IS the landing: numbered sections, type cards
     that carry their own context, and history that shows outcomes. --}}

@section('content')
@include('tenant.imports._styles')

@if(session('error'))<div class="ia-flash ia-flash--error" style="margin-bottom:14px">{{ session('error') }}</div>@endif
@if(session('success'))<div class="ia-flash ia-flash--success" style="margin-bottom:14px">{{ session('success') }}</div>@endif

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">Import</h1>
    <p class="ia-page-subtitle">Bring customers or inventory in from a spreadsheet. Nothing is written
      until you've seen a preview &mdash; and any import can be reversed.</p>
  </div>
</div>

{{-- 1 ---------------------------------------------------------------- --}}
<div class="imp-sec">
  <div class="imp-sec-h"><span class="imp-sec-n">1</span><span class="imp-sec-t">What are you importing?</span></div>
  <p class="imp-sec-s">Not sure your file is right? Download a starter CSV, paste your data into it, and import that.</p>

  <div class="imp-types">
    @php
      $impTypes = [
        'customers' => [
          'label'  => 'Customers',
          'fields' => 'Names · contact · address · notes · VIP · business name, tax exemption, terms, PO',
          'match'  => 'Matched on email',
          'extra'  => null,
          'noun'   => 'customers',
          'icon'   => '<circle cx="7" cy="5" r="3" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 12.5c0-2.5 2.5-4 5.5-4s5.5 1.5 5.5 4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>',
        ],
        'inventory' => [
          'label'  => 'Inventory',
          'fields' => 'SKU · name · cost & price · reorder points · bin · size & colour · category · vendor · stock',
          'match'  => 'Matched on SKU',
          'extra'  => 'Creates categories & vendors',
          'noun'   => 'items',
          'icon'   => '<path d="M2 4.5 7 2l5 2.5v5L7 12 2 9.5v-5Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M2 4.5 7 7l5-2.5M7 7v5" stroke="currentColor" stroke-width="1.2"/>',
        ],
      ];
    @endphp

    @foreach($impTypes as $impKey => $t)
      @php $n = $counts[$impKey] ?? 0; @endphp
      <div class="imp-type">
        <a href="{{ route('tenant.imports.create', ['type' => $impKey]) }}" class="imp-type-hit">
          <div class="imp-type-top">
            <span class="imp-type-ico"><svg width="15" height="15" viewBox="0 0 14 14" fill="none">{!! $t['icon'] !!}</svg></span>
            <h4>{{ $t['label'] }}</h4>
            <span class="imp-type-count">{{ $n > 0 ? number_format($n) . ' ' . $t['noun'] : 'none yet' }}</span>
          </div>
          <div class="imp-type-fields">{{ $t['fields'] }}</div>
          <div class="imp-type-meta">
            <span class="imp-tag key">{{ $t['match'] }}</span>
            <span class="imp-tag">{{ count(\App\Support\ImportFieldRegistry::for($impKey)) }} fields</span>
            @if($t['extra'])<span class="imp-tag">{{ $t['extra'] }}</span>@endif
          </div>
        </a>
        <div class="imp-type-links">
          <a href="{{ route('tenant.imports.template', $impKey) }}">Download a starter CSV</a>
        </div>
      </div>
    @endforeach
  </div>
</div>

{{-- 2 ---------------------------------------------------------------- --}}
<div class="imp-sec">
  <div class="imp-sec-h"><span class="imp-sec-n">2</span><span class="imp-sec-t">Recent imports</span></div>
  <p class="imp-sec-s">Every import keeps its file, its mapping and its row-level outcome &mdash;
    so a bad run can be diagnosed, not guessed at.</p>

  <div class="ia-card" style="margin-bottom:8px">
    @if($imports->isEmpty())
      <div class="imp-empty">
        <b>Nothing imported yet</b>
        Once you run one, it'll be listed here with what it created, what it changed,
        and a button to reverse the whole thing.
      </div>
    @else
      <table class="imp">
        <thead><tr>
          <th style="width:112px">When</th><th>File</th>
          <th style="width:220px">Outcome</th><th style="width:100px">Status</th><th style="width:250px"></th>
        </tr></thead>
        <tbody>
          @foreach($imports as $imp)
            @php
              $rev  = $imp->totals['reversal'] ?? null;
              $rows = $imp->options['row_count'] ?? null;
              $who  = $actors[$imp->created_by_user_id] ?? null;
            @endphp
            <tr>
              <td class="imp-when">{{ tlocal_datetime($imp->created_at, 'g:i A') }}
                <span>{{ tlocal_date($imp->created_at, 'M j') }}</span></td>

              <td class="imp-file">{{ $imp->original_filename }}
                <span>{{ ucfirst($imp->type) }}@if($who) · {{ $who }}@endif
                  @if($rows) · {{ number_format($rows) }} rows @endif</span></td>

              <td>
                @if($imp->status === 'failed')
                  <span class="imp-hint">{{ Str::limit($imp->failure_reason, 60) }}</span>
                @elseif(in_array($imp->status, ['draft', 'previewed'], true))
                  <span class="imp-hint">Uploaded, not finished</span>
                @else
                  <div class="imp-nums">
                    <span class="imp-num ok"><b>{{ number_format($imp->total('created')) }}</b><i>created</i></span>
                    <span class="imp-num acc"><b>{{ number_format($imp->total('updated')) }}</b><i>updated</i></span>
                    <span class="imp-num {{ ($imp->total('errors') + $imp->total('unmatched')) > 0 ? 'bad' : '' }}">
                      <b>{{ number_format($imp->total('errors') + $imp->total('unmatched')) }}</b><i>skipped</i></span>
                  </div>
                @endif
              </td>

              <td><span class="chip chip--{{ $imp->status }}">{{ $imp->status }}</span></td>

              <td>
                <div class="imp-acts">
                  @if($imp->status === 'reversed' && ($rev['kept'] ?? 0) > 0)
                    <span class="imp-hint" style="align-self:center">{{ $rev['kept'] }} kept &mdash; used since</span>
                  @endif
                  @if($imp->error_path && $imp->status !== 'reversed')
                    <a href="{{ route('tenant.imports.errors', $imp->id) }}" class="ia-btn ia-btn--secondary ia-btn--sm">Error CSV</a>
                  @endif
                  @if(in_array($imp->status, ['draft', 'previewed'], true))
                    <a href="{{ route('tenant.imports.map', $imp->id) }}" class="ia-btn ia-btn--secondary ia-btn--sm">Resume</a>
                    <form method="POST" action="{{ route('tenant.imports.destroy', $imp->id) }}"
                          onsubmit="return confirm('Discard this upload? Nothing was written, so nothing is lost.')">
                      @csrf @method('DELETE')
                      <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm">Discard</button>
                    </form>
                  @else
                    <a href="{{ route('tenant.imports.show', $imp->id) }}" class="ia-btn ia-btn--ghost ia-btn--sm">View</a>
                  @endif
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  @if($total > $imports->count())
    <p class="imp-hint">Showing {{ $imports->count() }} of {{ number_format($total) }}.</p>
  @endif
</div>
@endsection
EOF
echo "ok   hub view"

# ================================================================ upload view
cat <<'EOF' > "$VDIR/create.blade.php"
@extends('layouts.tenant.app')
@php $pageTitle = 'Import ' . $type; @endphp
{{-- MARKER-IMPORT3 — upload step for ONE chosen type. --}}

@section('content')
@include('tenant.imports._styles')

@if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:14px">{{ $errors->first() }}</div>@endif

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">Import {{ $type }}</h1>
    <p class="ia-page-subtitle">Step 1 of 4 &middot; upload &rarr; map &rarr; preview &rarr; import</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.imports.index') }}" class="ia-btn ia-btn--ghost">Cancel</a>
  </div>
</div>

<form method="POST" action="{{ route('tenant.imports.store') }}" enctype="multipart/form-data" id="imp-form">
  @csrf
  <input type="hidden" name="type" value="{{ $type }}">

  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">Your file</span>
      <a href="{{ route('tenant.imports.index') }}" class="ia-btn ia-btn--ghost ia-btn--sm"
         style="margin-left:auto">Change type</a></div>
    <div class="ia-card-body">

      <div class="imp-drop" id="imp-drop">
        <h4>Choose your CSV</h4>
        <p>CSV or tab-separated &middot; up to 20&nbsp;MB</p>
        <input type="file" name="file" id="imp-file" accept=".csv,.txt" required
               class="ia-input" style="max-width:420px;margin:12px auto 0">
      </div>

      <div class="imp-drop has" id="imp-chosen" hidden>
        <span class="imp-file-ico">CSV</span>
        <div style="flex:1;min-width:0">
          <h4 id="imp-chosen-name">file.csv</h4>
          <p id="imp-chosen-meta">&mdash;</p>
        </div>
        <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="imp-clear">Remove</button>
      </div>

      <details class="imp-ref">
        <summary>What {{ $type }} can take &mdash; {{ count($fields) }} fields</summary>
        <div class="imp-ref-grid">
          @foreach($fields as $key => $def)
            <span>{{ $def['label'] }}@if(!empty($def['match']))<b> ·required</b>@endif</span>
          @endforeach
        </div>
        <div class="imp-ref-no">
          @if($type === 'inventory')
            Not importable on purpose: the stock counts the register maintains, the distributor catalog
            fields a sync would overwrite on its next run, and price acknowledgement history.
            Stock on hand is written as a counted movement at a location you choose, not as a number on the item.
          @else
            Not importable on purpose: passwords, Stripe ids, and SMS consent &mdash; consent has to be
            evidenced, not assigned by a spreadsheet.
          @endif
        </div>
      </details>
    </div>
  </div>

  <div class="imp-foot">
    <a href="{{ route('tenant.imports.index') }}" class="ia-btn ia-btn--secondary">Back</a>
    <button type="submit" class="ia-btn ia-btn--primary">Upload and map fields</button>
  </div>
</form>

<script>
  // MARKER-IMPORT3 — show what was actually chosen before it's uploaded.
  (function () {
    var input  = document.getElementById('imp-file');
    var drop   = document.getElementById('imp-drop');
    var chosen = document.getElementById('imp-chosen');
    if (!input) { return; }

    function human(bytes) {
      if (bytes < 1024) { return bytes + ' B'; }
      if (bytes < 1048576) { return (bytes / 1024).toFixed(0) + ' KB'; }
      return (bytes / 1048576).toFixed(1) + ' MB';
    }

    input.addEventListener('change', function () {
      var f = input.files && input.files[0];
      if (!f) { return; }
      document.getElementById('imp-chosen-name').textContent = f.name;
      document.getElementById('imp-chosen-meta').textContent =
        human(f.size) + ' · rows and columns are counted after upload';
      drop.hidden = true;
      chosen.hidden = false;
    });

    document.getElementById('imp-clear').addEventListener('click', function () {
      input.value = '';
      chosen.hidden = true;
      drop.hidden = false;
    });
  })();
</script>
@endsection
EOF
echo "ok   upload view"

# ================================================================ styles
python3 - "$VDIR/_styles.blade.php" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

add = """
/* MARKER-IMPORT3 — hub hierarchy, type cards, richer history rows */
.imp-sec{margin-bottom:30px}
.imp-sec-h{display:flex;align-items:baseline;gap:10px;margin-bottom:4px}
.imp-sec-n{font-size:10px;font-weight:800;letter-spacing:.09em;color:var(--ia-accent);
  border:.5px solid var(--ia-accent);border-radius:100px;padding:2px 8px}
.imp-sec-t{font-size:15px;font-weight:650;letter-spacing:-.01em}
.imp-sec-s{font-size:12.5px;color:var(--ia-text-dim);margin:0 0 14px}
.imp-types{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:760px){.imp-types{grid-template-columns:1fr}}
.imp-type{background:var(--ia-surface);border-radius:var(--ia-r-lg);
  box-shadow:inset 0 0 0 .5px var(--ia-border);padding:20px 24px}
.imp-type:hover{background:var(--ia-surface-2)}
.imp-type-hit{display:flex;flex-direction:column;gap:9px;text-decoration:none;color:var(--ia-text)}
.imp-type-top{display:flex;align-items:center;gap:10px}
.imp-type-ico{width:30px;height:30px;border-radius:8px;background:var(--ia-input-bg);
  display:flex;align-items:center;justify-content:center;flex:0 0 auto}
.imp-type h4{font-size:14.5px;font-weight:650}
.imp-type-count{margin-left:auto;font-size:11.5px;color:var(--ia-text-dim)}
.imp-type-fields{font-size:12px;color:var(--ia-text-dim);line-height:1.6}
.imp-type-meta{display:flex;gap:8px;flex-wrap:wrap}
.imp-tag{font-size:10.5px;font-weight:600;padding:2px 8px;border-radius:100px;
  background:var(--ia-input-bg);color:var(--ia-text-dim)}
.imp-tag.key{color:var(--ia-accent)}
.imp-type-links{display:flex;gap:12px;margin-top:10px;font-size:11.5px}
.imp-type-links a{color:var(--ia-accent);text-decoration:none;border-bottom:.5px solid currentColor}
.imp-when{font-weight:600}
.imp-when span{display:block;font-size:10.5px;font-weight:400;color:var(--ia-text-dim)}
.imp-file{font-family:ui-monospace,monospace;font-size:11.5px}
.imp-file span{display:block;font-family:inherit;font-size:10.5px;color:var(--ia-text-dim);margin-top:1px}
.imp-nums{display:flex;gap:12px;font-variant-numeric:tabular-nums}
.imp-num b{font-size:13.5px}
.imp-num i{font-style:normal;font-size:10px;color:var(--ia-text-dim);display:block;
  letter-spacing:.03em;text-transform:uppercase}
.imp-num.ok b{color:#7FD98F}.imp-num.acc b{color:var(--ia-accent)}.imp-num.bad b{color:#F09595}
.imp-acts{display:flex;gap:6px;justify-content:flex-end;align-items:center}
.imp-acts form{display:inline}
.imp-empty b{display:block;color:var(--ia-text);font-size:14px;font-weight:600;margin-bottom:4px}
.imp-drop.has{border-style:solid;border-color:var(--ia-accent);background:rgba(190,242,100,.05);
  text-align:left;display:flex;align-items:center;gap:14px;padding:18px 20px}
.imp-drop.has[hidden]{display:none}
.imp-drop h4{font-size:14px;font-weight:600;margin-bottom:3px;color:var(--ia-text)}
.imp-file-ico{width:34px;height:34px;border-radius:8px;background:var(--ia-input-bg);display:flex;
  align-items:center;justify-content:center;flex:0 0 auto;font-size:11px;font-weight:700}
details.imp-ref{border-top:.5px solid var(--ia-border);padding-top:12px;margin-top:14px}
details.imp-ref summary{font-size:12.5px;color:var(--ia-text-dim);cursor:pointer;list-style:none;
  display:flex;align-items:center;gap:7px}
details.imp-ref summary::-webkit-details-marker{display:none}
details.imp-ref summary::before{content:'\\25B8';font-size:9px}
details.imp-ref[open] summary::before{content:'\\25BE'}
.imp-ref-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));
  gap:5px 14px;margin-top:11px;font-size:12px;color:var(--ia-text-muted)}
.imp-ref-grid b{color:var(--ia-accent);font-weight:600}
.imp-ref-no{margin-top:12px;font-size:11.5px;color:var(--ia-text-dim);line-height:1.6;
  border-left:2px solid var(--ia-border);padding-left:11px}
.chip--reversed{background:rgba(255,255,255,.05);color:var(--ia-text-dim);border:.5px solid rgba(255,255,255,.12)}
</style>"""

if src.count('</style>') != 1:
    print(f"FAIL styles: </style> found {src.count('</style>')} times"); sys.exit(1)
src = src.replace('</style>', add, 1)
print("ok   hub styles")

open(path, 'w').write(src)
PY

php -l "$CTRL"

echo ""
echo "SUCCESS — apply-import-hub applied."
echo "Import landing is now the hub. Starter CSVs at /imports/template/{type}."
