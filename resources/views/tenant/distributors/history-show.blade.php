{{-- MARKER-CATALOG-UNDO — inside one batch. --}}
@extends('layouts.tenant')
@section('title', 'Catalog change')

@section('content')
<div class="ia-page">
  <div class="ia-page-head">
    <h1 class="ia-page-title">{{ $batch->label() }}</h1>
    <a href="{{ route('tenant.distributors.attention.history') }}" class="ia-back-link">← Catalog changes</a>
  </div>

  <p class="ch-hint">
    {{ number_format($batch->item_count) }} {{ \Illuminate\Support\Str::plural('item', $batch->item_count) }}
    changed {{ $batch->created_at->diffForHumans() }}@if($batch->run_by) by {{ $batch->run_by }}@endif.
    @if($rows->count() < $batch->item_count)
      Showing the first {{ number_format($rows->count()) }}.
    @endif
  </p>

  <div class="ch-rows">
    @foreach($rows as $row)
      @php
        $item = $items[$row->item_id] ?? null;
        $edited = $item ? $row->changedSince($item) : false;
      @endphp
      <div class="ch-row">
        <div class="ch-row-name">
          {{ $item?->name ?? 'deleted item' }}
          <div class="ch-row-meta">{{ $item?->sku }}</div>
        </div>

        <div class="ch-row-diff">
          @foreach(($row->before ?? []) as $field => $was)
            <div class="ch-field">
              <span class="ch-field-k">{{ str_replace('_', ' ', $field) }}</span>
              <span class="ch-was">{{ is_scalar($was) ? $was : json_encode($was) }}</span>
              <span class="ch-now">{{ $item?->{$field} }}</span>
            </div>
          @endforeach
        </div>

        <div class="ch-row-act">
          @if($row->restored_at)
            <span class="ch-note">put back</span>
          @elseif($edited)
            <span class="ch-note ch-kept">edited since — kept</span>
          @elseif($item)
            <form method="POST" action="{{ route('tenant.distributors.attention.history.undo.item', [$batch->id, $row->item_id]) }}">
              @csrf
              <button type="submit" class="ia-btn ia-btn--secondary ia-btn--sm">Put back</button>
            </form>
          @endif
        </div>
      </div>
    @endforeach
  </div>
</div>

@push('styles')
<style>
  .ch-hint{font-size:13px;color:var(--ia-text-dim);line-height:1.6;max-width:74ch;margin-bottom:16px}
  .ch-row{display:grid;grid-template-columns:230px 1fr 120px;gap:14px;padding:12px 0;
    border-top:.5px solid var(--ia-border);align-items:start}
  .ch-row-name{font-size:13.5px;font-weight:600;line-height:1.35}
  .ch-row-meta{font-size:11.5px;color:var(--ia-text-dim);font-weight:400;margin-top:2px}
  .ch-field{font-size:12.5px;line-height:1.5;margin-bottom:5px}
  .ch-field-k{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-dim)}
  .ch-was{color:var(--ia-text-dim);text-decoration:line-through;display:block}
  .ch-now{display:block;word-break:break-word}
  .ch-note{font-size:12px;color:var(--ia-text-dim)}
  .ch-kept{color:var(--ia-warn,#F0C46A)}
  @media(max-width:720px){
    .ch-row{grid-template-columns:1fr;gap:8px}
    .ch-row-act .ia-btn{width:100%}
  }
</style>
@endpush
@endsection
