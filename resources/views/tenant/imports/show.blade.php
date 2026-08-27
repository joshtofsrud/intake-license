@extends('layouts.tenant.app')
@php $pageTitle = 'Import result'; @endphp
{{-- MARKER-IMPORT1 --}}

@section('content')
@include('tenant.imports._styles')

@if(session('error'))<div class="ia-flash ia-flash--error" style="margin-bottom:14px">{{ session('error') }}</div>@endif

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">
      {{ $import->status === 'done' ? 'Import finished' : 'Import ' . $import->status }}
    </h1>
    <p class="ia-page-subtitle mono">{{ $import->original_filename }}</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.customers.index') }}" class="ia-btn ia-btn--secondary">View customers</a>
    <a href="{{ route('tenant.imports.index') }}" class="ia-btn ia-btn--primary">Done</a>
  </div>
</div>

@if($import->status === 'failed')
  {{-- MARKER-CONSENT-IMPORT-FIX — a failed run with no recorded reason used to
       render an empty box, which tells nobody anything. --}}
  <div class="ia-flash ia-flash--error">
    @if(trim((string) $import->failure_reason) !== '')
      {{ $import->failure_reason }}
    @else
      This import stopped before it finished and no reason was recorded.
      Nothing was imported. Please send this import's reference
      (<span class="mono">{{ $import->id }}</span>) to support so the cause can be found.
    @endif
  </div>
@elseif($import->status === 'done')
  <div class="ia-flash ia-flash--success">
    {{ number_format($import->total('created') + $import->total('updated')) }} rows imported.
  </div>
  {{-- MARKER-CONSENT-IMPORT-FIX — the next step nobody would otherwise know about. --}}
  @if($import->type === 'customers' && $import->total('created') > 0)
    <div class="ia-flash ia-flash--info" style="margin-top:10px">
      <b>These customers can't receive campaigns yet.</b> Imported contacts start
      without marketing permission. Confirm it once for the whole list on
      <a href="{{ route('tenant.consent.index') }}">Contacts &amp; consent</a>.
    </div>
  @endif
@endif

{{-- MARKER-IMPORT-DRILLDOWN — the three tiles with records behind them open
     a detail panel; the rest are counts with nothing stored per row, and say
     so on hover rather than pretending to be clickable. --}}
<div class="imp-tiles">
  <button type="button" class="imp-tile imp-tile--go" data-kind="created" title="See which records were created">
    <div class="k">Created</div><div class="v ok">{{ number_format($import->total('created')) }}</div></button>
  <button type="button" class="imp-tile imp-tile--go" data-kind="updated" title="See which records were updated">
    <div class="k">Updated</div><div class="v acc">{{ number_format($import->total('updated')) }}</div></button>
  <div class="imp-tile" title="Rows that matched an existing record with nothing to change">
    <div class="k">Already matched</div><div class="v dim">{{ number_format($import->total('unchanged')) }}</div></div>
  <div class="imp-tile" title="Rows deliberately left alone by the merge rules">
    <div class="k">Skipped</div><div class="v dim">{{ number_format($import->total('skipped')) }}</div></div>
  <div class="imp-tile" title="Rows with no existing record to update, in update-only mode">
    <div class="k">No match</div><div class="v dim">{{ number_format($import->total('unmatched')) }}</div></div>
  <button type="button" class="imp-tile imp-tile--go" data-kind="errors" title="See what went wrong, row by row">
    <div class="k">Errors</div><div class="v bad">{{ number_format($import->total('errors')) }}</div></button>
</div>

{{-- Detail panel — opens under the tiles, no page load. --}}
<div class="ia-card imp-detail" id="imp-detail" style="display:none">
  <div class="ia-card-head">
    <span class="ia-card-title" id="imp-detail-title">Detail</span>
    <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" style="margin-left:auto" id="imp-detail-close">Close</button>
  </div>
  <div class="ia-card-body">
    <div id="imp-detail-body" class="imp-hint">Loading…</div>
  </div>
</div>

