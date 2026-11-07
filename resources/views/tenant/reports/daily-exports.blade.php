@extends('layouts.tenant.app')

{{-- Reports → Daily ops → Bookkeeping exports. --}}

@section('title', 'Reports · Exports')

@push('styles')
@include('tenant.reports._tab_styles')
<link rel="stylesheet" href="{{ asset('css/tenant/report-builder.css') }}?v={{ filemtime(public_path('css/tenant/report-builder.css')) }}">
@endpush

@section('content')
@php
  $qs = '?from=' . tlocal_carbon($from)->toDateString() . '&to=' . tlocal_carbon($to)->toDateString();
  $exports = [
    ['QuickBooks journal', 'One balanced journal entry per day: each payment method into its deposit account, sales income, tax payable, tips payable. Import via QBO journal import. Account names follow your payment-method mapping once set.', 'CSV · QBO journal', route('tenant.reports.daily.export.qb')],
    ['Sales & payments detail', 'Every payment with its kind, method, amount, sale totals, tax, tip, customer and reference. The everything file for your accountant, Wave or Excel.', 'CSV · generic', route('tenant.reports.daily.export.detail')],
    ['Tax summary', 'Collected tax by day with gross and taxable amounts, shaped for the WA excise return and usable for any jurisdiction.', 'CSV · tax', route('tenant.reports.daily.export.tax')],
    ['Xero bank statement', 'Payout-level lines matching your bank feed for one-click reconciliation in Xero. Built from cached payouts, so fetch the weeks you need on the Reconciliation tab first.', 'CSV · Xero statement', route('tenant.reports.daily.export.xero')],
  ];
@endphp
<div class="ia-section ia-section--wide">
@include('tenant.reports._daily_head', ['dailyActive' => 'exports'])

<form method="GET" action="{{ route('tenant.reports.daily.exports') }}" class="rb-bar">
  <span class="rb-hint" style="font-weight:600;text-transform:uppercase;letter-spacing:.07em">Range</span>
  <input class="rb-inp" style="width:auto" type="date" name="from" value="{{ tlocal_carbon($from)->toDateString() }}" aria-label="From" required>
  <span class="rb-hint">to</span>
  <input class="rb-inp" style="width:auto" type="date" name="to" value="{{ tlocal_carbon($to)->toDateString() }}" aria-label="To" required>
  <button class="ia-btn ia-btn--sm" type="submit">Set range</button>
  <span class="sp"></span>
  <span class="rb-hint">Defaults to this month. Dates are your local days.</span>
</form>

<div class="rb-sec">
  <div class="rb-h"><h2>Exports</h2><span class="rb-hint">each file covers the range above</span></div>
  <div class="rb-tbl"><table class="ia-table rx-tbl">
    <thead><tr><th>Export</th><th>What it contains</th><th>Format</th><th></th></tr></thead>
    <tbody>
      @foreach($exports as [$t, $d, $f, $url])
        <tr>
          <td style="white-space:nowrap"><b>{{ $t }}</b></td>
          <td class="sub" style="white-space:normal;font-size:12.5px;line-height:1.5;max-width:560px">{{ $d }}</td>
          <td style="white-space:nowrap"><span class="rb-pill mute">{{ $f }}</span></td>
          <td class="num"><a class="ia-btn ia-btn--sm ia-btn--primary" href="{{ $url . $qs }}">Generate export</a></td>
        </tr>
      @endforeach
    </tbody>
  </table></div>
</div>

<div class="rb-legend">
  <b>How this behaves.</b>
  <div class="row"><span>Exports</span><span>Download a CSV of your records. Nothing is sent anywhere and nothing in your shop changes.</span></div>
  <div class="row"><span>Open days</span><span>Days that aren't closed yet are included with their live numbers, so close the day first if you need a locked figure.</span></div>
</div>
</div>
@endsection
