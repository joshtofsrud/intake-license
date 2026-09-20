@extends('layouts.tenant.app')

@section('title', 'Preview import')

@section('content')
@include('tenant.imports._styles')
@include('tenant.imports._progress')

{{-- MARKER-IMPORT-MATCH — rebuilt around the ledger. Every tile opens the
     rows behind it; possible duplicates are reviewed here and the run will
     not start until each has a decision. Wording follows the import type. --}}
@php
  $c        = $result['counts'];
  $sing     = $nouns['singular'];
  $plur     = $nouns['plural'];
  $tagName  = $result['tag_name'] ?? null;
  $willTag  = $tagName ? ($c['will_tag'] ?? 0) : 0;
  $dupCount = (int) ($c['possible_duplicate'] ?? 0);
  $undecided = collect($dupes)->filter(fn ($d) => ! isset($decisions[(string) $d->line]))->count();
  $rowWrites = ($c['create'] ?? 0) + ($c['update'] ?? 0);
  $writes    = $rowWrites + $willTag;

  $cta = $rowWrites > 0 && $willTag > 0
      ? 'Import ' . number_format($rowWrites) . ' ' . Str::plural('row', $rowWrites) . ' · tag ' . number_format($willTag)
      : ($rowWrites > 0
          ? 'Import ' . number_format($rowWrites) . ' ' . Str::plural('row', $rowWrites)
          : ($willTag > 0
              ? 'Tag ' . number_format($willTag) . ' ' . Str::plural($sing, $willTag)
              : 'Import 0 rows'));

  $tiles = [
    'create'             => ['Will be created',    'ok'],
    'update'             => ['Will be updated',    'acc'],
    'unchanged'          => ['Already match',      'dim'],
    'possible_duplicate' => ['Possible duplicates','warn'],
    'skipped'            => ['Skipped',            'dim'],
    'unmatched'          => ['No match',           'dim'],
    'error'              => ['Errors',             'bad'],
  ];
@endphp

{{-- MARKER-IMPORT-PROGRESS-FIX — a preview that has not finished must not
     render as a file full of nothing. Until the job writes its ledger, the
     screen says so and the run button stays disabled. --}}
@php
  $previewing = in_array($import->progress_stage, ['previewing'], true)
                || (($c['create'] ?? 0) + ($c['update'] ?? 0) + ($c['unchanged'] ?? 0)
                    + ($c['skipped'] ?? 0) + ($c['unmatched'] ?? 0) + ($c['error'] ?? 0)
                    + $dupCount) === 0 && $import->status !== 'previewed';
@endphp

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">What to expect</h1>
    <p class="ia-page-subtitle">Nothing has been written yet. Every row below has been decided — click a count to see exactly which rows.</p>
  </div>
  <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
    <a href="{{ route('tenant.imports.ledger', $import->id) }}" class="ia-btn ia-btn--secondary ia-btn--sm">Download row-by-row CSV</a>
  </div>
</div>

@if(session('error'))
  <div class="ia-flash ia-flash--error">{{ session('error') }}</div>
@endif
@if(session('success'))
  <div class="ia-flash ia-flash--success">{{ session('success') }}</div>
@endif

<div class="imp-legend" style="margin-bottom:14px">
  <b>How a row is recognised.</b> {{ ucfirst($nouns['keys']) }}. A match on anything but the first key —
  or on the first key with a {{ $import->type === 'inventory' ? 'different item name' : 'different surname' }} —
  is a <b>possible duplicate</b> and is never merged without your say-so.
</div>

@if($previewing)
  <div class="imp-legend" style="border-color:rgba(225,180,94,.35)">
    <b>Still working out what will happen.</b> The file is being read and every row matched.
    The counts below fill in as it goes, and this page refreshes itself when it's done — nothing has been
    written and nothing can be until it finishes.
  </div>
@endif

<div class="imp-tiles">
  @foreach($tiles as $key => [$label, $tone])
    @php $n = (int) ($c[$key] ?? 0); @endphp
    <a class="imp-tile {{ $showOutcome === $key ? 'is-active' : '' }}"
       href="{{ $n > 0 ? route('tenant.imports.preview', ['id' => $import->id, 'rows' => $key]) : '#' }}"
       style="text-decoration:none;color:inherit{{ $n === 0 ? ';opacity:.45;pointer-events:none' : '' }}">
      <div class="k">{{ $label }}</div>
      <div class="v {{ $tone }}">{{ number_format($n) }}</div>
    </a>
  @endforeach
