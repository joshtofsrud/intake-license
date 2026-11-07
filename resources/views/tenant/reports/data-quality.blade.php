@extends('layouts.tenant.app')

{{-- Reports → Data quality. --}}

@section('title', 'Reports · Data quality')

@push('styles')
@include('tenant.reports._tab_styles')
<link rel="stylesheet" href="{{ asset('css/tenant/report-builder.css') }}?v={{ filemtime(public_path('css/tenant/report-builder.css')) }}">
@endpush

@section('content')
<div class="ia-section ia-section--wide">

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Reports</h1>
    <p class="ia-page-subtitle">What's missing from your records, and what it stops you doing.</p>
  </div>
</div>

@include('tenant.reports._tab_subnav', ['active' => 'data'])

{{-- Consent first: the most common "why did nothing send" answer --}}
<div class="rb-sec">
  <div class="rb-h"><h2>Marketing permission</h2><span class="rb-hint">who campaigns can reach</span></div>
  <div class="rb-note"><div>Campaigns only reach the first group. Imported contacts start unconfirmed, since permission has to be evidenced rather than assumed. Receipts and booking confirmations are unaffected by all of this.</div></div>
  <div class="rb-strip">
    <div class="rb-fig"><div class="l">Can receive campaigns</div><div class="v">{{ number_format($consent['mailable']) }}</div><div class="d good">&nbsp;</div></div>
    <div class="rb-fig"><div class="l">Unconfirmed</div><div class="v">{{ number_format($consent['unconfirmed']) }}</div><div class="d {{ $consent['unconfirmed'] > 0 ? 'warn' : '' }}">&nbsp;</div></div>
    <div class="rb-fig"><div class="l">Unsubscribed</div><div class="v">{{ number_format($consent['unsubscribed']) }}</div><div class="d">&nbsp;</div></div>
  </div>
  @if($consent['unconfirmed'] > 0)
    <div class="rx-line"><a href="{{ route('tenant.consent.index') }}">Confirm permission on Contacts &amp; consent</a> if your business has it for these contacts.</div>
  @endif
</div>

@foreach([['customers', 'Customers', $customers], ['inventory', 'Inventory', $inventory]] as [$type, $title, $data])
<div class="rb-sec">
  <div class="rb-h"><h2>{{ $title }}</h2><span class="rb-hint">{{ number_format($data['total']) }} record{{ $data['total'] === 1 ? '' : 's' }}. A blank counts as missing; a zero doesn't, because a $0 price is a decision, not a gap.</span></div>
  @if($data['total'] === 0)
    <div class="rb-note"><div>Nothing here yet.</div></div>
  @else
    <div class="rb-tbl"><table class="ia-table rx-tbl">
      <thead><tr><th>Field</th><th style="width:34%">Filled in</th><th class="num">Missing</th><th></th></tr></thead>
      <tbody>
        @foreach($data['fields'] as $f)
          @php $crit = $f['critical'] && $f['missing'] > 0; @endphp
          <tr>
            <td style="white-space:normal">
              {{ $f['label'] }}@if($crit) <span class="rb-pill bad" style="margin-left:6px">Needed</span>@endif
              @if($f['consequence'] && $f['missing'] > 0)<div class="rb-hint" style="margin-top:3px;font-weight:400">{{ $f['consequence'] }}</div>@endif
            </td>
            <td>
              <div class="rb-meter {{ $crit ? 'crit' : '' }}"><span style="width:{{ round($f['present'] / $data['total'] * 100) }}%"></span></div>
              <div class="rb-hint" style="margin-top:4px">{{ number_format($f['present']) }} of {{ number_format($data['total']) }}</div>
            </td>
            <td class="num">@if($f['missing'] > 0){{ number_format($f['missing']) }} <span class="rb-hint">({{ $f['percent'] }}%)</span>@else<span class="rb-hint">None</span>@endif</td>
            <td class="num" style="width:74px">@if($f['missing'] > 0)<a class="ia-btn ia-btn--sm" href="{{ route('tenant.reports.data_quality.export', ['type' => $type, 'field' => $f['field']]) }}">CSV</a>@endif</td>
          </tr>
        @endforeach
      </tbody>
    </table></div>
    <div class="rx-line">The CSV lists the affected records with their id. Fill in the blanks and bring it back through <a href="{{ route('tenant.imports.index') }}">Import</a>. Matching is by {{ $type === 'inventory' ? 'SKU' : 'email' }}, so existing records update rather than duplicate.</div>
  @endif
</div>
@endforeach

<div class="rb-legend">
  <b>How this behaves.</b>
  <div class="row"><span>Read-only</span><span>This page only counts. Nothing in your records changes until you import a corrected CSV.</span></div>
  <div class="row"><span>Needed</span><span>A field other features depend on (contact details for campaigns and reminders, for example). The bar turns red while any are missing.</span></div>
  <div class="row"><span>CSV</span><span>One file per field, listing only the records that are missing it.</span></div>
</div>
</div>
@endsection
