{{--
  Shared subnav partial for the Reports tabs.
  Usage:  @include('tenant.reports._tab_subnav', ['active' => 'services'])
  Active: traffic | operations | customers | services | retail | money | staff
  desktop tab row + native mobile report picker.
--}}
@php
  $repTabs = [
    ['key' => 'traffic',    'label' => 'Traffic',    'url' => route('tenant.reports.traffic')],
    ['key' => 'operations', 'label' => 'Operations', 'url' => route('tenant.reports.index')],
    ['key' => 'customers',  'label' => 'Customers',  'url' => route('tenant.reports.customers')],
    ['key' => 'services',   'label' => 'Services',   'url' => route('tenant.reports.services')],
    ['key' => 'retail',     'label' => 'Retail',     'url' => route('tenant.reports.retail')],
    ['key' => 'money',      'label' => 'Money',      'url' => route('tenant.reports.money')],
    ['key' => 'daily',      'label' => 'Daily ops',  'url' => route('tenant.reports.daily')],
    ['key' => 'staff',      'label' => 'Staff',      'url' => route('tenant.reports.staff')],
    ['key' => 'data',       'label' => 'Data quality', 'url' => route('tenant.reports.data_quality')],
    ['key' => 'custom',     'label' => 'Custom',     'url' => route('tenant.reports.custom')],
  ];
  // MARKER-REPORT-TAB-ICONS. Lucide outline icons (ISC licence), one per report type.
  $repIcons = [
    'traffic' => '<path d="M4 16v-2.38C4 11.5 2.97 10.5 3 8c.03-2.72 1.49-6 4.5-6C9.37 2 10 3.8 10 5.5c0 3.11-2 5.66-2 8.68V16a2 2 0 1 1-4 0Z" /> <path d="M20 20v-2.38c0-2.12 1.03-3.12 1-5.62-.03-2.72-1.49-6-4.5-6C14.63 6 14 7.8 14 9.5c0 3.11 2 5.66 2 8.68V20a2 2 0 1 0 4 0Z" /> <path d="M16 17h4" /> <path d="M4 13h4" />',
    'operations' => '<path d="M20 7h-9" /> <path d="M14 17H5" /> <circle cx="17" cy="17" r="3" /> <circle cx="7" cy="7" r="3" />',
    'customers' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /> <circle cx="9" cy="7" r="4" /> <path d="M22 21v-2a4 4 0 0 0-3-3.87" /> <path d="M16 3.13a4 4 0 0 1 0 7.75" />',
    'services' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" />',
    'retail' => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z" /> <path d="M3 6h18" /> <path d="M16 10a4 4 0 0 1-8 0" />',
    'money' => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1" /> <path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4" />',
    'daily' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1" /> <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" /> <path d="M12 11h4" /> <path d="M12 16h4" /> <path d="M8 11h.01" /> <path d="M8 16h.01" />',
    'staff' => '<path d="M16 10h2" /> <path d="M16 14h2" /> <path d="M6.17 15a3 3 0 0 1 5.66 0" /> <circle cx="9" cy="11" r="2" /> <rect x="2" y="5" width="20" height="14" rx="2" />',
    'data' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" /> <path d="m9 12 2 2 4-4" />',
    'custom' => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" /> <path d="M20 3v4" /> <path d="M22 5h-4" /> <path d="M4 17v2" /> <path d="M5 18H3" />',
  ];
  $repActive = $active ?? '';
@endphp

<style>
  .rep-subnav-tabs a { display: inline-flex; align-items: center; gap: 7px; }
  .rep-subnav-tabs a svg { width: 15px; height: 15px; flex: none; }
</style>

{{-- Desktop: horizontal tab row (hidden on phones via .rep-subnav-tabs) --}}
<nav class="rep-toggle rep-subnav-tabs" style="margin-bottom: 18px;">
  @foreach($repTabs as $t)
    <a href="{{ $t['url'] }}" class="{{ $repActive === $t['key'] ? 'active' : '' }}">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $repIcons[$t['key']] ?? '' !!}</svg>{{ $t['label'] }}
    </a>
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