</div>

@if(!empty($result['newCategories']) || !empty($result['newVendors']) || $willTag)
  <div class="ia-card" style="margin-top:14px">
    <div class="ia-card-head"><span class="ia-card-title">Also created or applied by this run</span></div>
    <div class="ia-card-body" style="font-size:13px;line-height:1.7">
      @if(!empty($result['newCategories']))
        <div><b>{{ count($result['newCategories']) }} new {{ Str::plural('category', count($result['newCategories'])) }}:</b>
          {{ implode(', ', array_slice($result['newCategories'], 0, 20)) }}@if(count($result['newCategories']) > 20) … and {{ count($result['newCategories']) - 20 }} more @endif</div>
      @endif
      @if(!empty($result['newVendors']))
        <div><b>{{ count($result['newVendors']) }} new {{ Str::plural('vendor', count($result['newVendors'])) }}:</b>
          {{ implode(', ', array_slice($result['newVendors'], 0, 20)) }}</div>
      @endif
      @if($willTag)
        <div><b>Tag "{{ $tagName }}"</b> applied to {{ number_format($willTag) }} {{ Str::plural($sing, $willTag) }}.</div>
      @endif
    </div>
  </div>
@endif

<style>
  /* MARKER-IMPORT-CATS — same modal vocabulary as the vendor modal. */
  .imp-modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;align-items:center;justify-content:center;z-index:1000;padding:20px}
  .imp-modal-bg.open{display:flex}
  .imp-modal{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);width:100%}
  .pc-pill{font-size:10.5px;padding:2px 8px;border-radius:99px;border:0.5px solid var(--ia-border);color:var(--ia-text-muted);white-space:nowrap}
</style>

{{-- ==================================================== categories --}}
{{-- MARKER-IMPORT-MAP-CLEAN — render whenever a category column is mapped,
     even with nothing to show. A card that disappears reads as a missing
     feature; one that says "nothing to review" reads as an answer. --}}
@php
  $catColumnMapped = collect((array) ($import->mapping ?? []))
      ->contains(fn ($m) => (is_array($m) ? ($m['field'] ?? null) : $m) === 'category');
@endphp
@if($import->type === 'inventory' && ! count($catRows) && $catColumnMapped)
  <div class="ia-card" style="margin-top:14px">
    <div class="ia-card-head"><span class="ia-card-title">Categories in this file</span></div>
    <div class="ia-card-body">
      <div class="imp-hint">
        @if($previewing)
          Counting them now — this fills in when the check finishes.
        @else
          A category column is mapped, but this check found no values in it. Either the column is empty,
          or the check ran before category review existed — <b>Back to mapping</b> and check the file again
          to build the list.
        @endif
      </div>
    </div>
  </div>
@endif

