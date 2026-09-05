@extends('layouts.tenant.app')
@php $pageTitle = 'Preview import'; @endphp
{{-- MARKER-IMPORT1 --}}

@section('content')
@include('tenant.imports._styles')

@php
  $c = $result['counts'];
  // MARKER-PREVIEW-TAGS — tagging is work. Without this, a file whose rows all
  // already match produces a disabled button reading "Import 0 rows", even
  // though every one of those rows is about to be tagged.
  $tagName  = $result['tag_name'] ?? null;
  $willTag  = $tagName ? ($c['will_tag'] ?? 0) : 0;
  $rowWrites = ($c['create'] ?? 0) + ($c['update'] ?? 0);
  $writes   = $rowWrites + $willTag;

  $cta = $rowWrites > 0 && $willTag > 0
      ? 'Import ' . number_format($rowWrites) . ' ' . Str::plural('row', $rowWrites) . ' · tag ' . number_format($willTag)
      : ($rowWrites > 0
          ? 'Import ' . number_format($rowWrites) . ' ' . Str::plural('row', $rowWrites)
          : ($willTag > 0
              ? 'Tag ' . number_format($willTag) . ' ' . Str::plural('customer', $willTag)
              : 'Import 0 rows'));
@endphp

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
  {{-- MARKER-PREVIEW-TAGS --}}
  @if($tagName)
    <div class="imp-tile"><div class="k">Will be tagged</div><div class="v acc">{{ number_format($willTag) }}</div></div>
  @endif
</div>

@if($tagName)
  <div class="ia-flash ia-flash--info" style="margin-bottom:14px">
    {{ number_format($willTag) }} {{ Str::plural('customer', $willTag) }} will be tagged <b>{{ $tagName }}</b>.
    @if($willTag === 0)
      Nothing matches the tag setting you chose — check the Tag panel on the mapping step.
    @elseif(($c['unchanged'] ?? 0) > 0 && $willTag >= ($c['unchanged'] ?? 0))
      That includes {{ number_format($c['unchanged']) }} who already match this file exactly — they change nothing, but they still get the tag.
    @endif
  </div>
@endif

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
  {{-- MARKER-IMPORT-CTA — a dead button with no reason reads as a broken page. --}}
  @if($writes === 0)
    <span style="font-size:12px;color:var(--ia-text-dim);align-self:center;text-align:center">
      @if(($c['error'] ?? 0) > 0)
        Nothing can be written yet — every row has an error. Fix the file and upload it again.
      @elseif(($c['unchanged'] ?? 0) > 0)
        {{-- MARKER-PREVIEW-TAGS — name the way out, since this is the exact
             spot where tagging an already-imported list dead-ends. --}}
        Every row already matches what's in Intake, and no tag is set. To tag this list,
        set a tag on the mapping step and choose <b>Everyone in this file</b>.
      @else
        Nothing to write — every row already matches what's in Intake.
      @endif
    </span>
  @endif
  <button type="submit" class="ia-btn ia-btn--primary" @disabled($writes === 0)>
    {{ $cta }}
  </button>
</form>
@endsection
