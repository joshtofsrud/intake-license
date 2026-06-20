{{-- MARKER-PATCH-110-STEP-8 / MARKER-PATCH-350 - Launcher nav grid.
     12 tiles. Sub-stats from zoneLauncher where available; static labels
     where not. Nudge badges surface counts that need attention.
     Icons are a consistent monochrome SVG set (patch-350) — replaces the
     mixed emoji that clashed with the dark UI. --}}

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
      <div class="ic"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="3.5" width="15" height="14" rx="2"/><path d="M2.5 7.5h15M6.5 2v3M13.5 2v3"/></svg></div>
      <div class="body">
        <div class="name">Calendar</div>
        <div class="sub">{{ $L['calendar']['today_count'] ?? 0 }} booked today</div>
      </div>
    </a>

    <a href="{{ route('tenant.register.index') }}" class="ia-dash-launcher-tile">
      <div class="ic"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 2.5h10v15l-2-1.3-2 1.3-2-1.3-2 1.3V2.5z"/><path d="M8 7h4M8 10h4"/></svg></div>
      <div class="body">
        <div class="name">Register</div>
        <div class="sub">{{ $registerTodayDisplay }} rung up today</div>
      </div>
    </a>

    <a href="{{ route('tenant.customers.index') }}" class="ia-dash-launcher-tile">
      <div class="ic"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="7" r="2.7"/><path d="M3 16.5c0-2.6 2.2-4.3 5-4.3s5 1.7 5 4.3"/><path d="M14 8.2a2.3 2.3 0 0 0 0-2.4"/></svg></div>
      <div class="body">
        <div class="name">Customers</div>
        <div class="sub">{{ number_format($L['customers']['count'] ?? 0) }} in database</div>
      </div>
    </a>

    <a href="{{ route('tenant.waitlist.index') }}" class="ia-dash-launcher-tile">
      <div class="ic"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5.5 3h9M5.5 17h9M6.5 3c0 3 7 4 7 7s-7 4-7 7M13.5 3c0 3-7 4-7 7s7 4 7 7"/></svg></div>
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
      <div class="ic"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M10 2.5l7 3.8v7.4l-7 3.8-7-3.8V6.3l7-3.8z"/><path d="M3 6.3l7 3.8 7-3.8M10 10.1V18"/></svg></div>
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
      <div class="ic"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 5h8v8h-8zM10.5 8h3.5l2.5 2.5V13h-6z"/><circle cx="6" cy="15" r="1.5"/><circle cx="14" cy="15" r="1.5"/></svg></div>
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
      <div class="ic"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17h14"/><path d="M6 17v-5M10 17V6M14 17v-8"/></svg></div>
      <div class="body">
        <div class="name">Reports</div>
        <div class="sub">Operations · Customers · Services · Retail · Money · Staff</div>
      </div>
    </a>

    <a href="{{ route('tenant.appointments.index') }}" class="ia-dash-launcher-tile">
      <div class="ic"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="3.5" width="11" height="14" rx="2"/><rect x="7" y="2.2" width="6" height="3" rx="1"/><path d="M7.5 9.5h5M7.5 12.5h3"/></svg></div>
      <div class="body">
        <div class="name">Appointments</div>
        <div class="sub">All bookings · open work</div>
      </div>
    </a>

    <a href="{{ route('tenant.services.index') }}" class="ia-dash-launcher-tile">
      <div class="ic"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12.6 3.4a3.2 3.2 0 0 0-4 4.1l-4.7 4.7a1.6 1.6 0 0 0 2.2 2.2l4.7-4.7a3.2 3.2 0 0 0 4.1-4l-1.9 1.9-1.6-.4-.4-1.6 1.9-1.9z"/></svg></div>
      <div class="body">
        <div class="name">Services</div>
        <div class="sub">{{ $L['services']['count'] ?? 0 }} active {{ Str::plural('item', $L['services']['count'] ?? 0) }}</div>
      </div>
    </a>

    <a href="{{ route('tenant.resources.index') }}" class="ia-dash-launcher-tile">
      <div class="ic"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="6.5" r="3"/><path d="M4 17c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/></svg></div>
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
      <div class="ic"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 2.5h6l4 4v11H5z"/><path d="M11 2.5v4h4M7.5 11h5M7.5 14h3"/></svg></div>
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
      <div class="ic"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="10" r="2.6"/><path d="M10 1.5v2M10 16.5v2M1.5 10h2M16.5 10h2M4.6 4.6 6 6M14 14l1.4 1.4M15.4 4.6 14 6M6 14l-1.4 1.4"/></svg></div>
      <div class="body">
        <div class="name">Settings</div>
        <div class="sub">Shop · branding · billing</div>
      </div>
    </a>

  </div>
</div>
