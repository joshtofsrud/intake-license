{{-- MARKER-PATCH-110-STEP-8 - Launcher nav grid.
     12 tiles. Sub-stats from zoneLauncher where available; static labels
     where not. Nudge badges surface counts that need attention. --}}

@php
  $L = $launcher ?? [];
  $registerTodayDisplay = isset($L['register']['today_total_cents'])
      ? format_money($L['register']['today_total_cents'])
      : '$0';
@endphp

<div class="ia-dash-launcher-block">
  <div class="ia-dash-tiles-zone-label">
    <span>Jump to</span>
    <span class="hint">all sections of your shop</span>
  </div>

  <div class="ia-dash-launcher-grid">

    <a href="{{ route('tenant.calendar.index') }}" class="ia-dash-launcher-tile">
      <div class="ic">📅</div>
      <div class="body">
        <div class="name">Calendar</div>
        <div class="sub">{{ $L['calendar']['today_count'] ?? 0 }} booked today</div>
      </div>
    </a>

    <a href="{{ route('tenant.register.index') }}" class="ia-dash-launcher-tile">
      <div class="ic">🧾</div>
      <div class="body">
        <div class="name">Register</div>
        <div class="sub">{{ $registerTodayDisplay }} rung up today</div>
      </div>
    </a>

    <a href="{{ route('tenant.customers.index') }}" class="ia-dash-launcher-tile">
      <div class="ic">👥</div>
      <div class="body">
        <div class="name">Customers</div>
        <div class="sub">{{ number_format($L['customers']['count'] ?? 0) }} in database</div>
      </div>
    </a>

    <a href="{{ route('tenant.waitlist.index') }}" class="ia-dash-launcher-tile">
      <div class="ic">⏳</div>
      <div class="body">
        <div class="name">
          Waitlist
          @if(($L['waitlist']['count'] ?? 0) > 0)
            <span class="nudge nudge--blue">{{ $L['waitlist']['count'] }}</span>
          @endif
        </div>
        <div class="sub">
          @if(($L['waitlist']['count'] ?? 0) === 0)
            No one waiting
          @else
            {{ $L['waitlist']['count'] }} {{ Str::plural('customer', $L['waitlist']['count']) }} waiting
          @endif
        </div>
      </div>
    </a>

    <a href="{{ route('tenant.inventory.index') }}" class="ia-dash-launcher-tile">
      <div class="ic">📦</div>
      <div class="body">
        <div class="name">
          Inventory
          @if(($L['inventory']['low_stock_count'] ?? 0) > 0)
            <span class="nudge nudge--amber">{{ $L['inventory']['low_stock_count'] }}</span>
          @endif
        </div>
        <div class="sub">
          @if(($L['inventory']['low_stock_count'] ?? 0) > 0)
            {{ $L['inventory']['low_stock_count'] }} low ·
          @endif
          {{ number_format($L['inventory']['active_count'] ?? 0) }} active items
        </div>
      </div>
    </a>

    <a href="{{ route('tenant.special-orders.index') }}" class="ia-dash-launcher-tile">
      <div class="ic">🚚</div>
      <div class="body">
        <div class="name">
          Special orders
          @if(($L['special_orders']['arrived_count'] ?? 0) > 0)
            <span class="nudge nudge--amber">{{ $L['special_orders']['arrived_count'] }}</span>
          @endif
        </div>
        <div class="sub">
          @if(($L['special_orders']['arrived_count'] ?? 0) > 0 || ($L['special_orders']['overdue_count'] ?? 0) > 0)
            {{ $L['special_orders']['arrived_count'] ?? 0 }} on bench ·
            {{ $L['special_orders']['overdue_count'] ?? 0 }} overdue
          @else
            All current
          @endif
        </div>
      </div>
    </a>

    <a href="{{ route('tenant.reports.index') }}" class="ia-dash-launcher-tile">
      <div class="ic">📊</div>
      <div class="body">
        <div class="name">Reports</div>
        <div class="sub">Operations · Customers · Services · Retail · Money · Staff</div>
      </div>
    </a>

    <a href="{{ route('tenant.appointments.index') }}" class="ia-dash-launcher-tile">
      <div class="ic">📋</div>
      <div class="body">
        <div class="name">Appointments</div>
        <div class="sub">All bookings · open work</div>
      </div>
    </a>

    <a href="{{ route('tenant.services.index') }}" class="ia-dash-launcher-tile">
      <div class="ic">🛠️</div>
      <div class="body">
        <div class="name">Services</div>
        <div class="sub">{{ $L['services']['count'] ?? 0 }} active {{ Str::plural('item', $L['services']['count'] ?? 0) }}</div>
      </div>
    </a>

    <a href="{{ route('tenant.resources.index') }}" class="ia-dash-launcher-tile">
      <div class="ic">👤</div>
      <div class="body">
        <div class="name">Resources</div>
        <div class="sub">
          {{ $L['resources']['count'] ?? 0 }} {{ Str::plural('station', $L['resources']['count'] ?? 0) }}
          @if(($L['resources']['staff_count'] ?? 0) > 0)
            · {{ $L['resources']['staff_count'] }} staff
          @endif
        </div>
      </div>
    </a>

    <a href="{{ route('tenant.pages.index') }}" class="ia-dash-launcher-tile">
      <div class="ic">📝</div>
      <div class="body">
        <div class="name">Pages</div>
        <div class="sub">
          @if(($L['pages']['published_count'] ?? 0) === 0)
            No pages published
          @else
            {{ $L['pages']['published_count'] }} {{ Str::plural('page', $L['pages']['published_count']) }} live
          @endif
        </div>
      </div>
    </a>

    <a href="{{ route('tenant.settings.index') }}" class="ia-dash-launcher-tile">
      <div class="ic">⚙️</div>
      <div class="body">
        <div class="name">Settings</div>
        <div class="sub">Shop · branding · billing</div>
      </div>
    </a>

  </div>
</div>
