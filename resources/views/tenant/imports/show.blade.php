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
  <div class="ia-flash ia-flash--error">{{ $import->failure_reason }}</div>
@elseif($import->status === 'done')
  <div class="ia-flash ia-flash--success">
    {{ number_format($import->total('created') + $import->total('updated')) }} rows imported.
  </div>
@endif

<div class="imp-tiles">
  <div class="imp-tile"><div class="k">Created</div><div class="v ok">{{ number_format($import->total('created')) }}</div></div>
  <div class="imp-tile"><div class="k">Updated</div><div class="v acc">{{ number_format($import->total('updated')) }}</div></div>
  <div class="imp-tile"><div class="k">Already matched</div><div class="v dim">{{ number_format($import->total('unchanged')) }}</div></div>
  <div class="imp-tile"><div class="k">Skipped</div><div class="v dim">{{ number_format($import->total('skipped')) }}</div></div>
  <div class="imp-tile"><div class="k">No match</div><div class="v dim">{{ number_format($import->total('unmatched')) }}</div></div>
  <div class="imp-tile"><div class="k">Errors</div><div class="v bad">{{ number_format($import->total('errors')) }}</div></div>
</div>

@if($import->error_path)
  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">Skipped rows</span>
      <a href="{{ route('tenant.imports.errors', $import->id) }}"
         class="ia-btn ia-btn--secondary ia-btn--sm" style="margin-left:auto">Download as CSV</a></div>
    <div class="ia-card-body imp-hint">
      Keeps your original columns and adds a reason column, so you can fix it in the spreadsheet
      and import that file straight back.
    </div>
  </div>
@endif
@endsection