@if($import->type === 'inventory' && count($catRows))
  @php
    $catNew      = collect($catRows)->where('new', true);
    $catExisting = collect($catRows)->where('new', false);
    $willCreate  = collect($catDecisions)->filter(fn ($v) => $v === 'create')->count();
    $willMap     = collect($catDecisions)->filter(fn ($v) => $v !== 'create' && $v !== 'off')->count();
    $undecidedCats = $catNew->reject(fn ($r) => isset($catDecisions[$r['path']]))->count();
    $offRows     = collect($catRows)->reject(fn ($r) => ($catDecisions[$r['path']] ?? 'off') !== 'off')->sum('rows');
  @endphp

  <div class="ia-card" style="margin-top:14px">
    <div class="ia-card-head"><span class="ia-card-title">Categories in this file</span></div>
    <div class="ia-card-body">
      <div class="imp-hint" style="margin-bottom:12px">
        The file names <b>{{ number_format(count($catRows)) }}</b> distinct categories.
        <b>{{ number_format($catExisting->count()) }}</b> match ones you already have and
        <b>{{ number_format($catNew->count()) }}</b> would be new. <b>Nothing is created until you say so</b> —
        anything you leave off still imports, it just lands in Uncategorised with the file's own wording kept,
        so the mapper can suggest from it later.
        @if($catCapped)<br><b style="color:#f0c46a">More than 2,000 distinct values.</b> That is usually a description column rather than a category column — worth checking the mapping.@endif
      </div>

      <div style="display:flex;gap:26px;align-items:center;flex-wrap:wrap">
        <div><div style="font-size:22px;font-weight:700">{{ number_format($catExisting->count()) }}</div><div class="imp-hint">match existing</div></div>
        <div><div style="font-size:22px;font-weight:700;color:#f0c46a">{{ number_format($catNew->count()) }}</div><div class="imp-hint">would be new</div></div>
        <div><div style="font-size:22px;font-weight:700;color:var(--ia-accent)">{{ number_format($willCreate) }}</div><div class="imp-hint">you've approved</div></div>
        <div style="margin-left:auto">
          <button type="button" class="ia-btn ia-btn--primary" onclick="document.getElementById('imp-cat-modal').classList.add('open')">
            Review {{ number_format(count($catRows)) }} categories
          </button>
        </div>
      </div>

      @if($undecidedCats > 0)
        <div class="imp-hint" style="margin-top:10px">
          {{ number_format($undecidedCats) }} new {{ Str::plural('category', $undecidedCats) }} with no decision yet — those go to Uncategorised as things stand.
        </div>
      @endif
    </div>
  </div>

  {{-- the review itself --}}
  <div class="imp-modal-bg" id="imp-cat-modal" role="dialog" aria-modal="true">
    <form method="POST" action="{{ route('tenant.imports.categories.save', $import->id) }}" class="imp-modal" style="max-width:920px;display:flex;flex-direction:column;max-height:92vh">
      @csrf
      <div style="padding:18px 22px 12px;border-bottom:0.5px solid var(--ia-border)">
        <h3 style="margin:0 0 4px;font-size:16px;font-weight:600">Categories this file would use</h3>
        <div style="font-size:12.5px;color:var(--ia-text-dim)">
          Approve the ones you want, map the rest onto categories you already have, or leave them off.
          Anything left off goes to Uncategorised.
        </div>
      </div>

      <div style="display:flex;gap:8px;align-items:center;padding:10px 22px;border-bottom:0.5px solid var(--ia-border);flex-wrap:wrap">
        <input type="text" class="imp-sel" id="imp-cat-filter" placeholder="Filter…" style="width:220px">
        <span class="imp-hint">{{ number_format(count($catRows)) }} rows, biggest first</span>
        <span style="margin-left:auto;display:flex;gap:6px;align-items:center">
          <button type="submit" name="all" value="create" class="ia-btn ia-btn--sm">Approve all</button>
          <button type="submit" name="all" value="off" class="ia-btn ia-btn--sm">Leave all off</button>
          <span class="imp-hint">or</span>
          <input type="number" name="min_rows" value="10" min="1" class="imp-sel" style="width:64px">
          <button type="submit" name="all" value="threshold" class="ia-btn ia-btn--sm">Approve those with N+ items</button>
        </span>
      </div>

      <div style="overflow:auto;flex:1">
        <table class="imp" id="imp-cat-table">
          <thead><tr>
            <th>Category in the file</th>
            <th style="width:90px;text-align:right">Items</th>
            <th style="width:110px">Status</th>
            <th style="width:340px">What to do</th>
          </tr></thead>
          <tbody>
            @foreach($catRows as $row)
              @php $d = $catDecisions[$row['path']] ?? ($row['new'] ? null : 'create'); @endphp
              <tr data-path="{{ Str::lower($row['path']) }}">
                <td>{{ $row['path'] }}</td>
                <td style="text-align:right;font-variant-numeric:tabular-nums">{{ number_format($row['rows']) }}</td>
                <td>
                  @if($row['new'])<span class="pc-pill" style="color:#f0c46a">New</span>
                  @else<span class="pc-pill">Exists</span>@endif
                </td>
                <td>
                  <select name="decision[{{ $row['path'] }}]" class="imp-sel" style="width:100%">
                    <option value="off" @selected($d === null || $d === 'off')>Leave off — Uncategorised</option>
                    <option value="create" @selected($d === 'create')>{{ $row['new'] ? 'Create it' : 'Use the one that matches' }}</option>
                    @foreach($existingCats as $ec)
                      <option value="{{ $ec->id }}" @selected((string) $d === (string) $ec->id)>Map to: {{ $ec->name }}</option>
                    @endforeach
                  </select>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <div style="padding:12px 22px;border-top:0.5px solid var(--ia-border);display:flex;gap:8px;align-items:center">
        <span class="imp-hint">
          <b style="color:var(--ia-text)">{{ number_format($willCreate) }}</b> to create ·
          {{ number_format($willMap) }} mapped ·
          {{ number_format($offRows) }} items to Uncategorised
        </span>
        <span style="margin-left:auto;display:flex;gap:8px">
          <button type="button" class="ia-btn ia-btn--secondary" onclick="document.getElementById('imp-cat-modal').classList.remove('open')">Close</button>
          <button type="submit" class="ia-btn ia-btn--primary">Save decisions</button>
        </span>
      </div>
    </form>
  </div>

  <script>
  (function () {
    var f = document.getElementById('imp-cat-filter');
    if (!f) return;
    f.addEventListener('input', function () {
      var q = this.value.toLowerCase();
      document.querySelectorAll('#imp-cat-table tbody tr').forEach(function (tr) {
        tr.style.display = tr.dataset.path.indexOf(q) === -1 ? 'none' : '';
      });
    });
    var bg = document.getElementById('imp-cat-modal');
    bg.addEventListener('click', function (e) { if (e.target === bg) bg.classList.remove('open'); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') bg.classList.remove('open'); });
  })();
  </script>
@endif

{{-- ================================================ possible duplicates --}}
@if($dupCount > 0)
  <form method="POST" action="{{ route('tenant.imports.matches.save', $import->id) }}" class="ia-card" style="margin-top:14px;border-color:rgba(240,196,106,.35)">
    @csrf
    <div class="ia-card-head">
      <span class="ia-card-title">Possible duplicates — {{ $undecided }} of {{ $dupCount }} still need a decision</span>
      <div style="margin-left:auto;display:flex;gap:6px;align-items:center;font-size:12px">
        <span style="color:var(--ia-text-dim)">Set all to</span>
        <button type="submit" name="all" value="merge"  class="ia-btn ia-btn--sm">Merge</button>
        <button type="submit" name="all" value="create" class="ia-btn ia-btn--sm">Create new</button>
        <button type="submit" name="all" value="skip"   class="ia-btn ia-btn--sm">Skip</button>
      </div>
    </div>
    <div class="ia-card-body">
      <div class="imp-hint" style="margin-bottom:12px">
        Each row below matched an existing {{ $sing }}, but not confidently. <b>Merge</b> updates the existing one
        with this row. <b>Create new</b> makes a separate {{ $sing }}. <b>Skip</b> leaves both alone.
        The run cannot start until every row here has a decision.
      </div>

      <div class="imp-scroll">
        <table class="imp">
          <thead><tr>
            <th style="width:60px">Line</th>
            <th>In the file</th>
            <th>Already in Intake</th>
            <th style="width:260px">Why it's uncertain</th>
            <th style="width:230px">Decision</th>
          </tr></thead>
          <tbody>
            @foreach($dupes as $d)
              @php $cells = (array) $d->cells; $dec = $decisions[(string) $d->line] ?? null; @endphp
              <tr>
                <td class="mono">{{ $d->line }}</td>
                <td style="font-size:12.5px">
                  {{ Str::limit(implode(' · ', array_filter(array_slice($cells, 0, 6), fn ($v) => trim((string) $v) !== '')), 110) }}
                </td>
                <td style="font-size:12.5px">
                  <b>{{ $d->matched_label ?: '—' }}</b>
                  <div class="imp-hint">matched on {{ strtoupper((string) $d->match_key) }}</div>
                </td>
                <td style="font-size:12px;color:var(--ia-text-muted)">{{ $d->reason }}</td>
                <td>
                  <div style="display:flex;gap:10px;font-size:12.5px;flex-wrap:wrap">
                    <label><input type="radio" name="decision[{{ $d->line }}]" value="merge"  @checked($dec === 'merge')> Merge</label>
                    <label><input type="radio" name="decision[{{ $d->line }}]" value="create" @checked($dec === 'create')> Create new</label>
                    <label><input type="radio" name="decision[{{ $d->line }}]" value="skip"   @checked($dec === 'skip')> Skip</label>
                  </div>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <div style="margin-top:12px;display:flex;gap:8px;align-items:center">
        <button type="submit" class="ia-btn ia-btn--primary">Save decisions</button>
        <span class="imp-hint">Saving re-runs the preview so the counts reflect your choices.</span>
      </div>
    </div>
  </form>
@endif

{{-- ================================================ rows behind a tile --}}
@if($showOutcome && count($ledgerRows))
  <div class="ia-card" style="margin-top:14px">
    <div class="ia-card-head">
      <span class="ia-card-title">{{ $tiles[$showOutcome][0] ?? ucfirst($showOutcome) }} — {{ number_format(count($ledgerRows)) }} {{ count($ledgerRows) === 500 ? '(first 500)' : '' }}</span>
      <a href="{{ route('tenant.imports.preview', $import->id) }}" style="margin-left:auto;font-size:12px">Close</a>
    </div>
    <div class="imp-scroll">
      <table class="imp">
        <thead><tr>
          <th style="width:60px">Line</th>
          <th>{{ $nouns['key'] }}</th>
          <th>Name</th>
          <th>Matched</th>
          <th style="width:320px">Reason / changes</th>
        </tr></thead>
        <tbody>
          @foreach($ledgerRows as $r)
            @php
              $cells = (array) $r->cells;
              $keyIdx = null; $nameIdx = null;
              foreach ((array) ($import->mapping ?? []) as $ci => $m) {
                $f = is_array($m) ? ($m['field'] ?? null) : $m;
                if ($f === ($import->type === 'inventory' ? 'sku' : 'email')) { $keyIdx = (int) $ci; }
                if ($f === ($import->type === 'inventory' ? 'name' : 'first_name')) { $nameIdx = (int) $ci; }
              }
            @endphp
            <tr>
              <td class="mono">{{ $r->line }}</td>
              <td class="mono">{{ $keyIdx !== null ? ($cells[$keyIdx] ?? '—') : '—' }}</td>
              <td>{{ $nameIdx !== null ? ($cells[$nameIdx] ?? '—') : '—' }}</td>
              <td style="font-size:12.5px">{{ $r->matched_label ?: '—' }}@if($r->match_key) <span class="imp-hint">on {{ strtoupper($r->match_key) }}</span>@endif</td>
              <td style="font-size:12px">
                @if($r->reason)<div class="{{ $r->outcome === 'error' ? 'imp-err' : '' }}">{{ $r->reason }}</div>@endif
                @if($r->outcome === 'update' && $r->changes)<span class="imp-changes">{{ implode(', ', (array) $r->changes) }}</span>@endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@endif

<form method="POST" action="{{ route('tenant.imports.run', $import->id) }}" class="imp-foot">
  @csrf
  <a href="{{ route('tenant.imports.map', $import->id) }}" class="ia-btn ia-btn--secondary">Back to mapping</a>
  @if($undecided > 0)
    <span style="font-size:12px;color:#f0c46a;align-self:center;text-align:center">
      {{ $undecided }} possible {{ Str::plural('duplicate', $undecided) }} still {{ $undecided === 1 ? 'needs' : 'need' }} a decision above.
    </span>
    <button type="submit" class="ia-btn ia-btn--primary" disabled>{{ $cta }}</button>
  @elseif($previewing)
    <span style="font-size:12px;color:var(--ia-text-dim);align-self:center;text-align:center">
      Waiting for the preview to finish.
    </span>
    <button type="submit" class="ia-btn ia-btn--primary" disabled>Import</button>
  @elseif($writes === 0)
    <span style="font-size:12px;color:var(--ia-text-dim);align-self:center;text-align:center">
      @if(($c['error'] ?? 0) > 0)
        Nothing can be written yet — every row has an error. Fix the file and upload it again.
      @elseif(($c['unchanged'] ?? 0) > 0)
        Every row already matches what's in Intake, and no tag is set.
      @else
        Nothing to write — every row already matches what's in Intake.
      @endif
    </span>
    <button type="submit" class="ia-btn ia-btn--primary" disabled>{{ $cta }}</button>
  @else
    <button type="submit" class="ia-btn ia-btn--primary">{{ $cta }}</button>
  @endif
</form>
@endsection
