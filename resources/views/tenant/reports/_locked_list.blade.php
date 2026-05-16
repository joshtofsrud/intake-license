{{--
  Locked-list partial.
  Renders a blurred placeholder table for the Customers tab's three lists
  when extended_reports is not enabled. Real aggregates already show above
  in the rep-stat-strip; this partial replaces only the row list.

  Usage:
    @include('tenant.reports._locked_list', ['kind' => 'missing|lapsed|ltv'])

  The placeholder rows are deliberately generic and unrealistic — DevTools
  reveals only these fakes, never real customer data. The blur is visual
  presentation only; the real data was never sent from the server (the
  controller passes the aggregatesOnly flag to the service).
--}}

@php
  // Generic, obviously-fake placeholder rows. Same data regardless of
  // tenant — the point isn't to deceive, it's to give the blur something
  // to blur so the panel doesn't look broken.
  $placeholders = [
    ['name' => 'Alex Morgan',   'detail' => 'alex@example.com',   'col3' => 'Apr 2024', 'col4' => '$2,450.00'],
    ['name' => 'Jordan Lee',    'detail' => 'jordan@example.com', 'col3' => 'Mar 2024', 'col4' => '$1,890.00'],
    ['name' => 'Sam Patel',     'detail' => 'sam@example.com',    'col3' => 'Feb 2024', 'col4' => '$1,640.00'],
    ['name' => 'Riley Chen',    'detail' => 'riley@example.com',  'col3' => 'Jan 2024', 'col4' => '$1,420.00'],
    ['name' => 'Casey Nguyen',  'detail' => 'casey@example.com',  'col3' => 'Dec 2023', 'col4' => '$1,275.00'],
    ['name' => 'Taylor Brooks', 'detail' => 'taylor@example.com', 'col3' => 'Nov 2023', 'col4' => '$1,140.00'],
  ];
@endphp

<div class="rep-locked-list">
  <table class="rep-tbl" aria-hidden="true">
    <thead>
      <tr>
        <th>Customer</th>
        <th>{{ $kind === 'missing' ? 'Email' : ($kind === 'lapsed' ? 'Contact' : 'Customer') }}</th>
        <th class="right">{{ $kind === 'ltv' ? 'Lifetime value' : ($kind === 'lapsed' ? 'Last visit' : 'Added') }}</th>
      </tr>
    </thead>
    <tbody>
      @foreach($placeholders as $row)
        <tr>
          <td>
            <div class="rep-cell-name">{{ $row['name'] }}</div>
            <div class="rep-cell-meta">———</div>
          </td>
          <td>{{ $row['detail'] }}</td>
          <td class="right">{{ $kind === 'ltv' ? $row['col4'] : $row['col3'] }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <div class="rep-locked-overlay">
    <div class="rep-locked-badge">
      <span class="lock-icon">🔒</span>
      <span><span class="lime">Branded feature</span> — see your real customers</span>
      <button type="button" onclick="document.getElementById('rep-upsell-backdrop').classList.add('open')">Upgrade</button>
    </div>
  </div>
</div>
