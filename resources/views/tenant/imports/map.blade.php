@extends('layouts.tenant.app')
@php $pageTitle = 'Map fields'; @endphp
{{-- MARKER-IMPORT1 --}}

@section('content')
@include('tenant.imports._styles')

@if(session('error'))<div class="ia-flash ia-flash--error" style="margin-bottom:14px">{{ session('error') }}</div>@endif

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">Map your columns</h1>
    <p class="ia-page-subtitle mono">{{ $import->original_filename }} ·
      {{ number_format($stats['rows']) }} rows · {{ count($preview['header']) }} columns</p>
  </div>
</div>

@if($stats['ragged'] > 0)
  <div class="ia-flash ia-flash--error" style="margin-bottom:14px">
    {{ $stats['ragged'] }} {{ Str::plural('row', $stats['ragged']) }} have a different number of columns
    than the header. They'll still be read, but check them in the preview.
  </div>
@endif

<div class="ia-flash ia-flash--info" style="margin-bottom:14px">
  <b>Email is required</b> — it's how an existing customer is recognised. Anything you leave unmapped is ignored.
</div>

<form method="POST" action="{{ route('tenant.imports.mapping', $import->id) }}">
  @csrf

  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">{{ count($preview['header']) }} columns</span></div>
    <div class="imp-scroll">
      <table class="imp">
        <thead><tr>
          <th style="width:170px">Your column</th>
          <th style="width:210px">Sample</th>
          <th style="width:230px">Intake field</th>
          <th style="width:200px">When it already has a value</th>
        </tr></thead>
        <tbody>
          @foreach($preview['header'] as $i => $head)
            @php
              $chosen = $mapping[$i]['field'] ?? null;
              $dir    = $mapping[$i]['dir'] ?? '';
              $sample = $preview['sample'][0][$i] ?? '';
            @endphp
            <tr>
              <td class="mono">{{ $head !== '' ? $head : 'Column ' . ($i + 1) }}</td>
              <td><span class="imp-sample">{{ Str::limit((string) $sample, 40) }}</span></td>
              <td>
                <select name="field[{{ $i }}]" class="imp-sel">
                  <option value="">— ignore this column —</option>
                  @foreach($fields as $key => $def)
                    <option value="{{ $key }}" @selected($chosen === $key)>{{ $def['label'] }}</option>
                  @endforeach
                </select>
              </td>
              <td>
                <select name="dir[{{ $i }}]" class="imp-dir">
                  <option value="" @selected($dir === '')>Use the default</option>
                  <option value="csv" @selected($dir === 'csv')>File wins</option>
                  <option value="keep" @selected($dir === 'keep')>Keep existing</option>
                  <option value="blank" @selected($dir === 'blank')>Only fill blanks</option>
                </select>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>

  <div class="imp-two">
    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Existing customers</span></div>
      <div class="ia-card-body">
        <label class="imp-radio"><input type="radio" name="mode" value="upsert" checked>
          <span><b>Add and update</b><span>New emails are created; ones you already have are merged.</span></span></label>
        <label class="imp-radio"><input type="radio" name="mode" value="insert">
          <span><b>Add only</b><span>Existing customers are left alone and reported as skipped.</span></span></label>
        <label class="imp-radio"><input type="radio" name="mode" value="update">
          <span><b>Update only</b><span>Nothing new is created. Rows with no match are listed, not dropped.</span></span></label>
      </div>
    </div>

    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Default merge direction</span></div>
      <div class="ia-card-body">
        <label class="imp-radio"><input type="radio" name="direction" value="csv" checked>
          <span><b>File wins</b><span>Your spreadsheet is the source of truth for every mapped field.</span></span></label>
        <label class="imp-radio"><input type="radio" name="direction" value="blank">
          <span><b>Only fill blanks</b><span>Adds what's missing, never overwrites what someone typed.</span></span></label>
        <label class="imp-radio"><input type="radio" name="direction" value="keep">
          <span><b>Keep existing</b><span>Reference only — useful for a dry comparison.</span></span></label>
        <p class="imp-hint" style="margin-top:8px">Any column above can override this for itself.</p>
      </div>
    </div>
  </div>

  <div class="imp-foot">
    <a href="{{ route('tenant.imports.index') }}" class="ia-btn ia-btn--secondary">Cancel</a>
    <button type="submit" class="ia-btn ia-btn--primary">Check the file</button>
  </div>
</form>
@endsection
