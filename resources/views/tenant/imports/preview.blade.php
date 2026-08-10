@extends('layouts.tenant.app')
@php $pageTitle = 'Preview import'; @endphp
{{-- MARKER-IMPORT1 --}}

@section('content')
@include('tenant.imports._styles')

@php $c = $result['counts']; $writes = ($c['create'] ?? 0) + ($c['update'] ?? 0); @endphp

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">Preview</h1>
    <p class="ia-page-subtitle">Nothing has been written yet. This is exactly what will happen.</p>
  </div>
</div>

<div class="imp-tiles">
  <div class="imp-tile"><div class="k">Will be created</div><div class="v ok">{{ number_format($c['create'] ?? 0) }}</div></div>
  <div class="imp-tile"><div class="k">Will be updated</div><div class="v acc">{{ number_format($c['update'] ?? 0) }}</div></div>
  <div class="imp-tile"><div class="k">Already match</div><div class="v dim">{{ number_format($c['unchanged'] ?? 0) }}</div></div>
  <div class="imp-tile"><div class="k">Skipped</div><div class="v dim">{{ number_format($c['skipped'] ?? 0) }}</div></div>
  <div class="imp-tile"><div class="k">No match</div><div class="v dim">{{ number_format($c['unmatched'] ?? 0) }}</div></div>
  <div class="imp-tile"><div class="k">Errors</div><div class="v bad">{{ number_format($c['error'] ?? 0) }}</div></div>
</div>

@if(($c['error'] ?? 0) > 0)
  <div class="ia-flash ia-flash--error" style="margin-bottom:14px">
    {{ number_format($c['error']) }} {{ Str::plural('row', $c['error']) }} can't be imported and will be
    skipped. Everything else still goes in — you can download the skipped rows afterwards, fix them,
    and import that file straight back.
  </div>
@endif

<div class="ia-card">
  <div class="ia-card-head"><span class="ia-card-title">Row by row</span>
    <span style="margin-left:auto;font-size:11.5px;color:var(--ia-text-dim)">first {{ count($result['sample']) }} rows</span></div>
  <div class="imp-scroll">
    <table class="imp">
      <thead><tr><th style="width:60px">Line</th><th style="width:220px">Email</th><th>Name</th><th style="width:280px">Outcome</th></tr></thead>
      <tbody>
        @foreach($result['sample'] as $row)
          <tr>
            <td class="mono">{{ $row['line'] }}</td>
            <td class="mono">{{ $row['key'] }}</td>
            <td>{{ $row['label'] }}</td>
            <td>
              <span class="chip chip--{{ $row['outcome'] }}">{{ str_replace('_',' ', $row['outcome']) }}</span>
              @if($row['errors'])
                <div class="imp-err">{{ implode(' · ', $row['errors']) }}</div>
              @elseif($row['outcome'] === 'update' && $row['changes'])
                <span class="imp-changes">{{ implode(', ', $row['changes']) }}</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>

<form method="POST" action="{{ route('tenant.imports.run', $import->id) }}" class="imp-foot">
  @csrf
  <a href="{{ route('tenant.imports.map', $import->id) }}" class="ia-btn ia-btn--secondary">Back to mapping</a>
  <button type="submit" class="ia-btn ia-btn--primary" @disabled($writes === 0)>
    Import {{ number_format($writes) }} {{ Str::plural('row', $writes) }}
  </button>
</form>
@endsection
