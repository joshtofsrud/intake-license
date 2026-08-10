@extends('layouts.tenant.app')
@php $pageTitle = 'Import'; @endphp
{{-- MARKER-IMPORT1 --}}

@section('content')
@include('tenant.imports._styles')

@if(session('error'))<div class="ia-flash ia-flash--error" style="margin-bottom:14px">{{ session('error') }}</div>@endif
@if(session('success'))<div class="ia-flash ia-flash--success" style="margin-bottom:14px">{{ session('success') }}</div>@endif

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">Import</h1>
    <p class="ia-page-subtitle">Bring customers in from a spreadsheet.</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.imports.create') }}" class="ia-btn ia-btn--primary">+ New import</a>
  </div>
</div>

<div class="ia-card">
  <div class="ia-card-head"><span class="ia-card-title">Past imports</span></div>
  @if($imports->isEmpty())
    <div class="imp-empty">Nothing imported yet.</div>
  @else
    <table class="imp">
      <thead><tr>
        <th>When</th><th>File</th><th>Type</th><th>Created</th><th>Updated</th><th>Skipped</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($imports as $imp)
          <tr>
            <td>{{ tlocal_datetime($imp->created_at, 'M j, g:i A') }}</td>
            <td class="mono">{{ $imp->original_filename }}</td>
            <td style="text-transform:capitalize">{{ $imp->type }}</td>
            <td><b>{{ number_format($imp->total('created')) }}</b></td>
            <td>{{ number_format($imp->total('updated')) }}</td>
            <td>{{ number_format($imp->total('errors') + $imp->total('unmatched')) }}</td>
            <td><span class="chip chip--{{ $imp->status }}">{{ $imp->status }}</span></td>
            <td style="text-align:right">
              <a href="{{ route('tenant.imports.show', $imp->id) }}" class="ia-btn ia-btn--secondary ia-btn--sm">View</a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>
@endsection
