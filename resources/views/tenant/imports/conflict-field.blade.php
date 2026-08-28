@extends('layouts.tenant.app')
@php $pageTitle = 'Merge review'; @endphp
{{-- MARKER-IMPORT-MERGE --}}

@section('content')
@include('tenant.imports._styles')

@php
  $ruleText = ['csv' => 'Use CSV', 'keep' => 'Keep mine', 'blank' => 'Only fill blanks'][$rule] ?? $rule;
  $pages    = (int) max(1, ceil($total / $per));
@endphp

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">{{ $label }}</h1>
    <p class="ia-page-subtitle">{{ number_format($total) }} {{ Str::plural('row', $total) }} differ ·
      field rule: <b>{{ $ruleText }}</b> — a choice made here beats it.</p>
  </div>
</div>

<form method="POST" action="{{ route('tenant.imports.conflict.field.save', [$import->id, $field]) }}">
  @csrf
  <input type="hidden" name="page" value="{{ $page }}">
  <input type="hidden" name="q" value="{{ $filter }}">

  <div class="ia-card">
    <div class="ia-card-head">
      <span class="ia-card-title">Row by row</span>
      <span style="margin-left:auto">
        <input class="imp-sel" form="imp-filter" type="search" name="q" value="{{ $filter }}"
               placeholder="Filter by name…" style="width:220px">
      </span>
    </div>

    @if($rows)
      <table class="imp">
        <thead><tr>
          <th style="width:60px">Line</th><th style="width:240px">Record</th>
          <th>In Intake</th><th>In your file</th><th style="text-align:right;width:200px">This row</th>
        </tr></thead>
        <tbody>
          @foreach($rows as $r)
            @php $ov = $overrides[(string) $r['line']] ?? ''; @endphp
            <tr>
              <td class="mono">{{ $r['line'] }}</td>
              <td>{{ $r['record'] }}</td>
              <td class="mono">{{ $importer->displayValue($field, $r['current']) }}</td>
              <td class="mono">{{ $importer->displayValue($field, $r['incoming']) }}</td>
              <td style="text-align:right">
                <span class="imp-rowseg">
                  <label><input type="radio" name="ov[{{ $r['line'] }}]" value="" @checked($ov === '')>Rule</label>
                  <label><input type="radio" name="ov[{{ $r['line'] }}]" value="csv" @checked($ov === 'csv')>CSV</label>
                  <label><input type="radio" name="ov[{{ $r['line'] }}]" value="keep" @checked($ov === 'keep')>Mine</label>
                </span>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @else
      <div class="imp-empty">Nothing matches that filter.</div>
    @endif

    <div class="imp-legend">
      <b style="color:var(--ia-text)">Only this page is saved.</b>
      Rows left on <b>Rule</b> follow the field decision ({{ $ruleText }}) and store nothing.
      Choices are kept with the import, so leaving and coming back doesn't lose them — but rows on
      other pages are untouched by this button, so save before you page.
    </div>
  </div>

  <div class="imp-foot">
    <a class="ia-btn" href="{{ route('tenant.imports.conflicts', $import->id) }}">&larr; Back to all fields</a>
    <button type="submit" class="ia-btn ia-btn--primary">Save row choices</button>
  </div>

  @if($pages > 1)
    <div class="imp-pager">
      <span>Page {{ $page }} of {{ $pages }}</span>
      @if($page > 1)
        <a href="{{ route('tenant.imports.conflict.field', [$import->id, $field, 'page' => $page - 1, 'q' => $filter]) }}">&larr; Previous</a>
      @endif
      @if($page < $pages)
        <a href="{{ route('tenant.imports.conflict.field', [$import->id, $field, 'page' => $page + 1, 'q' => $filter]) }}">Next &rarr;</a>
      @endif
    </div>
  @endif
</form>

<form id="imp-filter" method="GET" action="{{ route('tenant.imports.conflict.field', [$import->id, $field]) }}"></form>
@endsection
