{{--
  Shared subnav partial for the Reports tabs.
  Usage:  @include('tenant.reports._tab_subnav', ['active' => 'services'])
  Active: traffic | operations | customers | services | retail | money | staff
  MARKER-PATCH-355 — desktop tab row + native mobile report picker.
--}}
@php
  $repTabs = [
    ['key' => 'traffic',    'label' => 'Traffic',    'url' => route('tenant.reports.traffic')],
    ['key' => 'operations', 'label' => 'Operations', 'url' => route('tenant.reports.index')],
    ['key' => 'customers',  'label' => 'Customers',  'url' => route('tenant.reports.customers')],
    ['key' => 'services',   'label' => 'Services',   'url' => route('tenant.reports.services')],
    ['key' => 'retail',     'label' => 'Retail',     'url' => route('tenant.reports.retail')],
    ['key' => 'money',      'label' => 'Money',      'url' => route('tenant.reports.money')],
    ['key' => 'daily',      'label' => 'Daily ops',  'url' => route('tenant.reports.daily')], // MARKER-PATCH-633
    ['key' => 'staff',      'label' => 'Staff',      'url' => route('tenant.reports.staff')],
    ['key' => 'data',       'label' => 'Data quality', 'url' => route('tenant.reports.data_quality')], // MARKER-DATA-COMPLETENESS
  ];
  $repActive = $active ?? '';
@endphp

{{-- Desktop: horizontal tab row (hidden on phones via .rep-subnav-tabs) --}}
<nav class="rep-toggle rep-subnav-tabs" style="margin-bottom: 18px;">
  @foreach($repTabs as $t)
    <a href="{{ $t['url'] }}" class="{{ $repActive === $t['key'] ? 'active' : '' }}">{{ $t['label'] }}</a>
  @endforeach
</nav>

{{-- Phones: native report picker (hidden on desktop via .rep-subnav-picker) --}}
<div class="rep-subnav-picker">
  <label class="rep-pick-cap" for="rep-pick">Report</label>
  <select id="rep-pick" class="rep-pick-select"
          onchange="if(this.value){window.location.href=this.value;}">
    @foreach($repTabs as $t)
      <option value="{{ $t['url'] }}" @selected($repActive === $t['key'])>{{ $t['label'] }}</option>
    @endforeach
  </select>
</div>

