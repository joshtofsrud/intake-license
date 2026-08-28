@extends('layouts.tenant.app')
@php $pageTitle = 'Merge review'; @endphp
{{-- MARKER-IMPORT-MERGE --}}

@section('content')
@include('tenant.imports._styles')

@php $c = $analysis['counts']; @endphp

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">Merge review</h1>
    <p class="ia-page-subtitle">Your file disagrees with records you already have. Decide it once per
      field — nothing has been written yet.</p>
  </div>
</div>

<div class="imp-tiles">
  <div class="imp-tile"><div class="k">Rows matched</div><div class="v acc">{{ number_format($c['matched']) }}</div></div>
  <div class="imp-tile"><div class="k">Fields in conflict</div><div class="v acc">{{ count($analysis['fields']) }}</div></div>
  <div class="imp-tile"><div class="k">Identical, no change</div><div class="v dim">{{ number_format($c['identical']) }}</div></div>
  <div class="imp-tile"><div class="k">New records</div><div class="v ok">{{ number_format($c['new']) }}</div></div>
</div>

<form method="POST" action="{{ route('tenant.imports.conflicts.save', $import->id) }}">
  @csrf
  <div class="ia-card">
    <div class="ia-card-head">
      <span class="ia-card-title">Conflicts by field</span>
      <span style="margin-left:auto;font-size:11.5px;color:var(--ia-text-dim)">pick a winner per field</span>
    </div>

    @foreach($analysis['fields'] as $field => $f)
      @php
        $dir = null;
        foreach ((array) $import->mapping as $m) {
            if ((is_array($m) ? ($m['field'] ?? null) : $m) === $field) {
                $dir = is_array($m) ? ($m['dir'] ?? null) : null;
            }
        }
        $dir = $dir ?: (($import->options ?? [])['direction'] ?? 'csv');
        $ovCount = count((array) (($import->row_overrides ?? [])[$field] ?? []));
      @endphp
      <div class="imp-fg">
        <div class="imp-fg-top">
          <span class="imp-fg-name">{{ $f['label'] }}</span>
          <span class="imp-fg-count">{{ number_format($f['count']) }} {{ Str::plural('row', $f['count']) }} differ</span>
          @if($ovCount)
            <span class="chip chip--update">{{ $ovCount }} row {{ Str::plural('override', $ovCount) }}</span>
          @endif
          <span class="imp-seg">
            @foreach(['csv' => 'Use CSV', 'keep' => 'Keep mine', 'blank' => 'Only fill blanks'] as $val => $text)
              <label><input type="radio" name="dir[{{ $field }}]" value="{{ $val }}"
                     @checked($dir === $val)>{{ $text }}</label>
            @endforeach
          </span>
        </div>

        @if($f['samples'])
          <div class="imp-fg-sample">
            <table class="imp">
              <thead><tr>
                <th style="width:60px">Line</th><th style="width:200px">Record</th>
                <th>In Intake</th><th>In your file</th><th>Result</th>
              </tr></thead>
              <tbody>
                @foreach($f['samples'] as $s)
                  @php
                    $cur = $importer->displayValue($field, $s['current']);
                    $inc = $importer->displayValue($field, $s['incoming']);
                    $wins = $dir === 'csv' || ($dir === 'blank' && $s['blank']);
                  @endphp
                  <tr>
                    <td class="mono">{{ $s['line'] }}</td>
                    <td>{{ $s['record'] }}</td>
                    <td class="{{ $wins ? 'imp-was' : 'mono' }}">{{ $cur }}</td>
                    <td class="{{ $wins ? 'mono' : 'imp-was' }}">{{ $inc }}</td>
                    <td class="{{ $wins ? 'imp-now' : 'imp-kept' }}">{{ $wins ? $inc : $cur }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
          <div class="imp-more">
            Showing {{ count($f['samples']) }} of {{ number_format($f['count']) }} ·
            <a href="{{ route('tenant.imports.conflict.field', [$import->id, $field]) }}">see all / override individual rows &rarr;</a>
          </div>
        @endif
      </div>
    @endforeach

    <div class="imp-legend">
      <b style="color:var(--ia-text)">What this screen does and doesn't cover.</b>
      Only fields where your file and an existing record actually hold different values appear above —
      {{ number_format($c['new']) }} new {{ Str::plural('record', $c['new']) }} and
      {{ number_format($c['identical']) }} already-identical {{ Str::plural('row', $c['identical']) }}
      are not decisions and aren't listed. A blank incoming value never overwrites, whatever you choose
      here. The match key is never rewritten, and rows that can't be imported at all are errors rather
      than conflicts — those show on the next screen.
    </div>
  </div>

  <div class="imp-foot">
    <a class="ia-btn" href="{{ route('tenant.imports.map', $import->id) }}">&larr; Back to mapping</a>
    <span style="font-size:12px;color:var(--ia-text-dim);align-self:center">
      {{ count($analysis['fields']) }} {{ Str::plural('field', count($analysis['fields'])) }} to decide ·
      {{ $overrideCount }} individual row {{ Str::plural('override', $overrideCount) }}
    </span>
    <button type="submit" class="ia-btn ia-btn--primary">Continue to preview &rarr;</button>
  </div>
</form>
@endsection