{{-- MARKER-IMPORT2 — reverse this import --}}
@if($import->status === 'done')
  @php $rev = ($import->totals['reversal'] ?? null); @endphp
  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">Reverse this import</span></div>
    <div class="ia-card-body">
      <p class="imp-hint" style="margin-bottom:12px">
        Deletes what this import created and puts back what it changed. Anything that has been
        <b>used since</b> — sold, transferred, put on a ticket — is kept rather than deleted, and
        you'll be told which. Stock is corrected with a counter-movement, so the history stays intact.
      </p>
      <form method="POST" action="{{ route('tenant.imports.reverse', $import->id) }}"
            onsubmit="return confirm('Reverse this import? Records that have been used since will be kept.')">
        @csrf
        <button type="submit" class="ia-btn ia-btn--secondary">Reverse import</button>
      </form>
    </div>
  </div>
@elseif($import->status === 'reversed')
  @php $rev = $import->totals['reversal'] ?? []; @endphp
  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">Reversed</span></div>
    <div class="ia-card-body imp-hint">
      {{ $rev['deleted'] ?? 0 }} deleted · {{ $rev['restored'] ?? 0 }} restored ·
      {{ $rev['stock_reversed'] ?? 0 }} stock changes undone
      @if(($rev['kept'] ?? 0) > 0)
        <div style="margin-top:8px;color:var(--ia-text)">{{ $rev['kept'] }} kept because they'd been used since:</div>
        @foreach(array_slice($rev['keptDetail'] ?? [], 0, 20) as $k)
          <div style="font-size:11.5px">{{ $k['type'] }} — {{ $k['why'] }}</div>
        @endforeach
      @endif
    </div>
  </div>
@endif

@if($import->error_path)
  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">Rows that didn't import</span>
      <a href="{{ route('tenant.imports.errors', $import->id) }}"
         class="ia-btn ia-btn--secondary ia-btn--sm" style="margin-left:auto">Download as CSV</a></div>
    <div class="ia-card-body imp-hint">
      Keeps your original columns and adds a reason column, so you can fix it in the spreadsheet
      and import that file straight back.
    </div>
  </div>
@endif

{{-- MARKER-IMPORT-DRILLDOWN --}}
@push('styles')
<style>
  button.imp-tile{font:inherit;text-align:left;cursor:pointer;width:100%}
  button.imp-tile:hover{border-color:var(--ia-text-muted)}
  .imp-tile--go .k::after{content:' ›';opacity:.5}
  .imp-detail{margin-top:-4px}
  .imp-dt-row{display:flex;justify-content:space-between;gap:14px;padding:8px 0;border-bottom:.5px solid var(--ia-border);font-size:13px}
  .imp-dt-row:last-child{border-bottom:none}
  .imp-dt-sub{color:var(--ia-text-dim);font-size:12px;text-align:right;max-width:55%}
</style>
@endpush

@push('scripts')
<script>
(function () {
  var card  = document.getElementById('imp-detail');
  var title = document.getElementById('imp-detail-title');
  var body  = document.getElementById('imp-detail-body');
  if (!card) return;

  var BASE = @json(route('tenant.imports.detail', $import->id));
  var LABELS = { created: 'Records created', updated: 'Records updated', errors: "Rows that didn't import" };

  function close() { card.style.display = 'none'; }
  document.getElementById('imp-detail-close').addEventListener('click', close);

  document.querySelectorAll('.imp-tile--go').forEach(function (t) {
    t.addEventListener('click', function () {
      var kind = t.dataset.kind;
      title.textContent = LABELS[kind] || 'Detail';
      body.textContent  = 'Loading…';
      card.style.display = '';
      card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

      fetch(BASE + '?kind=' + encodeURIComponent(kind), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) { body.textContent = d.error || 'Could not load that.'; return; }
          if (!d.rows.length) { body.textContent = 'Nothing to show here.'; return; }

          body.innerHTML = '';
          d.rows.forEach(function (row) {
            var el = document.createElement('div');
            el.className = 'imp-dt-row';
            var a = document.createElement('span');
            a.textContent = row.label;
            var b = document.createElement('span');
            b.className = 'imp-dt-sub';
            b.textContent = row.sub || '';
            el.appendChild(a); el.appendChild(b);
            body.appendChild(el);
          });

          if (d.total > d.rows.length) {
            var note = document.createElement('div');
            note.className = 'imp-hint';
            note.style.marginTop = '10px';
            note.textContent = 'Showing the first ' + d.rows.length + ' of ' + d.total + '.';
            body.appendChild(note);
          }
        })
        .catch(function () { body.textContent = 'Could not load that.'; });
    });
  });
})();
</script>
@endpush

@endsection
