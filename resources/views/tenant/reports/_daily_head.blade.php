{{-- Shared head for Reports → Daily ops (End of day, Reconciliation, Bookkeeping exports). Same page head and tab bar as every other Reports tab. --}}
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Reports</h1>
    <p class="ia-page-subtitle">How your business is performing.</p>
  </div>
</div>

@include('tenant.reports._tab_subnav', ['active' => 'daily'])

<div class="rb-bar">
  <div class="rb-seg" role="tablist" aria-label="Daily ops">
    <a class="rb-segbtn {{ $dailyActive === 'eod' ? 'on' : '' }}" href="{{ route('tenant.reports.daily') }}">End of day</a>
    <a class="rb-segbtn {{ $dailyActive === 'recon' ? 'on' : '' }}" href="{{ route('tenant.reports.daily.recon') }}">Reconciliation</a>
    <a class="rb-segbtn {{ $dailyActive === 'exports' ? 'on' : '' }}" href="{{ route('tenant.reports.daily.exports') }}">Bookkeeping exports</a>
  </div>
</div>
