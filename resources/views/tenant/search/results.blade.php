@extends('layouts.tenant.app')
@php $pageTitle = 'Search'; @endphp

@section('content')
{{-- MARKER-SEARCH-ALL — grouped, matching the modal, so the two read the same
     way. The modal is for jumping to something you already have in mind; this
     is for when six was not enough. --}}
<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">Search</h1>
    <p class="ia-page-sub">
      @if($q !== '')
        Results for <strong>{{ $q }}</strong>
      @else
        Type something to search.
      @endif
    </p>
  </div>
</div>

<form method="get" action="{{ route('tenant.search.page') }}" style="margin-bottom:18px">
  <input type="text" name="q" value="{{ $q }}" class="ia-input"
         style="max-width:460px" placeholder="Search customers, products, sales…" autofocus>
</form>

@if($q !== '' && ! count($groups))
  <div class="ia-card"><div class="ia-card-body" style="color:var(--ia-text-muted)">
    Nothing matched <strong>{{ $q }}</strong>.
  </div></div>
@endif

@foreach($groups as $g)
  <div class="ia-card" style="margin-bottom:16px">
    <div class="ia-card-head" style="display:flex;align-items:center;gap:10px">
      <span class="ia-card-title">{{ $g['label'] }}</span>
      <span style="color:var(--ia-text-dim);font-size:12px">
        @if(($g['total'] ?? 0) > count($g['rows']))
          showing {{ count($g['rows']) }} of {{ number_format($g['total']) }}
        @else
          {{ number_format($g['total'] ?? count($g['rows'])) }}
        @endif
      </span>
    </div>
    <div>
      @foreach($g['rows'] as $r)
        <a href="{{ $r['url'] }}"
           style="display:flex;gap:12px;align-items:baseline;padding:10px 15px;
                  border-bottom:0.5px solid var(--ia-border);text-decoration:none;color:var(--ia-text)">
          <span style="flex:1;min-width:0">{{ $r['title'] }}</span>
          @if(!empty($r['subtitle']))
            <span style="color:var(--ia-text-dim);font-size:12px">{{ $r['subtitle'] }}</span>
          @endif
        </a>
      @endforeach
    </div>
    @if(($g['total'] ?? 0) > count($g['rows']))
      <div class="ia-card-body" style="font-size:12px;color:var(--ia-text-dim)">
        {{ number_format($g['total'] - count($g['rows'])) }} more not shown. Narrow the search to see them.
      </div>
    @endif
  </div>
@endforeach
@endsection
