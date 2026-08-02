@extends('layouts.tenant.app')
@php $pageTitle = 'Link vendor'; @endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Link {{ strtoupper($code) }} to {{ $target->name }}</h1>
    <p class="ia-page-subtitle">{{ $source->name }} will be absorbed and deleted.</p>
  </div>
</div>

<div style="background:var(--ia-surface);border:.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:22px;max-width:620px">

  <p style="font-size:13.5px;line-height:1.7;margin-bottom:16px">
    <strong>{{ $target->name }}</strong> keeps its name, contact details, account
    number, free-freight minimum and program discount. Everything below moves
    onto it from <strong>{{ $source->name }}</strong>.
  </p>

  <div style="border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);overflow:hidden;margin-bottom:16px">
    @foreach ([
      ['Catalog items',   $preview['items']],
      ['Special orders',  $preview['special_orders']],
      ['Receipts',        $preview['shipments']],
      ['Default vendor on', $preview['default_for']],
    ] as $row)
      <div style="display:flex;justify-content:space-between;padding:10px 14px;border-bottom:.5px solid var(--ia-border);font-size:13px">
        <span>{{ $row[0] }}</span>
        <span style="font-variant-numeric:tabular-nums">{{ number_format($row[1]) }}</span>
      </div>
    @endforeach
  </div>

  @if ($preview['items_collide'] > 0)
    <p style="font-size:12px;color:var(--ia-text-dim);line-height:1.6;margin-bottom:14px">
      {{ number_format($preview['items_collide']) }} of those items already list
      {{ $target->name }} as a source. Those rows are kept, filling in anything
      they were missing from the {{ $source->name }} row.
    </p>
  @endif

  @if (count($preview['inherits']))
    <p style="font-size:12px;color:var(--ia-text-dim);line-height:1.6;margin-bottom:14px">
      {{ $target->name }} will inherit these blank fields from {{ $source->name }}:
      {{ implode(', ', str_replace('_', ' ', $preview['inherits'])) }}.
    </p>
  @endif

  <p style="font-size:12px;color:var(--ia-text-dim);line-height:1.6;margin-bottom:18px">
    Receiving history is unaffected — each receipt keeps the distributor name
    recorded when it was received. This can't be undone.
  </p>

  <form method="POST" action="{{ route('tenant.distributors.vendor_merge.run') }}"
        style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    @csrf
    <input type="hidden" name="code" value="{{ $code }}">
    <input type="hidden" name="source" value="{{ $source->id }}">
    <input type="hidden" name="target" value="{{ $target->id }}">
    <button class="ia-btn ia-btn--primary" type="submit">
      Merge into {{ $target->name }}
    </button>
    <a class="ia-btn" href="{{ route('tenant.distributors.connection') }}">Cancel</a>
  </form>
</div>

@endsection
